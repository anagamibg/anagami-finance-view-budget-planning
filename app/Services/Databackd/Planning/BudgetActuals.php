<?php

namespace App\Services\Databackd\Planning;

use App\Models\Databackd\FinBudget;
use App\Services\Databackd\FinancialDataService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Budget vs. actual comparison. Actuals come from FinancialDataService (fin_entries, flows of revenue and
 * expense accounts); the budget from fin_budget_lines. Amounts are positive in the sign convention of the
 * account type, so diff = actual - budget and "favorable" means: revenue above plan or expense below plan.
 */
class BudgetActuals
{

    protected FinancialDataService $data;

    public function __construct(?int $companyId = null)
    {
        $this->data = new FinancialDataService($companyId);
    }

    /**
     * Actual turnover of revenue and expense accounts per account and month of the year.
     *
     * @param int|null $entityId null = the whole group (all legal entities)
     * @param array $filters region_id, product_group_id, cost_center_id, client_segment_id, client_id
     * @return array<int, array<int, float>> [account_id => [month (1-12) => amount]]
     */
    public function actualsByAccountMonth(int $year, ?int $entityId, array $filters = []): array
    {
        if ($entityId) {
            $filters['entity_ids'] = [$entityId];
        }

        $rows = $this->data->query($filters)
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
            ->where('fin_entries.kind', 'flow')
            ->whereIn('fin_accounts.type', ['revenue', 'expense'])
            ->whereBetween('fin_entries.entry_date', [Carbon::create($year, 1, 1)->toDateString(), Carbon::create($year, 12, 31)->toDateString()])
            ->groupBy('fin_entries.account_id', DB::raw('MONTH(fin_entries.entry_date)'))
            ->selectRaw('fin_entries.account_id as account_id, MONTH(fin_entries.entry_date) as m, SUM(fin_entries.amount_base) as total')
            ->get();

        $out = [];

        foreach ($rows as $r) {
            $out[(int)$r->account_id][(int)$r->m] = round((float)$r->total, 2);
        }

        return $out;
    }

    /**
     * Budgeted amounts per account and month (the same shape as actualsByAccountMonth).
     *
     * @param array $filters region_id, product_group_id, cost_center_id (matched against the budget lines)
     * @return array<int, array<int, float>>
     */
    public function budgetByAccountMonth(FinBudget $budget, array $filters = []): array
    {
        $q = DB::table('fin_budget_lines')->where('budget_id', $budget->id);

        foreach (['region_id', 'product_group_id', 'cost_center_id'] as $key) {
            if (!empty($filters[$key])) {
                $q->where($key, $filters[$key]);
            }
        }

        $rows = $q->groupBy('account_id', 'month')->selectRaw('account_id, month, SUM(amount) as total')->get();
        $out = [];

        foreach ($rows as $r) {
            $out[(int)$r->account_id][(int)$r->month] = round((float)$r->total, 2);
        }

        return $out;
    }

    /**
     * Budget vs. actual per account for the months $fromMonth..$toMonth (1-12) of the budget year.
     * Accounts without budget and without actuals in the period are left out. Ordered by account code.
     *
     * @param array $filters region_id, product_group_id, cost_center_id
     * @return array<int, array{account_id: int, code: string, name: string, type: string, budget: float, actual: float, diff: float, diff_pct: float|null, favorable: bool}>
     */
    public function variance(FinBudget $b, int $fromMonth, int $toMonth, array $filters = []): array
    {
        $fromMonth = max(1, min(12, $fromMonth));
        $toMonth = max($fromMonth, min(12, $toMonth));

        $budget = $this->budgetByAccountMonth($b, $filters);
        $actual = $this->actualsByAccountMonth((int)$b->year, $b->entity_id, $filters);

        $accountIds = array_unique(array_merge(array_keys($budget), array_keys($actual)));

        if (empty($accountIds)) {
            return [];
        }

        $accounts = DB::table('fin_accounts')->where('company_id', $b->company_id)->whereIn('id', $accountIds)
            ->whereIn('type', ['revenue', 'expense'])->orderBy('code')->get();

        $rows = [];

        foreach ($accounts as $a) {
            $planned = 0.0;
            $real = 0.0;

            for ($m = $fromMonth; $m <= $toMonth; $m++) {
                $planned += $budget[$a->id][$m] ?? 0;
                $real += $actual[$a->id][$m] ?? 0;
            }

            if ($planned == 0 && $real == 0) {
                continue;
            }

            $diff = round($real - $planned, 2);

            $rows[] = [
                'account_id' => (int)$a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'budget' => round($planned, 2),
                'actual' => round($real, 2),
                'diff' => $diff,
                'diff_pct' => $planned != 0 ? round($diff / $planned * 100, 1) : null,
                'favorable' => $a->type === 'revenue' ? $diff >= 0 : $diff <= 0,
            ];
        }

        return $rows;
    }

}
