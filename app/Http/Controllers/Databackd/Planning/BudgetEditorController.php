<?php

namespace App\Http\Controllers\Databackd\Planning;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinBudget;
use App\Models\Databackd\FinBudgetLine;
use App\Services\Databackd\Planning\BudgetActuals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Budget editor: a grid of revenue and expense accounts x 12 months for one budget.
 *
 * The editor works on the dimension-free level (region / product group / cost center = NULL). Accounts that
 * already have dimension-split lines in the budget are shown as the sum and are read-only. Zero or empty cells
 * are stored as "no line" (a dimension-free line with amount 0 is deleted). An approved budget is read-only.
 * Inactive accounts are shown (and stay editable) only while the budget still has lines on them, so their
 * amounts are visible and can be cleared.
 *
 * Concurrency (S12): the cell and the batch save accept the amount the browser believes is stored
 * (`expected`). When the stored amount differs (another window or user changed it meanwhile), the cell is not
 * written and is returned as a conflict with the current amount; without `expected` the last write wins.
 */
class BudgetEditorController extends AccountBaseController
{

    const TYPES = ['revenue', 'expense'];

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-planning-budget-editor.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function edit($budget)
    {
        $budget = FinBudget::with('entity')->findOrFail($budget);
        $actuals = new BudgetActuals();

        $accounts = $this->planningAccounts($budget);
        $values = $actuals->budgetByAccountMonth($budget);
        $lastYear = $actuals->actualsByAccountMonth($budget->year - 1, $budget->entity_id);
        $splitIds = $this->splitAccountIds($budget);

        $sections = ['revenue' => [], 'expense' => []];

        foreach ($accounts as $account) {
            $months = [];

            for ($m = 1; $m <= 12; $m++) {
                $months[$m] = (float)($values[$account->id][$m] ?? 0);
            }

            $sections[$account->type][] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'months' => $months,
                'split' => in_array($account->id, $splitIds, true),
                'inactive' => !$account->is_active,
                'last_year' => round(array_sum($lastYear[$account->id] ?? []), 2),
            ];
        }

        $this->budget = $budget;
        $this->sections = $sections;
        $this->lastYearByAccount = $lastYear;
        $this->readonly = $budget->is_approved;
        $this->hasAccounts = count($accounts) > 0;
        $this->listUrl = \Route::has('databackd.planning.budgets.index') ? route('databackd.planning.budgets.index') : null;
        $this->saveUrl = route('databackd.planning.budget-editor.save', $budget->id);
        $this->cellUrl = route('databackd.planning.budget-editor.cell', $budget->id);

        return view('databackd.planning.budget-editor.edit', $this->data);
    }

    /**
     * Upsert the dimension-free lines of the budget (only the posted cells are touched).
     * Expects `cells[account_id][month] = amount` (non-negative, "." or "," as the decimal separator) and
     * optionally `expected[account_id][month]` (see the class comment). Conflicting cells are skipped and
     * returned in `conflicts` [{account_id, month, current}]; the other cells are saved.
     */
    public function save(Request $request, $budget): JsonResponse
    {
        $budget = FinBudget::findOrFail($budget);

        if ($budget->is_approved) {
            return response()->json(Reply::error('databackd-planning-budget-editor.messages.approved'));
        }

        $cells = $request->input('cells');
        $expectedIn = $request->input('expected');
        $expectedIn = is_array($expectedIn) ? $expectedIn : [];

        if (!is_array($cells) || !$cells) {
            return response()->json(Reply::error('databackd-planning-budget-editor.messages.invalid_payload'));
        }

        $editable = $this->editableAccountIds($budget);

        $plan = []; // [account_id => [month => amount]]
        $expected = []; // [account_id => [month => amount]] (only the cells that carry one)
        $invalid = [];

        foreach ($cells as $accountId => $months) {
            $accountId = (int)$accountId;

            if (!in_array($accountId, $editable, true) || !is_array($months)) {
                continue; // unknown, inactive or split accounts are never written
            }

            foreach ($months as $month => $raw) {
                $month = (int)$month;

                if ($month < 1 || $month > 12) {
                    continue;
                }

                $amount = $this->parseAmount($raw);

                if ($amount === null) {
                    $invalid[] = ['account_id' => $accountId, 'month' => $month];
                    continue;
                }

                $plan[$accountId][$month] = $amount;
                $exp = is_array($expectedIn[$accountId] ?? null) ? ($expectedIn[$accountId][$month] ?? null) : null;

                if ($exp !== null && is_scalar($exp) && ($e = $this->parseAmount($exp)) !== null) {
                    $expected[$accountId][$month] = $e;
                }
            }
        }

        if ($invalid) {
            return response()->json(Reply::error('databackd-planning-budget-editor.messages.invalid_cells', null, ['invalid' => $invalid]));
        }

        if (!$plan) {
            return response()->json(Reply::error('databackd-planning-budget-editor.messages.cell_locked'));
        }

        $result = $this->persist($budget, $plan, $expected);
        $message = $result['conflicts'] ? 'databackd-planning-budget-editor.messages.saved_with_conflicts' : 'databackd-planning-budget-editor.messages.saved';

        return response()->json(Reply::successWithData($message, [
            'changed' => $result['changed'],
            'conflicts' => $result['conflicts'],
        ]));
    }

    /**
     * Autosave of one cell. Expects `account_id`, `month` (1-12) and `amount` (same format and rules as the full
     * save: non-negative, empty or 0 = no line) and optionally `expected` (the amount the browser believes is
     * stored; a different stored amount is refused as a conflict with `data.current`). Split and unknown accounts
     * are refused and an approved budget is read-only, exactly as in save().
     */
    public function cell(Request $request, $budget): JsonResponse
    {
        $budget = FinBudget::findOrFail($budget);

        if ($budget->is_approved) {
            return response()->json(Reply::error('databackd-planning-budget-editor.messages.approved'));
        }

        $validator = Validator::make($request->only(['account_id', 'month']), [
            'account_id' => ['required', 'integer', 'min:1'],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        $raw = $request->input('amount');
        $amount = is_scalar($raw) || $raw === null ? $this->parseAmount($raw) : null;

        if ($validator->fails() || $amount === null) {
            return response()->json(Reply::error('databackd-planning-budget-editor.messages.invalid_cell'));
        }

        $accountId = (int)$request->input('account_id');
        $month = (int)$request->input('month');

        if (!in_array($accountId, $this->editableAccountIds($budget), true)) {
            return response()->json(Reply::error('databackd-planning-budget-editor.messages.cell_locked'));
        }

        $expected = [];
        $exp = $request->input('expected');

        if ($exp !== null && is_scalar($exp) && ($e = $this->parseAmount($exp)) !== null) {
            $expected[$accountId][$month] = $e;
        }

        $result = $this->persist($budget, [$accountId => [$month => $amount]], $expected);

        if ($result['conflicts']) {
            return response()->json(Reply::error('databackd-planning-budget-editor.messages.conflict', 'conflict', $result['conflicts'][0]));
        }

        return response()->json(Reply::successWithData('databackd-planning-budget-editor.cell.saved', [
            'account_id' => $accountId, 'month' => $month, 'amount' => $amount, 'changed' => $result['changed'],
        ]));
    }

    /**
     * Writes the planned amounts `[account_id => [month => amount]]` to the dimension-free lines of the budget in
     * one transaction and returns ['changed' => number of changed lines, 'conflicts' => [{account_id, month,
     * current}]]. A cell with an `$expected` amount that differs from the stored one is skipped as a conflict. The budget row is locked first, so concurrent saves
     * of the same budget (several cells, several browser windows) are applied one after another.
     */
    protected function persist(FinBudget $budget, array $plan, array $expected = []): array
    {
        return DB::transaction(function () use ($budget, $plan, $expected) {
            FinBudget::whereKey($budget->id)->lockForUpdate()->first();

            // Existing dimension-free lines of the posted accounts only (dimension lines are never touched)
            $existing = FinBudgetLine::where('budget_id', $budget->id)
                ->whereNull('region_id')->whereNull('product_group_id')->whereNull('cost_center_id')
                ->whereIn('account_id', array_keys($plan))
                ->orderBy('id')
                ->get()
                ->groupBy(fn($line) => $line->account_id . '|' . $line->month);

            $changed = 0;
            $conflicts = [];

            foreach ($plan as $accountId => $months) {
                foreach ($months as $month => $amount) {
                    $lines = $existing->get($accountId . '|' . $month, collect());
                    $line = $lines->first();

                    if (isset($expected[$accountId][$month])) {
                        $current = round((float)$lines->sum('amount'), 2);

                        if (abs($current - $expected[$accountId][$month]) >= 0.005 && abs($current - $amount) >= 0.005) {
                            $conflicts[] = ['account_id' => (int)$accountId, 'month' => (int)$month, 'current' => $current];
                            continue;
                        }
                    }

                    // Duplicates of the same dimension-free cell (NULLs are not covered by the unique index)
                    foreach ($lines->slice(1) as $duplicate) {
                        $duplicate->delete();
                        $changed++;
                    }

                    if ($amount <= 0) {
                        if ($line) {
                            $line->delete();
                            $changed++;
                        }

                        continue;
                    }

                    if ($line) {
                        if (round((float)$line->amount, 2) !== $amount) {
                            $line->amount = $amount;
                            $line->save();
                            $changed++;
                        }

                        continue;
                    }

                    $new = new FinBudgetLine();
                    $new->company_id = $budget->company_id;
                    $new->budget_id = $budget->id;
                    $new->account_id = $accountId;
                    $new->region_id = null;
                    $new->product_group_id = null;
                    $new->cost_center_id = null;
                    $new->month = $month;
                    $new->amount = $amount;
                    $new->save();
                    $changed++;
                }
            }

            if ($changed) {
                $budget->touch();
            }

            return ['changed' => $changed, 'conflicts' => $conflicts];
        });
    }

    /**
     * Revenue and expense accounts of the company ordered by code: the active ones plus the inactive ones that
     * still have lines in this budget (otherwise their amounts would count in the list totals and in "budget vs
     * actual" without being visible or editable anywhere).
     */
    protected function planningAccounts(FinBudget $budget)
    {
        return FinAccount::whereIn('type', self::TYPES)
            ->where(function ($q) use ($budget) {
                $q->where('is_active', 1)
                    ->orWhereIn('id', DB::table('fin_budget_lines')->where('budget_id', $budget->id)->select('account_id'));
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'is_active']);
    }

    /** Accounts whose dimension-free cells can be written. @return int[] */
    protected function editableAccountIds(FinBudget $budget): array
    {
        $ids = $this->planningAccounts($budget)->pluck('id')->map(fn($id) => (int)$id)->all();

        return array_values(array_diff($ids, $this->splitAccountIds($budget)));
    }

    /** Accounts that have at least one dimension-split line in the budget. @return int[] */
    protected function splitAccountIds(FinBudget $budget): array
    {
        return DB::table('fin_budget_lines')
            ->where('budget_id', $budget->id)
            ->where(function ($q) {
                $q->whereNotNull('region_id')->orWhereNotNull('product_group_id')->orWhereNotNull('cost_center_id');
            })
            ->distinct()
            ->pluck('account_id')
            ->map(fn($id) => (int)$id)
            ->all();
    }

    /** Returns a non-negative amount rounded to cents, 0.0 for an empty value, null when the value is invalid. */
    protected function parseAmount($raw): ?float
    {
        if ($raw === null || $raw === '') {
            return 0.0;
        }

        $s = str_replace([' ', "\u{00A0}", "\u{202F}", "'"], '', trim((string)$raw));

        // Both separators ("1.234,56" / "1,234.56"): the last one is the decimal separator
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $decimal = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
            $s = str_replace($decimal === ',' ? '.' : ',', '', $s);
        }

        $s = str_replace(',', '.', $s);

        if ($s === '') {
            return 0.0;
        }

        if (!preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $s)) {
            return null;
        }

        $value = round((float)$s, 2);

        return ($value < 0 || $value > 999999999999.99) ? null : $value;
    }

}
