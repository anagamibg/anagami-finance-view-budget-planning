<?php

namespace App\Services\Databackd\Planning;

use App\Services\Databackd\FinancialDataService;
use Carbon\Carbon;

/**
 * Forecasting of a monthly series.
 *
 * Fixed result format of forecast():
 *   ['history' => ['2025-05' => float, ...], 'forecast' => ['2026-11' => float, ...],
 *    'lower' => ['2026-11' => float, ...], 'upper' => [...], 'method' => string, 'params' => array]
 *
 * Methods: linear (least squares), moving_average (rolling 3/6 months), seasonal (level x seasonal index of the
 * last 12 months), manual (monthly growth %). The corridor is +- the standard deviation of the in-sample
 * residuals (manual: +- corridor_pct %). Values are never clamped (profit / cash can be negative).
 */
class ForecastService
{

    public const METHODS = ['linear', 'moving_average', 'seasonal', 'manual'];

    /**
     * Forecast of a monthly series.
     *
     * @param array<string, float> $history month ('YYYY-MM') => value, ascending
     * @param string $method linear|moving_average|seasonal|manual (anything else is treated as linear)
     * @param int $horizon number of months ahead
     * @param array $params method parameters:
     *        moving_average: ['window' => 3|6] (default 3, clamped to the history length);
     *        manual: ['growth_pct' => monthly % growth (default 0), 'corridor_pct' => % (default 5)];
     *        linear / seasonal: none.
     * @return array ['history', 'forecast', 'lower', 'upper' (keys = the months after the last history month,
     *         values rounded to 2 decimals), 'method' (the method actually used), 'params']
     */
    public function forecast(array $history, string $method, int $horizon, array $params = []): array
    {
        $history = $this->normalizeHistory($history);
        $horizon = max(0, $horizon);

        if (!in_array($method, self::METHODS, true)) {
            $method = 'linear';
        }

        $keys = $this->nextMonthKeys($history, $horizon);
        $values = array_values($history);
        $months = array_keys($history);

        if (empty($values)) {
            $forecast = $spread = array_fill(0, $horizon, 0.0);
        }
        else {
            [$forecast, $spread] = match ($method) {
                'moving_average' => $this->movingAverage($values, $horizon, $params),
                'seasonal' => $this->seasonal($values, $months, $keys, $horizon),
                'manual' => $this->manual($values, $horizon, $params),
                default => $this->linear($values, $horizon),
            };
        }

        $out = ['forecast' => [], 'lower' => [], 'upper' => []];

        foreach ($keys as $i => $key) {
            $f = $this->finite($forecast[$i] ?? 0);
            $d = abs($this->finite($spread[$i] ?? 0));
            $out['forecast'][$key] = round($f, 2);
            $out['lower'][$key] = round($f - $d, 2);
            $out['upper'][$key] = round($f + $d, 2);
        }

        return [
            'history' => $history,
            'forecast' => $out['forecast'],
            'lower' => $out['lower'],
            'upper' => $out['upper'],
            'method' => $method,
            'params' => $params,
        ];
    }

    /**
     * Ordinary least squares y = a + b*t over all points (t = 0..n-1), continued for t = n..n+h-1.
     * Corridor: sample standard deviation of the residuals (n-2 degrees of freedom).
     *
     * @return array [forecast list, spread list]
     */
    protected function linear(array $values, int $horizon): array
    {
        $n = count($values);

        if ($n === 1) {
            return [array_fill(0, $horizon, $values[0]), array_fill(0, $horizon, 0.0)];
        }

        $meanT = ($n - 1) / 2;
        $meanY = array_sum($values) / $n;
        $sxy = $sxx = 0.0;

        foreach ($values as $t => $y) {
            $sxy += ($t - $meanT) * ($y - $meanY);
            $sxx += ($t - $meanT) ** 2;
        }

        $b = $sxx > 0 ? $sxy / $sxx : 0.0;
        $a = $meanY - $b * $meanT;

        $residuals = [];

        foreach ($values as $t => $y) {
            $residuals[] = $y - ($a + $b * $t);
        }

        $sd = $n > 2 ? $this->stdDev($residuals, $n - 2) : 0.0;
        $forecast = [];

        for ($i = 0; $i < $horizon; $i++) {
            $forecast[] = $a + $b * ($n + $i);
        }

        return [$forecast, array_fill(0, $horizon, $sd)];
    }

    /**
     * Rolling moving average: every next month is the mean of the previous `window` values, forecast months are
     * fed back. Corridor: standard deviation of (actual - the moving average that predicted it) in the history.
     *
     * @return array [forecast list, spread list]
     */
    protected function movingAverage(array $values, int $horizon, array $params): array
    {
        $n = count($values);
        $window = (int)($params['window'] ?? 3);
        $window = max(1, min($window > 0 ? $window : 3, $n));

        $residuals = [];

        for ($i = $window; $i < $n; $i++) {
            $residuals[] = $values[$i] - array_sum(array_slice($values, $i - $window, $window)) / $window;
        }

        $sd = count($residuals) > 1 ? $this->stdDev($residuals, count($residuals) - 1) : 0.0;
        $series = $values;
        $forecast = [];

        for ($i = 0; $i < $horizon; $i++) {
            $next = array_sum(array_slice($series, -$window)) / $window;
            $series[] = $next;
            $forecast[] = $next;
        }

        return [$forecast, array_fill(0, $horizon, $sd)];
    }

    /**
     * Seasonal: forecast = base level (mean of the last 12 months) x seasonal index of the calendar month.
     * Index = value of the month / mean of its 12-month block; with >= 24 months the index is averaged over all
     * complete 12-month blocks counted from the end. Missing months get index 1.
     * Corridor: standard deviation of (actual - level x index) over the history.
     *
     * @param string[] $months history month keys, parallel to $values
     * @param string[] $keys forecast month keys
     * @return array [forecast list, spread list]
     */
    protected function seasonal(array $values, array $months, array $keys, int $horizon): array
    {
        $n = count($values);
        $base = array_sum(array_slice($values, -min(12, $n))) / min(12, $n);
        $perMonth = [];

        if (abs($base) > 1e-9) {
            $blocks = $n >= 24 ? intdiv($n, 12) : 1;

            for ($b = 0; $b < $blocks; $b++) {
                $len = min(12, $n);
                $offset = $n - $len * ($b + 1);
                $slice = array_slice($values, $offset, $len);
                $blockMean = array_sum($slice) / count($slice);

                if (abs($blockMean) < 1e-9) {
                    continue;
                }

                foreach ($slice as $j => $v) {
                    $perMonth[(int)substr($months[$offset + $j], 5, 2)][] = $v / $blockMean;
                }
            }
        }

        $index = [];

        foreach ($perMonth as $m => $list) {
            $index[$m] = array_sum($list) / count($list);
        }

        $residuals = [];

        foreach ($values as $i => $v) {
            $residuals[] = $v - $base * ($index[(int)substr($months[$i], 5, 2)] ?? 1.0);
        }

        $sd = $n > 1 ? $this->stdDev($residuals, $n - 1) : 0.0;
        $forecast = [];

        foreach ($keys as $key) {
            $forecast[] = $base * ($index[(int)substr($key, 5, 2)] ?? 1.0);
        }

        return [$forecast, array_fill(0, $horizon, $sd)];
    }

    /**
     * Manual assumption: last value x (1 + growth_pct/100)^t, corridor +- corridor_pct % of the forecast.
     *
     * @return array [forecast list, spread list]
     */
    protected function manual(array $values, int $horizon, array $params): array
    {
        $last = end($values);
        $growth = 1 + (float)($params['growth_pct'] ?? 0) / 100;
        $corridor = max(0.0, (float)($params['corridor_pct'] ?? 5)) / 100;
        $forecast = $spread = [];

        for ($t = 1; $t <= $horizon; $t++) {
            $f = $this->finite($last * ($growth ** $t));
            $forecast[] = $f;
            $spread[] = abs($f) * $corridor;
        }

        return [$forecast, $spread];
    }

    /**
     * Standard deviation of the values around their mean, with the given degrees of freedom.
     */
    protected function stdDev(array $residuals, int $dof): float
    {
        $count = count($residuals);

        if ($count === 0 || $dof <= 0) {
            return 0.0;
        }

        $mean = array_sum($residuals) / $count;
        $sum = 0.0;

        foreach ($residuals as $r) {
            $sum += ($r - $mean) ** 2;
        }

        return $this->finite(sqrt($sum / $dof));
    }

    /**
     * Drops the leading months without data (value 0), e.g. the months before the company started recording in
     * AFV, so that they do not pull the trend / averages down. A series of only zeros is returned unchanged
     * (sorted and cleaned like in forecast()); zeros after the first month with data are kept.
     *
     * @param array<string, float> $history 'YYYY-MM' => value
     * @return array<string, float>
     */
    public function trimLeadingZeros(array $history): array
    {
        $history = $this->normalizeHistory($history);

        foreach ($history as $key => $value) {
            if (abs($value) >= 0.005) {
                return array_filter($history, fn($k) => $k >= $key, ARRAY_FILTER_USE_KEY);
            }
        }

        return $history;
    }

    /**
     * Sorts the history by month, keeps only 'YYYY-MM' keys and replaces NaN / INF with 0.
     */
    protected function normalizeHistory(array $history): array
    {
        $clean = [];

        foreach ($history as $key => $value) {
            if (preg_match('/^\d{4}-\d{2}$/', (string)$key)) {
                $clean[(string)$key] = $this->finite((float)$value);
            }
        }

        ksort($clean);

        return $clean;
    }

    /**
     * The $horizon month keys following the last history month (current month when the history is empty).
     *
     * @return string[]
     */
    protected function nextMonthKeys(array $history, int $horizon): array
    {
        $lastMonth = empty($history)
            ? Carbon::now()->startOfMonth()
            : Carbon::createFromFormat('!Y-m-d', array_key_last($history) . '-01');

        $keys = [];

        for ($i = 1; $i <= $horizon; $i++) {
            $keys[] = $lastMonth->copy()->addMonthsNoOverflow($i)->format('Y-m');
        }

        return $keys;
    }

    protected function finite(float|int $value): float
    {
        return is_finite((float)$value) ? (float)$value : 0.0;
    }

    /**
     * Forecast of the same series under several scenarios.
     *
     * @param array<string, array> $scenarioParams scenario name => ['method' => string (default linear), 'params' => array,
     *        'growth_pct' => float annual % added on top of the base forecast, ramped linearly: month i is multiplied
     *        by 1 + growth_pct/100 * i/12]. Typical keys: base, optimistic, pessimistic.
     *        The UI calls it once for the revenue series (growth = revenue growth %) and once for the expense series.
     * @return array<string, array> scenario name => forecast() result
     */
    public function scenarios(array $history, int $horizon, array $scenarioParams): array
    {
        $out = [];

        foreach ($scenarioParams as $name => $cfg) {
            $cfg = is_array($cfg) ? $cfg : [];
            $result = $this->forecast($history, $cfg['method'] ?? 'linear', $horizon, $cfg['params'] ?? []);
            $growth = (float)($cfg['growth_pct'] ?? 0);

            if ($growth != 0) {
                $i = 0;

                foreach (array_keys($result['forecast']) as $key) {
                    $i++;
                    $factor = 1 + $growth / 100 * $i / 12;

                    foreach (['forecast', 'lower', 'upper'] as $part) {
                        $result[$part][$key] = round($result[$part][$key] * $factor, 2);
                    }
                }
            }

            $result['scenario'] = $name;
            $out[$name] = $result;
        }

        return $out;
    }

    /**
     * Monthly history of a metric.
     *
     * @param string $metric revenue|expense|profit|cash|account|product_group
     * @param string|null $metricRef account code for `account`; fin_dimensions.id of the product group for `product_group` (its revenue)
     * @return array<string, float> 'YYYY-MM' => value (cash = balance of cash accounts at month end)
     */
    public function historyFor(string $metric, ?string $metricRef, ?int $entityId, Carbon $from, Carbon $to): array
    {
        $data = new FinancialDataService();
        $filters = $entityId ? ['entity_ids' => [$entityId]] : [];
        $from = $from->copy()->startOfMonth();
        $to = $to->copy()->endOfMonth();
        $out = [];

        if ($metric === 'cash') {
            for ($m = $from->copy(); $m->lte($to); $m->addMonthNoOverflow()) {
                // review A: the same cash as the dashboard and the alerts - 501-504 with analytic accounts
                $out[$m->format('Y-m')] = $data->balanceAt($m->copy()->endOfMonth(), FinancialDataService::CASH_CODES, $filters);
            }

            return $out;
        }

        if ($metric === 'account') {
            $rows = $data->query($filters)
                ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
                ->where('fin_entries.kind', 'flow')
                ->where('fin_accounts.code', (string)$metricRef)
                ->whereBetween('fin_entries.entry_date', [$from->toDateString(), $to->toDateString()])
                ->groupByRaw("DATE_FORMAT(fin_entries.entry_date, '%Y-%m')")
                ->selectRaw("DATE_FORMAT(fin_entries.entry_date, '%Y-%m') as month, SUM(fin_entries.amount_base) as total")
                ->pluck('total', 'month');

            for ($m = $from->copy(); $m->lte($to); $m->addMonthNoOverflow()) {
                $out[$m->format('Y-m')] = round((float)($rows[$m->format('Y-m')] ?? 0), 2);
            }

            return $out;
        }

        if ($metric === 'product_group') {
            $filters['product_group_id'] = (int)$metricRef;
            $metric = 'revenue';
        }

        foreach ($data->monthlySeries($from, $to, null, $filters) as $row) {
            $out[$row['month']] = (float)$row[$metric];
        }

        return $out;
    }

}
