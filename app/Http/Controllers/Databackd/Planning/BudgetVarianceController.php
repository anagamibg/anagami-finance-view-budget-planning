<?php

namespace App\Http\Controllers\Databackd\Planning;

use App\Exports\Databackd\Planning\VarianceExport;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinBudget;
use App\Models\Databackd\FinDimension;
use App\Services\Databackd\Planning\BudgetActuals;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Budget vs. actual screen: filters, KPI cards, variance table, monthly chart and Excel export.
 */
class BudgetVarianceController extends AccountBaseController
{

    const MODES = ['month', 'ytd', 'year'];
    const DIMENSIONS = ['region_id' => 'region', 'product_group_id' => 'product_group', 'cost_center_id' => 'cost_center'];

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-planning-variance.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $this->budgets = FinBudget::with('entity')->orderByDesc('year')->orderBy('name')->get();
        $this->budgetsUrl = \Route::has('databackd.planning.budgets.create') ? route('databackd.planning.budgets.create') : null;

        if ($this->budgets->isEmpty()) {
            $this->report = null;

            return view('databackd.planning.variance.index', $this->data);
        }

        $report = $this->buildReport($request, $this->budgets);

        $this->report = $report;
        $this->budget = $report['budget'];
        $this->mode = $report['mode'];
        $this->month = $report['month'];
        $this->selected = $report['filters'];
        $this->dimensions = FinDimension::whereIn('type', array_values(self::DIMENSIONS))->orderBy('name')->get(['id', 'type', 'name'])->groupBy('type');
        $this->exportUrl = route('databackd.planning.variance.export', $report['query']);
        $this->baseCurrency = $report['currency'];

        return view('databackd.planning.variance.index', $this->data);
    }

    public function export(Request $request)
    {
        $budgets = FinBudget::with('entity')->orderByDesc('year')->orderBy('name')->get();
        abort_if($budgets->isEmpty(), 404);

        $report = $this->buildReport($request, $budgets);

        return Excel::download(
            new VarianceExport($report['table'], $report['caption']),
            'budget-vs-actual-' . $report['budget']->year . '-' . now()->format('Y-m-d-His') . '.xlsx'
        );
    }

    /**
     * Resolve the request (budget, period, dimension filters) and compute everything the screen shows.
     * Without parameters: the newest approved budget (else the newest budget) and year to date.
     *
     * @param \Illuminate\Support\Collection $budgets all budgets of the company, newest first
     * @return array{budget: FinBudget, mode: string, month: int, from: int, to: int, filters: array, query: array, caption: string,
     *     rows: array, table: array, kpi: array, chart: array, months: array}
     */
    public function buildReport(Request $request, $budgets): array
    {
        $budget = $budgets->firstWhere('id', (int)$request->input('budget_id'))
            ?? $budgets->where('version', 'approved')->sortByDesc(fn($b) => [$b->year, $b->id])->first()
            ?? $budgets->sortByDesc(fn($b) => [$b->year, $b->id])->first();

        $filters = [];

        foreach (array_keys(self::DIMENSIONS) as $key) {
            if ((int)$request->input($key) > 0) {
                $filters[$key] = (int)$request->input($key);
            }
        }

        $service = new BudgetActuals();
        $budgetMonthly = $service->budgetByAccountMonth($budget, $filters);
        $actualMonthly = $service->actualsByAccountMonth((int)$budget->year, $budget->entity_id, $filters);

        $mode = in_array($request->input('mode'), self::MODES, true) ? $request->input('mode') : 'ytd';

        $year = (int)$budget->year;
        $lastActual = $this->lastMonthWithActuals($actualMonthly);

        if ((int)$request->input('month') >= 1 && (int)$request->input('month') <= 12) {
            $month = (int)$request->input('month');
        }
        else {
            $month = $this->defaultMonth($lastActual, $year);
        }

        [$from, $to] = match ($mode) {
            'month' => [$month, $month],
            'year' => [1, 12],
            default => [1, $month],
        };

        $rows = $service->variance($budget, $from, $to, $filters);
        $months = $this->months($from, $to);
        $table = $this->buildTable($rows);

        $monthNames = trans('databackd-planning-common.months');
        $periodName = match ($mode) {
            'month' => $monthNames[$month] ?? $month,
            'year' => __('databackd-planning-variance.year_period'),
            default => __('databackd-planning-variance.ytd_period', ['month' => $monthNames[$month] ?? $month]),
        };

        $caption = $this->budgetLabel($budget) . ' · ' . __('databackd-planning-variance.period_label', ['period' => $periodName, 'year' => $budget->year])
            . ' · ' . ($budget->entity->name ?? __('databackd-planning-common.all_entities'));

        $query = array_merge(['budget_id' => $budget->id, 'mode' => $mode, 'month' => $month], $filters);
        $currency = $this->baseCurrency();

        return [
            'budget' => $budget,
            'mode' => $mode,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'filters' => $filters,
            'query' => $query,
            'caption' => $caption,
            'rows' => $rows,
            'table' => $table['rows'],
            'kpi' => $table['kpi'],
            'months' => $months,
            'chart' => $this->buildChart($budgetMonthly, $actualMonthly, $months, $year),
            'top' => $this->topDeviations($rows),
            'currency' => $currency,
            'notices' => $this->notices($budget, $from, $to, $lastActual, $filters, $budgetMonthly, $currency),
        ];
    }

    /**
     * Warnings about what makes the comparison incomplete: no actuals yet, months after the last actuals, the current
     * unfinished month, a dimension filter the budget has no lines for, a budget in another currency.
     *
     * @return array<int, array{type: string, text: string}>
     */
    protected function notices(FinBudget $budget, int $from, int $to, int $lastActual, array $filters, array $budgetMonthly, string $currency): array
    {
        $year = (int)$budget->year;
        $names = trans('databackd-planning-common.months');
        // month names start with a capital letter in English only
        $monthName = fn(int $m) => str_starts_with(app()->getLocale(), 'en') ? ($names[$m] ?? (string)$m) : mb_strtolower($names[$m] ?? (string)$m);
        $now = now();
        $out = [];

        if ($lastActual === 0) {
            $out[] = ['type' => 'warning', 'text' => __('databackd-planning-variance.notices.no_actuals', ['year' => $year])];
        }
        elseif ($to > $lastActual) {
            $out[] = ['type' => 'warning', 'text' => __('databackd-planning-variance.notices.actuals_until', ['month' => $monthName($lastActual), 'year' => $year])];
        }

        if ($year === (int)$now->year && $from <= $now->month && $now->month <= $to && $lastActual >= $now->month && !$now->isLastOfMonth()) {
            $out[] = ['type' => 'info', 'text' => __('databackd-planning-variance.notices.current_month', ['month' => $monthName((int)$now->month)])];
        }

        if ($filters && empty($budgetMonthly)) {
            $out[] = ['type' => 'warning', 'text' => __('databackd-planning-variance.notices.no_dimension_budget')];
        }

        if ($budget->currency && strtoupper($budget->currency) !== $currency) {
            $out[] = ['type' => 'warning', 'text' => __('databackd-planning-variance.notices.currency', ['budget' => strtoupper($budget->currency), 'base' => $currency])];
        }

        return $out;
    }

    /**
     * Up to 8 accounts with the largest absolute deviation in the period (for the "largest deviations" chart).
     *
     * @return array<int, array{label: string, diff: float, diff_pct: float|null, favorable: bool}>
     */
    protected function topDeviations(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn($r) => abs($r['diff']) >= 0.005));
        usort($rows, fn($a, $b) => abs($b['diff']) <=> abs($a['diff']));

        return array_map(fn($r) => [
            'label' => trim($r['code'] . ' ' . $r['name']),
            'diff' => $r['diff'],
            'diff_pct' => $r['diff_pct'],
            'favorable' => $r['favorable'],
        ], array_slice($rows, 0, 8));
    }

    /**
     * Currency of the actual amounts (fin_entries.amount_base = the company currency).
     */
    protected function baseCurrency(): string
    {
        $code = company()->currency->currency_code ?? null;

        return $code ? strtoupper($code) : 'EUR';
    }

    public function budgetLabel(FinBudget $budget): string
    {
        return __('databackd-planning-variance.budget_label', [
            'name' => $budget->name,
            'year' => $budget->year,
            'version' => __('databackd-planning-common.version.' . $budget->version),
        ]);
    }

    /**
     * Display rows: section headers, account rows, subtotals and the final profit row.
     * Every amount row has: kind (section|row|subtotal|profit), code, label, budget, actual, diff, diff_pct, favorable.
     *
     * @return array{rows: array, kpi: array}
     */
    protected function buildTable(array $variance): array
    {
        $out = [];
        $totals = [];

        foreach (['revenue', 'expense'] as $type) {
            $out[] = ['kind' => 'section', 'label' => __('databackd-planning-variance.sections.' . $type), 'type' => $type];
            $budget = 0.0;
            $actual = 0.0;

            foreach ($variance as $row) {
                if ($row['type'] !== $type) {
                    continue;
                }

                $budget += $row['budget'];
                $actual += $row['actual'];
                $out[] = [
                    'kind' => 'row',
                    'type' => $type,
                    'code' => $row['code'],
                    'label' => $row['name'],
                    'budget' => $row['budget'],
                    'actual' => $row['actual'],
                    'diff' => $row['diff'],
                    'diff_pct' => $row['diff_pct'],
                    'favorable' => $row['favorable'],
                ];
            }

            $totals[$type] = $this->amounts($budget, $actual, $type);
            $out[] = ['kind' => 'subtotal', 'type' => $type, 'code' => '', 'label' => __('databackd-planning-variance.subtotal', ['section' => mb_strtolower(__('databackd-planning-variance.sections.' . $type))])] + $totals[$type];
        }

        $totals['profit'] = $this->amounts(
            $totals['revenue']['budget'] - $totals['expense']['budget'],
            $totals['revenue']['actual'] - $totals['expense']['actual'],
            'profit'
        );
        $out[] = ['kind' => 'profit', 'type' => 'profit', 'code' => '', 'label' => __('databackd-planning-variance.profit')] + $totals['profit'];

        return ['rows' => $out, 'kpi' => $totals];
    }

    /**
     * budget / actual / diff / diff_pct / favorable of an aggregate (the rule of BudgetActuals::variance()).
     */
    protected function amounts(float $budget, float $actual, string $type): array
    {
        $diff = round($actual - $budget, 2);

        return [
            'budget' => round($budget, 2),
            'actual' => round($actual, 2),
            'diff' => $diff,
            // abs(): the planned profit can be a loss; a better result must still read as a positive %
            'diff_pct' => $budget != 0 ? round($diff / abs($budget) * 100, 1) : null,
            'favorable' => $type === 'expense' ? $diff <= 0 : $diff >= 0,
        ];
    }

    /**
     * Monthly budget and actual sums per account type for the chart.
     *
     * @return array{labels: array, revenue: array, expense: array, profit: array}
     */
    protected function buildChart(array $budgetMonthly, array $actualMonthly, array $months, int $year): array
    {
        $accountIds = array_unique(array_merge(array_keys($budgetMonthly), array_keys($actualMonthly)));
        $types = $accountIds ? FinAccount::whereIn('id', $accountIds)->pluck('type', 'id')->all() : [];
        $names = trans('databackd-planning-common.months_short');

        $chart = ['labels' => [], 'revenue' => ['budget' => [], 'actual' => []], 'expense' => ['budget' => [], 'actual' => []], 'profit' => ['budget' => [], 'actual' => []]];

        foreach ($months as $m) {
            $sum = ['revenue' => ['budget' => 0.0, 'actual' => 0.0], 'expense' => ['budget' => 0.0, 'actual' => 0.0]];

            foreach ($accountIds as $id) {
                $type = $types[$id] ?? null;

                if (!isset($sum[$type])) {
                    continue;
                }

                $sum[$type]['budget'] += $budgetMonthly[$id][$m] ?? 0;
                $sum[$type]['actual'] += $actualMonthly[$id][$m] ?? 0;
            }

            $chart['labels'][] = ($names[$m] ?? $m) . ' ' . substr((string)$year, -2);

            foreach (['budget', 'actual'] as $series) {
                $chart['revenue'][$series][] = round($sum['revenue'][$series], 2);
                $chart['expense'][$series][] = round($sum['expense'][$series], 2);
                $chart['profit'][$series][] = round($sum['revenue'][$series] - $sum['expense'][$series], 2);
            }
        }

        return $chart;
    }

    /**
     * Months shown in the chart: the period, or the whole year when the period is a single month.
     */
    protected function months(int $from, int $to): array
    {
        return $from === $to ? range(1, 12) : range($from, $to);
    }

    /**
     * Last month of the budget year with any actual turnover (0 = none yet).
     */
    protected function lastMonthWithActuals(array $actualMonthly): int
    {
        $last = 0;

        foreach ($actualMonthly as $months) {
            foreach ($months as $m => $amount) {
                if ($amount != 0 && $m > $last) {
                    $last = $m;
                }
            }
        }

        return $last;
    }

    /**
     * Default "up to" month: the last month with actuals, but not the current, still unfinished month (its turnover
     * is partial while its budget is for the whole month); without actuals: the last finished month of the
     * current year, December for other years.
     */
    protected function defaultMonth(int $lastActual, int $year): int
    {
        $now = now();
        $unfinished = $year === (int)$now->year && !$now->isLastOfMonth();

        if ($lastActual > 0) {
            return $unfinished && $lastActual === (int)$now->month && $lastActual > 1 ? $lastActual - 1 : $lastActual;
        }

        if ($year === (int)$now->year) {
            return $unfinished && $now->month > 1 ? (int)$now->month - 1 : (int)$now->month;
        }

        return 12;
    }

}
