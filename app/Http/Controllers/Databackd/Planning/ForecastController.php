<?php

namespace App\Http\Controllers\Databackd\Planning;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinDimension;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinForecast;
use App\Services\Databackd\FinancialDataService;
use App\Services\Databackd\Planning\ForecastService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Forecast screen: history + forecast + corridor of one indicator, scenarios and saved forecasts.
 * The numbers come from ForecastService; saved forecasts only store the definition and are recomputed on open.
 */
class ForecastController extends AccountBaseController
{

    const HORIZONS = [3, 6, 12];
    const HISTORY_MONTHS = 18;

    /** Fewer months of history than this → the forecast is marked as rough. */
    const MIN_HISTORY = 6;
    const SCENARIOS = ['base', 'optimistic', 'pessimistic'];

    /** Default scenario assumptions (annual % change of revenue / expenses). */
    const SCENARIO_DEFAULTS = ['opt_rev' => 10.0, 'opt_exp' => 2.0, 'pes_rev' => -10.0, 'pes_exp' => 5.0];

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-planning-forecasts.title');
            abort_403(in_array('client', user_roles()));
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $this->def = $this->definition($request);
        $this->entities = FinEntity::orderBy('sort')->orderBy('name')->get(['id', 'name']);
        $this->accounts = FinAccount::whereIn('type', ['revenue', 'expense'])->orderBy('code')->get(['code', 'name', 'type']);
        $this->productGroups = FinDimension::where('type', 'product_group')->orderBy('name')->get(['id', 'name']);
        $this->saved = $this->savedList();
        $this->activeId = $this->def['forecast_id'];
        $this->canDeleteAll = in_array('admin', user_roles());
        $this->userId = user()->id;
        $this->currencySymbol = $this->currencySymbol();

        return view('databackd.planning.forecasts.index', $this->data);
    }

    /**
     * JSON for the charts and tables. Query: metric, metric_ref, entity_id, horizon, method, window, growth_pct,
     * corridor_pct, mode=forecast|scenarios, opt_rev, opt_exp, pes_rev, pes_exp, or forecast_id of a saved forecast.
     */
    public function data(Request $request)
    {
        $def = $this->definition($request);

        return response()->json($def['mode'] === 'scenarios' ? $this->computeScenarios($def) : $this->computeForecast($def));
    }

    /**
     * HTML of the saved forecasts panel (re-rendered after save / delete).
     */
    public function saved(Request $request)
    {
        $this->saved = $this->savedList();
        $this->activeId = (int)$request->get('active') ?: null;
        $this->canDeleteAll = in_array('admin', user_roles());
        $this->userId = user()->id;

        return response()->json([
            'status' => 'success',
            'html' => view('databackd.planning.forecasts._saved', $this->data)->render(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
        ], [
            'name.required' => __('databackd-planning-forecasts.name_required'),
        ]);

        if ($validator->fails()) {
            return Reply::formErrors($validator);
        }

        $def = $this->definition($request, false);

        $forecast = new FinForecast();
        $forecast->company_id = company()->id;
        $forecast->name = trim($request->name);
        $forecast->metric = $def['metric'];
        $forecast->metric_ref = $def['metric_ref'];
        $forecast->entity_id = $def['entity_id'];
        $forecast->method = $def['method'];
        $forecast->params = [
            'window' => $def['window'],
            'growth_pct' => $def['growth_pct'],
            'corridor_pct' => $def['corridor_pct'],
        ];
        $forecast->horizon = $def['horizon'];
        $forecast->scenarios = [
            'base' => ['revenue_growth' => 0, 'expense_growth' => 0],
            'optimistic' => ['revenue_growth' => $def['opt_rev'], 'expense_growth' => $def['opt_exp']],
            'pessimistic' => ['revenue_growth' => $def['pes_rev'], 'expense_growth' => $def['pes_exp']],
        ];
        $forecast->created_by = user()->id;
        $forecast->save();

        return Reply::successWithData(__('databackd-planning-forecasts.saved_ok'), ['id' => $forecast->id]);
    }

    /**
     * A saved forecast: open the screen with its definition applied (recomputed live).
     */
    public function show(Request $request, $id)
    {
        $forecast = FinForecast::findOrFail($id);
        $query = ['forecast_id' => $forecast->id];

        if ($request->get('mode') === 'scenarios') {
            $query['mode'] = 'scenarios';
        }

        return redirect()->route('databackd.planning.forecasts.index', $query);
    }

    public function destroy($id)
    {
        $forecast = FinForecast::findOrFail($id);

        abort_403(!in_array('admin', user_roles()) && (int)$forecast->created_by !== (int)user()->id);

        $forecast->delete();

        return Reply::success(__('databackd-planning-forecasts.deleted'));
    }

    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Normalised definition from the query string, or from a saved forecast when forecast_id is given.
     */
    private function definition(Request $request, bool $useSaved = true): array
    {
        $src = $request->only([
            'metric', 'metric_ref', 'entity_id', 'horizon', 'method', 'window', 'growth_pct', 'corridor_pct',
            'opt_rev', 'opt_exp', 'pes_rev', 'pes_exp',
        ]);
        $forecastId = null;

        if ($useSaved && $request->filled('forecast_id') && ($saved = FinForecast::find((int)$request->forecast_id))) {
            $forecastId = $saved->id;
            $params = (array)($saved->params ?? []);
            $sc = (array)($saved->scenarios ?? []);
            $src = [
                'metric' => $saved->metric,
                'metric_ref' => $saved->metric_ref,
                'entity_id' => $saved->entity_id,
                'horizon' => $saved->horizon,
                'method' => $saved->method,
                'window' => $params['window'] ?? null,
                'growth_pct' => $params['growth_pct'] ?? null,
                'corridor_pct' => $params['corridor_pct'] ?? null,
                'opt_rev' => $sc['optimistic']['revenue_growth'] ?? null,
                'opt_exp' => $sc['optimistic']['expense_growth'] ?? null,
                'pes_rev' => $sc['pessimistic']['revenue_growth'] ?? null,
                'pes_exp' => $sc['pessimistic']['expense_growth'] ?? null,
            ];
        }

        $metric = in_array($src['metric'] ?? null, FinForecast::METRICS, true) ? $src['metric'] : 'revenue';
        $ref = isset($src['metric_ref']) && $src['metric_ref'] !== '' ? (string)$src['metric_ref'] : null;

        if ($metric === 'account') {
            if (!$ref || !FinAccount::whereIn('type', ['revenue', 'expense'])->where('code', $ref)->exists()) {
                $metric = 'revenue';
                $ref = null;
            }
        }
        elseif ($metric === 'product_group') {
            if (!$ref || !FinDimension::where('type', 'product_group')->where('id', (int)$ref)->exists()) {
                $metric = 'revenue';
                $ref = null;
            }
        }
        else {
            $ref = null;
        }

        $entityId = !empty($src['entity_id']) ? (int)$src['entity_id'] : null;

        if ($entityId && !FinEntity::where('id', $entityId)->exists()) {
            $entityId = null;
        }

        $num = function ($key, $default, $min, $max) use ($src) {
            $v = $src[$key] ?? null;

            return is_numeric($v) ? max($min, min($max, (float)$v)) : $default;
        };

        $window = (int)($src['window'] ?? 3);

        return [
            'forecast_id' => $forecastId,
            'mode' => $request->get('mode') === 'scenarios' ? 'scenarios' : 'forecast',
            'metric' => $metric,
            'metric_ref' => $ref,
            'entity_id' => $entityId,
            'horizon' => in_array((int)($src['horizon'] ?? 6), self::HORIZONS, true) ? (int)($src['horizon'] ?? 6) : 6,
            'method' => in_array($src['method'] ?? null, FinForecast::METHODS, true) ? $src['method'] : 'linear',
            'window' => in_array($window, [3, 6], true) ? $window : 3,
            'growth_pct' => $num('growth_pct', 0.0, -50, 50),
            'corridor_pct' => $num('corridor_pct', 5.0, 0, 50),
            'opt_rev' => $num('opt_rev', self::SCENARIO_DEFAULTS['opt_rev'], -100, 100),
            'opt_exp' => $num('opt_exp', self::SCENARIO_DEFAULTS['opt_exp'], -100, 100),
            'pes_rev' => $num('pes_rev', self::SCENARIO_DEFAULTS['pes_rev'], -100, 100),
            'pes_exp' => $num('pes_exp', self::SCENARIO_DEFAULTS['pes_exp'], -100, 100),
        ];
    }

    private function methodParams(array $def): array
    {
        return match ($def['method']) {
            'moving_average' => ['window' => $def['window']],
            'manual' => ['growth_pct' => $def['growth_pct'], 'corridor_pct' => $def['corridor_pct']],
            default => [],
        };
    }

    /**
     * The last HISTORY_MONTHS finished months. The current month is left out until its last day: its turnover is
     * still partial and, as the last point of the series, it would pull every method down.
     *
     * @return Carbon[] [from, to]
     */
    private function historyRange(): array
    {
        $now = now();
        $to = $now->isLastOfMonth() ? $now->copy()->endOfMonth() : $now->copy()->startOfMonth()->subMonthNoOverflow()->endOfMonth();

        return [$to->copy()->startOfMonth()->subMonthsNoOverflow(self::HISTORY_MONTHS - 1), $to];
    }

    /**
     * Notes under the forecast: the history ends with the last finished month, short history, seasonality without a full year.
     *
     * @return array<int, array{type: string, text: string}>
     */
    private function notices(int $historyCount, ?string $method, Carbon $historyEnd, array $history = []): array
    {
        $out = [];

        if ($method === 'seasonal' && $historyCount >= 12 && $this->annualTrend($history) > 0.25) {
            $out[] = ['type' => 'warning', 'text' => __('databackd-planning-forecasts.notice_seasonal_trend')];
        }

        if ($historyCount > 0 && $historyCount < self::MIN_HISTORY) {
            $out[] = ['type' => 'warning', 'text' => __('databackd-planning-forecasts.notice_short_history', ['n' => $historyCount, 'min' => self::MIN_HISTORY])];
        }

        if ($method === 'seasonal' && $historyCount > 0 && $historyCount < 12) {
            $out[] = ['type' => 'warning', 'text' => __('databackd-planning-forecasts.notice_seasonal_short', ['n' => $historyCount])];
        }

        if (!now()->isLastOfMonth()) {
            $out[] = ['type' => 'info', 'text' => __('databackd-planning-forecasts.notice_history_end', ['month' => $this->monthLabel($historyEnd->format('Y-m'))])];
        }

        return $out;
    }

    /**
     * Change of the least-squares line over the last 12 months relative to their mean size (0.3 = the series moved by
     * ~30 % in a year). The seasonal method ignores such a trend, so the UI warns about it.
     */
    private function annualTrend(array $history): float
    {
        $values = array_values(array_slice($history, -12));
        $n = count($values);
        $mean = $n ? array_sum($values) / $n : 0.0;
        // relative to the mean size of the values: a profit around 0 must not look like a huge trend
        $scale = $n ? array_sum(array_map('abs', $values)) / $n : 0.0;

        if ($n < 3 || $scale < 0.005) {
            return 0.0;
        }

        $meanT = ($n - 1) / 2;
        $sxy = $sxx = 0.0;

        foreach ($values as $t => $y) {
            $sxy += ($t - $meanT) * ($y - $mean);
            $sxx += ($t - $meanT) ** 2;
        }

        return abs($sxy / $sxx * 12 / $scale);
    }

    private function currencySymbol(): string
    {
        $currency = company()->currency;

        return $currency->currency_symbol ?? $currency->currency_code ?? __('databackd-planning-forecasts.currency');
    }

    private function monthLabel(string $key): string
    {
        [$year, $month] = explode('-', $key);

        return __('databackd-planning-common.months_short.' . (int)$month) . ' ' . $year;
    }

    private function computeForecast(array $def): array
    {
        [$from, $to] = $this->historyRange();
        $service = new ForecastService();

        // months before the first month with data (company started later) would distort the trend
        $history = $service->trimLeadingZeros($service->historyFor($def['metric'], $def['metric_ref'], $def['entity_id'], $from, $to));
        $result = $service->forecast($history, $def['method'], $def['horizon'], $this->methodParams($def));

        $rows = [];

        foreach ($result['history'] ?? $history as $month => $value) {
            $rows[] = ['key' => $month, 'label' => $this->monthLabel($month), 'type' => 'history', 'value' => round((float)$value, 2), 'lower' => null, 'upper' => null];
        }

        $forecastValues = [];

        foreach ($result['forecast'] as $month => $value) {
            $forecastValues[] = (float)$value;
            $rows[] = [
                'key' => $month,
                'label' => $this->monthLabel($month),
                'type' => 'forecast',
                'value' => round((float)$value, 2),
                'lower' => round((float)($result['lower'][$month] ?? $value), 2),
                'upper' => round((float)($result['upper'][$month] ?? $value), 2),
            ];
        }

        $historyValues = array_values($history);
        $last = $historyValues ? (float)end($historyValues) : 0.0;
        $end = $forecastValues ? (float)end($forecastValues) : 0.0;

        return [
            'mode' => 'forecast',
            'metric' => $def['metric'],
            'metric_ref' => $def['metric_ref'],
            'title' => $this->metricLabel($def['metric'], $def['metric_ref']),
            'is_stock' => $def['metric'] === 'cash',
            'method' => $def['method'],
            'horizon' => $def['horizon'],
            'rows' => $rows,
            'has_history' => count(array_filter($history)) > 0,
            'history_count' => count(array_filter($history)) > 0 ? count($history) : 0,
            'last_label' => $history ? $this->monthLabel((string)array_key_last($history)) : null,
            'notices' => $this->notices(count(array_filter($history)) > 0 ? count($history) : 0, $def['method'], $to, $history),
            'summary' => [
                'last' => round($last, 2),
                'end' => round($end, 2),
                'total' => round(array_sum($forecastValues), 2),
                'avg' => $forecastValues ? round(array_sum($forecastValues) / count($forecastValues), 2) : 0,
                'change_pct' => abs($last) > 0.005 ? round(($end - $last) / abs($last) * 100, 1) : null,
            ],
        ];
    }

    private function computeScenarios(array $def): array
    {
        [$from, $to] = $this->historyRange();
        $service = new ForecastService();
        $method = $def['method'];
        $params = $this->methodParams($def);
        $horizon = $def['horizon'];

        $growth = [
            'base' => [0.0, 0.0],
            'optimistic' => [$def['opt_rev'], $def['opt_exp']],
            'pessimistic' => [$def['pes_rev'], $def['pes_exp']],
        ];

        $revHistory = $service->historyFor('revenue', null, $def['entity_id'], $from, $to);
        $expHistory = $service->historyFor('expense', null, $def['entity_id'], $from, $to);

        // the same start for both series: the first month with any revenue or expense
        $activity = [];

        foreach ($revHistory as $m => $v) {
            $activity[$m] = abs((float)$v) + abs((float)($expHistory[$m] ?? 0));
        }

        $start = array_key_first($service->trimLeadingZeros($activity));

        if ($start !== null) {
            $revHistory = array_filter($revHistory, fn($m) => $m >= $start, ARRAY_FILTER_USE_KEY);
            $expHistory = array_filter($expHistory, fn($m) => $m >= $start, ARRAY_FILTER_USE_KEY);
        }

        $revCfg = $expCfg = [];

        foreach ($growth as $name => [$revGrowth, $expGrowth]) {
            $revCfg[$name] = ['method' => $method, 'params' => $params, 'growth_pct' => $revGrowth];
            $expCfg[$name] = ['method' => $method, 'params' => $params, 'growth_pct' => $expGrowth];
        }

        $revenue = $service->scenarios($revHistory, $horizon, $revCfg);
        $expense = $service->scenarios($expHistory, $horizon, $expCfg);

        $filters = $def['entity_id'] ? ['entity_ids' => [$def['entity_id']]] : [];
        // cash at the end of the last history month: the forecast profit is added from the next month on
        // review A: the same cash as the dashboard and the alerts - 501-504 with analytic accounts
        $cashNow = round((new FinancialDataService())->balanceAt($to, FinancialDataService::CASH_CODES, $filters), 2);

        $historyMonths = array_keys($revHistory);
        $profitHistory = [];

        foreach ($historyMonths as $m) {
            $profitHistory[] = round((float)$revHistory[$m] - (float)($expHistory[$m] ?? 0), 2);
        }

        $labels = array_map(fn($m) => $this->monthLabel($m), $historyMonths);
        $forecastMonths = array_keys($revenue['base']['forecast']);

        foreach ($forecastMonths as $m) {
            $labels[] = $this->monthLabel($m);
        }

        $out = [];
        $base = null;

        foreach (self::SCENARIOS as $name) {
            $profit = [];

            foreach ($forecastMonths as $m) {
                $profit[] = round((float)$revenue[$name]['forecast'][$m] - (float)($expense[$name]['forecast'][$m] ?? 0), 2);
            }

            $row = [
                'name' => $name,
                'label' => __('databackd-planning-common.scenario.' . $name),
                'revenue_growth' => $growth[$name][0],
                'expense_growth' => $growth[$name][1],
                'revenue' => round(array_sum($revenue[$name]['forecast']), 2),
                'expense' => round(array_sum($expense[$name]['forecast']), 2),
                'profit' => round(array_sum($profit), 2),
                'cash_end' => round($cashNow + array_sum($profit), 2),
                'monthly' => $profit,
            ];

            $base ??= $row;
            $row['d_revenue'] = round($row['revenue'] - $base['revenue'], 2);
            $row['d_expense'] = round($row['expense'] - $base['expense'], 2);
            $row['d_profit'] = round($row['profit'] - $base['profit'], 2);
            $row['d_cash_end'] = round($row['cash_end'] - $base['cash_end'], 2);
            $out[] = $row;
        }

        return [
            'mode' => 'scenarios',
            'method' => $method,
            'horizon' => $horizon,
            'labels' => $labels,
            'history_count' => count($historyMonths),
            'forecast_labels' => array_map(fn($m) => $this->monthLabel($m), $forecastMonths),
            'profit_history' => $profitHistory,
            'cash_now' => $cashNow,
            'cash_label' => __('databackd-planning-forecasts.cash_start', ['month' => $this->monthLabel($to->format('Y-m'))]),
            'notices' => $this->notices(array_sum($activity ?? []) > 0 ? count($revHistory) : 0, $method, $to),
            'scenarios' => $out,
        ];
    }

    private function metricLabel(string $metric, ?string $ref): string
    {
        if ($metric === 'account' && $ref) {
            $account = FinAccount::where('code', $ref)->first(['code', 'name']);

            return $account ? __('databackd-planning-forecasts.account_label', ['code' => $account->code, 'name' => $account->name]) : $ref;
        }

        if ($metric === 'product_group' && $ref) {
            $group = FinDimension::find((int)$ref);

            return __('databackd-planning-common.metric.product_group') . ': ' . ($group->name ?? $ref);
        }

        return __('databackd-planning-common.metric.' . $metric);
    }

    private function savedList()
    {
        $forecasts = FinForecast::with(['creator:id,name', 'entity:id,name'])->orderByDesc('created_at')->orderByDesc('id')->get();

        $accounts = FinAccount::whereIn('code', $forecasts->where('metric', 'account')->pluck('metric_ref')->filter()->unique()->all())->pluck('name', 'code');
        $groups = FinDimension::whereIn('id', $forecasts->where('metric', 'product_group')->pluck('metric_ref')->filter()->map(fn($v) => (int)$v)->all())->pluck('name', 'id');

        foreach ($forecasts as $f) {
            if ($f->metric === 'account') {
                $f->metric_title = __('databackd-planning-forecasts.account_label', ['code' => $f->metric_ref, 'name' => $accounts[$f->metric_ref] ?? '']);
            }
            elseif ($f->metric === 'product_group') {
                $f->metric_title = __('databackd-planning-common.metric.product_group') . ': ' . ($groups[(int)$f->metric_ref] ?? $f->metric_ref);
            }
            else {
                $f->metric_title = __('databackd-planning-common.metric.' . $f->metric);
            }
        }

        return $forecasts;
    }

}
