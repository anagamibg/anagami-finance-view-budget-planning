<?php

namespace App\Console\Commands\Databackd;

use App\Services\Databackd\Planning\ForecastService;
use Illuminate\Console\Command;

/**
 * databackd: self-check of the forecast methods on synthetic series (no database needed).
 */
class CheckForecasts extends Command
{

    protected $signature = 'databackd:check-forecasts';

    protected $description = 'Check the ForecastService methods (linear, moving average, seasonal, manual) on synthetic series';

    private int $failed = 0;

    private int $passed = 0;

    public function handle(): int
    {
        $svc = new ForecastService();
        $methods = ['linear', 'moving_average', 'seasonal', 'manual'];

        // 1. perfect line y = 100 + 10t
        $line = $this->series('2025-05', 12, fn($t) => 100 + 10 * $t);
        $r = $svc->forecast($line, 'linear', 6);
        $expected = array_map(fn($t) => 100 + 10 * $t, range(12, 17));
        $this->check('linear: perfect line is continued exactly', $this->near(array_values($r['forecast']), $expected, 0.005));
        $this->check('linear: corridor of a perfect line is ~0', $this->near(array_values($r['upper']), array_values($r['lower']), 0.01));
        $this->check('linear: first forecast month is 2026-05', array_key_first($r['forecast']) === '2026-05');

        // noisy line: corridor must be > 0 and close to the noise sd
        $noisy = $this->series('2025-05', 18, fn($t) => 100 + 10 * $t + ($t % 2 ? 5 : -5));
        $r = $svc->forecast($noisy, 'linear', 3);
        $width = (end($r['upper']) - end($r['lower'])) / 2;
        $this->check('linear: corridor of a noisy line is ~5 (got ' . round($width, 2) . ')', $width > 4.5 && $width < 5.8);

        // 2. constant series: every method is flat
        $const = $this->series('2025-05', 18, fn($t) => 150);

        foreach ($methods as $m) {
            $r = $svc->forecast($const, $m, 6);
            $this->check("$m: constant series gives a flat forecast", $this->near(array_values($r['forecast']), array_fill(0, 6, 150.0), 0.005));

            if ($m !== 'manual') {
                $this->check("$m: constant series has a zero corridor", $this->near(array_values($r['lower']), array_values($r['upper']), 0.005));
            }
        }

        // 3. seasonal pattern, 24 months, period 12
        $pattern = fn($t) => 1000 + 200 * sin(2 * M_PI * ($t % 12) / 12);
        $seasonal = $this->series('2024-11', 24, $pattern);
        $r = $svc->forecast($seasonal, 'seasonal', 12);
        $expected = array_map($pattern, range(24, 35));
        $this->check('seasonal: 24-month pattern is reproduced', $this->near(array_values($r['forecast']), $expected, 0.05));
        $this->check('seasonal: perfect pattern has ~0 corridor', abs(end($r['upper']) - end($r['lower'])) < 0.1);

        $seasonal12 = $this->series('2025-11', 12, $pattern);
        $r = $svc->forecast($seasonal12, 'seasonal', 12);
        $this->check('seasonal: 12-month pattern is reproduced', $this->near(array_values($r['forecast']), array_map($pattern, range(12, 23)), 0.05));

        // 4. moving average on constant + step
        $step = $this->series('2025-05', 12, fn($t) => $t < 6 ? 100 : 200);
        $r = $svc->forecast($step, 'moving_average', 3, ['window' => 3]);
        $this->check('moving_average: after a step the average settles at the new level', $this->near(array_values($r['forecast']), [200.0, 200.0, 200.0], 0.005));
        $this->check('moving_average: step produces a non-zero corridor', end($r['upper']) > end($r['lower']));

        $short = ['2026-07' => 100, '2026-08' => 100, '2026-09' => 200, '2026-10' => 200];
        $r = $svc->forecast($short, 'moving_average', 2, ['window' => 3]);
        $f = array_values($r['forecast']);
        $this->check('moving_average: rolling feedback (166.67, 188.89)', abs($f[0] - 166.67) < 0.005 && abs($f[1] - 188.89) < 0.005);
        $r = $svc->forecast($short, 'moving_average', 1, ['window' => 6]);
        $this->check('moving_average: window 6 is clamped to the history length (150)', abs(reset($r['forecast']) - 150.0) < 0.005);

        // 5. manual growth 2 % per month
        $r = $svc->forecast(['2026-09' => 900, '2026-10' => 1000], 'manual', 4, ['growth_pct' => 2]);
        $exp = array_map(fn($t) => round(1000 * 1.02 ** $t, 2), range(1, 4));
        $this->check('manual: exact compounding', $this->near(array_values($r['forecast']), $exp, 0.0001));
        $this->check('manual: default corridor is +-5 %', $this->near(array_values($r['lower']), array_map(fn($v) => round($v * 0.95, 2), $exp), 0.011)
            && $this->near(array_values($r['upper']), array_map(fn($v) => round($v * 1.05, 2), $exp), 0.011));
        $r = $svc->forecast(['2026-10' => 1000], 'manual', 2, ['growth_pct' => 0, 'corridor_pct' => 10]);
        $this->check('manual: corridor_pct = 10', $this->near(array_values($r['lower']), [900.0, 900.0], 0.0001) && $this->near(array_values($r['upper']), [1100.0, 1100.0], 0.0001));
        $r = $svc->forecast(['2026-10' => -1000], 'manual', 1, ['growth_pct' => 0, 'corridor_pct' => 10]);
        $this->check('manual: negative value keeps lower <= forecast <= upper', reset($r['lower']) <= reset($r['forecast']) && reset($r['forecast']) <= reset($r['upper']));

        // 6. short histories never throw
        foreach ($methods as $m) {
            foreach ([0, 1, 2] as $n) {
                $hist = $this->series('2026-09', $n, fn($t) => 100 + 20 * $t);

                try {
                    $r = $svc->forecast($hist, $m, 3, ['growth_pct' => 1]);
                    $ok = count($r['forecast']) === 3 && $this->allFinite($r);
                } catch (\Throwable $e) {
                    $ok = false;
                }

                $this->check("$m: history of $n point(s) does not throw and is finite", $ok);
            }

            $r = $svc->forecast([], $m, 3);
            $this->check("$m: empty history gives flat zeros", $this->near(array_values($r['forecast']), [0.0, 0.0, 0.0], 0.0001));

            if ($m !== 'manual') {
                $r = $svc->forecast(['2026-10' => 77.5], $m, 2);
                $this->check("$m: single point gives that value", $this->near(array_values($r['forecast']), [77.5, 77.5], 0.0001));
            }
        }

        $r = $svc->forecast(['2026-09' => 100, '2026-10' => 120], 'linear', 2);
        $this->check('linear: two points continue the line (140, 160)', $this->near(array_values($r['forecast']), [140.0, 160.0], 0.0001));

        // 7. result format and month keys across the year boundary
        foreach ($methods as $m) {
            $r = $svc->forecast($this->series('2026-01', 10, fn($t) => 100 + $t), $m, 4, ['growth_pct' => 1]);
            $this->check("$m: result keys present", array_keys($r) === ['history', 'forecast', 'lower', 'upper', 'method', 'params'] && $r['method'] === $m);
            $this->check("$m: month keys continue 2026-11, 2026-12, 2027-01, 2027-02",
                array_keys($r['forecast']) === ['2026-11', '2026-12', '2027-01', '2027-02']
                && array_keys($r['lower']) === array_keys($r['forecast']) && array_keys($r['upper']) === array_keys($r['forecast']));
            $this->check("$m: values rounded to 2 decimals", $this->rounded($r));
        }

        $r = $svc->forecast($line, 'no_such_method', 2);
        $this->check('unknown method is treated as linear', $r['method'] === 'linear' && $this->near(array_values($r['forecast']), [220.0, 230.0], 0.005));

        $r = $svc->forecast(['2026-10' => 5, '2026-08' => 3, '2026-09' => 4], 'linear', 1);
        $this->check('unsorted history is sorted', array_keys($r['history']) === ['2026-08', '2026-09', '2026-10'] && abs(reset($r['forecast']) - 6.0) < 0.005);

        // 8. lower <= forecast <= upper, also for noisy and negative series
        $negative = $this->series('2025-05', 18, fn($t) => -5000 + 300 * $t + (($t * 7) % 5 - 2) * 400);
        $noisy2 = $this->series('2025-05', 18, fn($t) => 20000 + 800 * $t + (($t * 11) % 7 - 3) * 900);

        foreach ($methods as $m) {
            foreach (['noisy' => $noisy2, 'negative' => $negative] as $label => $hist) {
                $r = $svc->forecast($hist, $m, 12, ['growth_pct' => 1.5, 'window' => 6]);
                $ok = true;

                foreach ($r['forecast'] as $k => $v) {
                    $ok = $ok && $r['lower'][$k] <= $v && $v <= $r['upper'][$k];
                }

                $this->check("$m: lower <= forecast <= upper ($label series)", $ok && $this->allFinite($r));
            }
        }

        // 9. scenarios: linear growth ramp
        $out = $svc->scenarios($const, 12, [
            'base' => ['method' => 'linear', 'growth_pct' => 0],
            'optimistic' => ['method' => 'linear', 'growth_pct' => 12],
            'pessimistic' => ['method' => 'linear', 'growth_pct' => -12],
        ]);
        $this->check('scenarios: names and scenario key', array_keys($out) === ['base', 'optimistic', 'pessimistic'] && $out['optimistic']['scenario'] === 'optimistic');
        $f = array_values($out['optimistic']['forecast']);
        $this->check('scenarios: month 12 multiplied by 1 + g/100 (168.00)', abs($f[11] - 168.0) < 0.005);
        $this->check('scenarios: month 6 multiplied by 1 + g/100 * 6/12 (159.00)', abs($f[5] - 159.0) < 0.005);
        $this->check('scenarios: pessimistic month 12 (132.00)', abs(array_values($out['pessimistic']['forecast'])[11] - 132.0) < 0.005);
        $this->check('scenarios: base is untouched (150)', $this->near(array_values($out['base']['forecast']), array_fill(0, 12, 150.0), 0.005));

        // 10. short history: leading months without data (before the company started recording) are trimmed
        $late = ['2025-05' => 0, '2025-06' => 0, '2025-07' => 0, '2025-08' => 100, '2025-09' => 0, '2025-10' => 120];
        $trimmed = $svc->trimLeadingZeros($late);
        $this->check('trimLeadingZeros: drops only the leading empty months', array_keys($trimmed) === ['2025-08', '2025-09', '2025-10']);
        $this->check('trimLeadingZeros: an all-zero series is kept', count($svc->trimLeadingZeros(['2026-01' => 0, '2026-02' => 0.0])) === 2);
        $this->check('trimLeadingZeros: negative first value counts as data', array_key_first($svc->trimLeadingZeros(['2026-01' => 0, '2026-02' => -50, '2026-03' => 10])) === '2026-02');
        $this->check('trimLeadingZeros: unsorted input is sorted', array_keys($svc->trimLeadingZeros(['2026-03' => 5, '2026-01' => 0, '2026-02' => 4])) === ['2026-02', '2026-03']);

        // 6 months of a flat 1000 after 12 empty months: untrimmed the trend climbs, trimmed it stays flat
        $startup = $this->series('2025-05', 18, fn($t) => $t < 12 ? 0 : 1000);
        $raw = $svc->forecast($startup, 'linear', 3);
        $fixed = $svc->forecast($svc->trimLeadingZeros($startup), 'linear', 3);
        $this->check('linear: leading zeros distort the trend (forecast > 1030)', reset($raw['forecast']) > 1030);
        $this->check('linear: trimmed startup history is flat (1000)', $this->near(array_values($fixed['forecast']), [1000.0, 1000.0, 1000.0], 0.005));

        // 11. zero and negative series
        $zeros = $this->series('2025-05', 18, fn($t) => 0);

        foreach ($methods as $m) {
            $r = $svc->forecast($zeros, $m, 3, ['growth_pct' => 5]);
            $this->check("$m: all-zero history gives zeros with a zero corridor", $this->near(array_values($r['forecast']), [0.0, 0.0, 0.0], 0.0001)
                && $this->near(array_values($r['upper']), [0.0, 0.0, 0.0], 0.0001));
        }

        $loss = $this->series('2025-05', 12, fn($t) => -1000 - 50 * $t);
        $r = $svc->forecast($loss, 'linear', 2);
        $this->check('linear: a falling negative series continues below zero (-1600, -1650)', $this->near(array_values($r['forecast']), [-1600.0, -1650.0], 0.005));
        $r = $svc->forecast($this->series('2025-05', 12, fn($t) => -500), 'seasonal', 3);
        $this->check('seasonal: constant negative series stays at its level (-500)', $this->near(array_values($r['forecast']), [-500.0, -500.0, -500.0], 0.005));

        $this->newLine();
        $this->line("Passed: {$this->passed}, failed: {$this->failed}");

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function check(string $name, bool $ok): void
    {
        if ($ok) {
            $this->passed++;
            $this->line("<info>✓</info> $name");
        }
        else {
            $this->failed++;
            $this->line("<error>✗ $name</error>");
        }
    }

    /**
     * Builds a monthly series starting at $start ('YYYY-MM') with $n points, value = $fn(t).
     */
    private function series(string $start, int $n, callable $fn): array
    {
        $date = \Carbon\Carbon::createFromFormat('!Y-m-d', $start . '-01');
        $out = [];

        for ($t = 0; $t < $n; $t++) {
            $out[$date->copy()->addMonthsNoOverflow($t)->format('Y-m')] = (float)$fn($t);
        }

        return $out;
    }

    private function near(array $a, array $b, float $tolerance): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach (array_values($a) as $i => $v) {
            if (abs($v - array_values($b)[$i]) > $tolerance) {
                return false;
            }
        }

        return true;
    }

    private function allFinite(array $result): bool
    {
        foreach (['forecast', 'lower', 'upper'] as $part) {
            foreach ($result[$part] as $v) {
                if (!is_finite($v)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function rounded(array $result): bool
    {
        foreach (['forecast', 'lower', 'upper'] as $part) {
            foreach ($result[$part] as $v) {
                if (abs($v - round($v, 2)) > 1e-9) {
                    return false;
                }
            }
        }

        return true;
    }

}
