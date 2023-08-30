<?php

namespace App\Services\Databackd\Planning;

use App\Models\Databackd\FinBudget;
use Illuminate\Support\Facades\DB;

/**
 * Creates budgets: empty, from the actual data of a year (± a percentage), as a copy of another budget,
 * or as a duplicate. Used by the budget screens and by the demo seeder.
 *
 * The `$data` array of every create method accepts: name (required), year (required), entity_id (null = whole
 * group), scenario (base), currency (EUR), notes, version (draft), company_id (default: current company),
 * created_by (default: current user). All created budgets are drafts unless `version` says otherwise.
 */
class BudgetFactory
{

    /**
     * An empty draft budget without lines.
     */
    public function createEmpty(array $data): FinBudget
    {
        return $this->makeBudget($data);
    }

    /**
     * Budget pre-filled with the actual revenue and expense per account and month of $sourceYear,
     * each amount multiplied by (1 + $pct / 100). The actuals are those of the budget's legal entity
     * (`entity_id`), or of the whole group when it is null. Only positive amounts become lines.
     *
     * @param bool $fillGaps months of the source year without any actuals (e.g. a year that is not complete
     *                       yet) are filled per account from the same month of the previous year, otherwise
     *                       from the account's average over the months that have data
     */
    public function fromActuals(array $data, int $sourceYear, float $pct = 0.0, bool $fillGaps = true): FinBudget
    {
        $companyId = $this->companyId($data);
        $entityId = !empty($data['entity_id']) ? (int)$data['entity_id'] : null;

        $service = new BudgetActuals($companyId);
        $actuals = $service->actualsByAccountMonth($sourceYear, $entityId);

        if ($fillGaps && !empty($actuals)) {
            $actuals = $this->fillGaps($actuals, $service->actualsByAccountMonth($sourceYear - 1, $entityId));
        }

        $factor = 1 + $pct / 100;
        $rows = [];

        foreach ($actuals as $accountId => $months) {
            foreach ($months as $month => $amount) {
                $value = round($amount * $factor, 2);

                if ($value > 0 && $month >= 1 && $month <= 12) {
                    $rows[] = ['account_id' => (int)$accountId, 'month' => (int)$month, 'amount' => $value];
                }
            }
        }

        return DB::transaction(function () use ($data, $rows, $companyId) {
            $budget = $this->makeBudget($data);
            $this->insertLines($budget->id, $companyId, $rows);

            return $budget;
        });
    }

    /**
     * Budget with all lines copied 1:1 from $source (header values come from $data).
     */
    public function fromBudget(array $data, FinBudget $source): FinBudget
    {
        $companyId = $this->companyId($data);

        return DB::transaction(function () use ($data, $source, $companyId) {
            $budget = $this->makeBudget($data);
            $this->copyLines($source, $budget, $companyId);

            return $budget;
        });
    }

    /**
     * Draft copy of a budget (same year, legal entity, scenario, currency, notes and lines).
     */
    public function duplicate(FinBudget $b, ?string $name = null): FinBudget
    {
        $data = [
            'company_id' => $b->company_id,
            'entity_id' => $b->entity_id,
            'name' => $name ?: trim($b->name . ' ' . __('databackd-planning-budgets.copy_suffix')),
            'year' => $b->year,
            'scenario' => $b->scenario,
            'currency' => $b->currency,
            'notes' => $b->notes,
        ];

        return $this->fromBudget($data, $b);
    }

    /**
     * Totals from the budget lines: [budget_id => ['revenue' => float, 'expense' => float, 'profit' => float]].
     * Budgets without lines are present with zeros.
     *
     * @param array<int> $budgetIds
     */
    public function totals(array $budgetIds): array
    {
        $out = [];

        foreach ($budgetIds as $id) {
            $out[$id] = ['revenue' => 0.0, 'expense' => 0.0, 'profit' => 0.0];
        }

        if (empty($budgetIds)) {
            return $out;
        }

        $rows = DB::table('fin_budget_lines as l')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.budget_id', $budgetIds)
            ->whereIn('a.type', ['revenue', 'expense'])
            ->groupBy('l.budget_id', 'a.type')
            ->selectRaw('l.budget_id, a.type, SUM(l.amount) as total')
            ->get();

        foreach ($rows as $r) {
            $out[(int)$r->budget_id][$r->type] = round((float)$r->total, 2);
        }

        foreach ($out as $id => $t) {
            $out[$id]['profit'] = round($t['revenue'] - $t['expense'], 2);
        }

        return $out;
    }

    private function makeBudget(array $data): FinBudget
    {
        $budget = new FinBudget();
        $budget->company_id = $this->companyId($data);
        $budget->entity_id = !empty($data['entity_id']) ? (int)$data['entity_id'] : null;
        $budget->name = (string)$data['name'];
        $budget->year = (int)$data['year'];
        $budget->version = $data['version'] ?? 'draft';
        $budget->scenario = $data['scenario'] ?? 'base';
        $budget->currency = strtoupper($data['currency'] ?? 'EUR');
        $budget->notes = isset($data['notes']) && trim((string)$data['notes']) !== '' ? $data['notes'] : null;
        $budget->created_by = $data['created_by'] ?? (user() ? user()->id : null);

        if ($budget->version === 'approved') {
            $budget->approved_by = $data['approved_by'] ?? $budget->created_by;
            $budget->approved_at = $data['approved_at'] ?? now();
        }

        $budget->save();

        return $budget;
    }

    private function companyId(array $data): int
    {
        return (int)($data['company_id'] ?? company()->id);
    }

    private function copyLines(FinBudget $source, FinBudget $target, int $companyId): void
    {
        $now = now();

        DB::table('fin_budget_lines')->where('budget_id', $source->id)->orderBy('id')->chunk(500, function ($chunk) use ($target, $companyId, $now) {
            $rows = [];

            foreach ($chunk as $l) {
                $rows[] = [
                    'company_id' => $companyId,
                    'budget_id' => $target->id,
                    'account_id' => $l->account_id,
                    'region_id' => $l->region_id,
                    'product_group_id' => $l->product_group_id,
                    'cost_center_id' => $l->cost_center_id,
                    'month' => $l->month,
                    'amount' => $l->amount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('fin_budget_lines')->insert($rows);
        });
    }

    /**
     * @param array<int, array{account_id: int, month: int, amount: float}> $rows
     */
    private function insertLines(int $budgetId, int $companyId, array $rows): void
    {
        $now = now();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('fin_budget_lines')->insert(array_map(fn($r) => [
                'company_id' => $companyId,
                'budget_id' => $budgetId,
                'account_id' => $r['account_id'],
                'region_id' => null,
                'product_group_id' => null,
                'cost_center_id' => null,
                'month' => $r['month'],
                'amount' => $r['amount'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }

    /**
     * Fills the months that have no actuals at all (for any account) per account: the same month of the
     * previous year when available, otherwise the account's average over its months with data.
     */
    private function fillGaps(array $actuals, array $previous): array
    {
        $present = [];

        foreach ($actuals as $months) {
            foreach ($months as $m => $v) {
                if ($v != 0) {
                    $present[$m] = true;
                }
            }
        }

        $missing = array_values(array_filter(range(1, 12), fn($m) => !isset($present[$m])));

        if (empty($missing) || count($missing) === 12) {
            return $actuals;
        }

        foreach ($actuals as $accountId => $months) {
            $values = array_filter($months, fn($v) => $v > 0);

            if (empty($values)) {
                continue;
            }

            $avg = round(array_sum($values) / count($values), 2);

            foreach ($missing as $m) {
                $prev = $previous[$accountId][$m] ?? 0;
                $actuals[$accountId][$m] = $prev > 0 ? $prev : $avg;
            }
        }

        return $actuals;
    }

}
