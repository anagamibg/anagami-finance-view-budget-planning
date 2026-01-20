<?php

namespace Database\Seeders\Databackd\Demo;

use App\Models\Company;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinBudget;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinForecast;
use App\Models\User;
use App\Scopes\ActiveScope;
use App\Scopes\CompanyScope;
use App\Services\Databackd\Planning\BudgetActuals;
use Carbon\Carbon;
use Database\Seeders\Databackd\DatabackdDemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data of the "Planning" package: approved 2026 base budgets (one per legal entity + one consolidated
 * group budget), a draft optimistic 2027 budget and three saved forecasts.
 *
 * How the 2026 budget lines are built (revenue and expense accounts only, no dimensions):
 *  - base(month) = actual of the same month of 2025 x 1.08. Actuals of 2025 exist only for May-Dec.
 *  - Jan-Apr 2026 have no 2025 counterpart: base = average of the entity's available 2025 months of the same
 *    account x 1.08 x a seasonal factor (winter dip: Jan 0.80, Feb 0.90, Mar 1.06, Apr 1.02, relative to the average
 *    seasonality of May-Dec). Only the seasonal accounts (702, 703, 704 revenue; 601, 602 variable costs) get the
 *    factor; payroll, social security, depreciation, interest and the rest stay flat.
 *  - "realism" is applied on the budget side (actual data is fixed): budget_line = base x (1 + noise) with a
 *    deterministic, seeded noise in [-12%, +12%] per (entity, account, month), a few hand-picked deviations
 *    (service revenue planned too high in some months => actual under plan; external services planned too low in
 *    some months => actual over plan), then renormalised so the yearly sum of every account equals the target
 *    (sum of base) - i.e. the annual plan stays within ~1% of 2025 x 1.08.
 *  - the group budget (entity_id = null) = sum of the entity budgets per account and month.
 *  - the 2027 draft = group 2026 lines x 1.15 (revenue) / x 1.07 (expense).
 *
 * Idempotent: all budgets, budget lines and forecasts of the company are removed and rebuilt.
 */
class PlanningDemoSeeder extends Seeder
{

    const BASE_GROWTH = 1.08;
    const NOISE = 0.12;

    /** Seasonality by calendar month (the same curve the S1 demo model uses for actuals). */
    const SEASON = [1 => 0.80, 2 => 0.90, 3 => 1.06, 4 => 1.02, 5 => 1.00, 6 => 1.10, 7 => 0.98, 8 => 0.86, 9 => 1.04, 10 => 1.08, 11 => 1.05, 12 => 1.12];

    /** Accounts whose Jan-Apr base follows the seasonality (the others are flat). */
    const SEASONAL_CODES = ['601', '602', '702', '703', '704'];

    /** Revenue planned too high => actual below plan (code => months, noise). */
    const REVENUE_MISSES = ['703' => [[3, 7, 10], 0.09]];

    /** Expense planned too low => actual over plan (code => months, noise). */
    const EXPENSE_OVERRUNS = ['602' => [[3, 6, 8], -0.11]];

    const ADMIN_APPROVED_AT = '2026-01-15 10:00:00';
    const LAST_ACTUAL_MONTH = 10;

    protected int $companyId = 0;
    protected ?int $adminId = null;

    /** @var array<int, FinAccount> id => account */
    protected array $accounts = [];

    public function run(Company $company)
    {
        $this->companyId = $company->id;
        mt_srand(4426);

        $this->adminId = User::withoutGlobalScopes([CompanyScope::class, ActiveScope::class])
            ->where('company_id', $this->companyId)->where('email', DatabackdDemoSeeder::ADMIN_EMAIL)->value('id');

        $this->accounts = FinAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)->whereIn('type', ['revenue', 'expense'])
            ->orderBy('code')->get()->keyBy('id')->all();

        $entities = FinEntity::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)->where('is_active', true)
            ->orderBy('sort')->orderBy('id')->get();

        if (!$this->adminId || $entities->isEmpty() || empty($this->accounts)) {
            throw new \RuntimeException('PlanningDemoSeeder needs the S1 demo data (admin user, legal entities, accounts) - run FinModelDemoSeeder first.');
        }

        $this->cleanup();

        $actuals = new BudgetActuals($this->companyId);
        $group = []; // [account_id => [month => amount]]
        $entityBudgets = [];
        $lineCount = 0;

        DB::transaction(function () use ($entities, $actuals, &$group, &$entityBudgets, &$lineCount) {
            // 1. Approved 2026 base budget per legal entity
            foreach ($entities as $entity) {
                $lines = $this->entityLines($actuals, $entity->id);
                $budget = $this->createBudget([
                    'entity_id' => $entity->id,
                    'name' => 'Бюджет 2026 – ' . $entity->name,
                    'year' => 2026,
                    'scenario' => 'base',
                    'version' => 'approved',
                    'notes' => 'Базов бюджет за 2026 г., изграден от реалните данни за 2025 г. с ръст 8%. Одобрен от управителя.',
                ], true);

                $lineCount += $this->insertLines($budget, $lines);
                $entityBudgets[$entity->id] = $budget;

                foreach ($lines as $accountId => $months) {
                    foreach ($months as $month => $amount) {
                        $group[$accountId][$month] = round(($group[$accountId][$month] ?? 0) + $amount, 2);
                    }
                }
            }

            // 2. Consolidated group budget (sum of the entity budgets)
            $groupBudget = $this->createBudget([
                'entity_id' => null,
                'name' => 'Консолидиран бюджет 2026 – Демо Финанс Груп',
                'year' => 2026,
                'scenario' => 'base',
                'version' => 'approved',
                'notes' => 'Консолидиран бюджет на групата - сума от бюджетите на юридическите лица за 2026 г.',
            ], true);
            $lineCount += $this->insertLines($groupBudget, $group);

            // 3. Draft optimistic 2027 budget
            $optimistic = [];

            foreach ($group as $accountId => $months) {
                $factor = $this->accounts[$accountId]->type === 'revenue' ? 1.15 : 1.07;

                foreach ($months as $month => $amount) {
                    $optimistic[$accountId][$month] = round($amount * $factor, 2);
                }
            }

            $budget2027 = $this->createBudget([
                'entity_id' => null,
                'name' => 'Бюджет 2027 – оптимистичен',
                'year' => 2027,
                'scenario' => 'optimistic',
                'version' => 'draft',
                'notes' => 'Чернова: консолидиран бюджет 2026 с ръст 15% на приходите и 7% на разходите (нови клиенти, по-бавен ръст на разходите). Предстои обсъждане.',
            ], false);
            $lineCount += $this->insertLines($budget2027, $optimistic);

            $this->groupBudgetId = $groupBudget->id;
            $this->entityBudgetIds = array_map(fn($b) => $b->id, $entityBudgets);
            $this->budget2027Id = $budget2027->id;
        });

        $forecasts = $this->forecasts();

        if ($this->command) {
            $this->summary(count($entityBudgets) + 2, $lineCount, $forecasts);
        }
    }

    public ?int $groupBudgetId = null;
    public array $entityBudgetIds = [];
    public ?int $budget2027Id = null;

    // ---------------------------------------------------------------------------------------------

    protected function cleanup(): void
    {
        DB::table('fin_budget_lines')->where('company_id', $this->companyId)->delete();
        FinBudget::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->delete();
        FinForecast::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->delete();
    }

    protected function createBudget(array $data, bool $approved): FinBudget
    {
        $budget = new FinBudget();
        $budget->forceFill($data + [
            'company_id' => $this->companyId,
            'currency' => 'EUR',
            'created_by' => $this->adminId,
            'approved_by' => $approved ? $this->adminId : null,
            'approved_at' => $approved ? Carbon::parse(self::ADMIN_APPROVED_AT) : null,
        ]);
        $budget->save();

        return $budget;
    }

    /**
     * 2026 budget lines of one legal entity: [account_id => [month => amount]].
     */
    protected function entityLines(BudgetActuals $actuals, int $entityId): array
    {
        $a2025 = $actuals->actualsByAccountMonth(2025, $entityId);
        $a2026 = $actuals->actualsByAccountMonth(2026, $entityId);
        $meanSeason = array_sum(array_intersect_key(self::SEASON, array_flip(range(5, 12)))) / 8;
        $lines = [];

        foreach ($this->accounts as $accountId => $account) {
            $hist = array_filter($a2025[$accountId] ?? [], fn($v) => $v > 0);

            if (empty($hist)) {
                // no 2025 data: fall back on the average of the 2026 actuals (kept flat), if any
                $hist2026 = array_filter($a2026[$accountId] ?? [], fn($v) => $v > 0);

                if (empty($hist2026)) {
                    continue;
                }

                $avg = array_sum($hist2026) / count($hist2026);
                $base = array_fill(1, 12, $avg);
            } else {
                $avg = array_sum($hist) / count($hist);
                $seasonal = in_array($account->code, self::SEASONAL_CODES, true);
                $base = [];

                for ($m = 1; $m <= 12; $m++) {
                    if (isset($hist[$m])) {
                        $base[$m] = $hist[$m] * self::BASE_GROWTH;
                    } else {
                        $base[$m] = $avg * self::BASE_GROWTH * ($seasonal ? self::SEASON[$m] / $meanSeason : 1);
                    }
                }
            }

            $lines[$accountId] = $this->withNoise($account->code, $account->type, $base);
        }

        return $lines;
    }

    /**
     * Applies the deterministic noise and renormalises the yearly sum to the sum of the base.
     */
    protected function withNoise(string $code, string $type, array $base): array
    {
        $target = array_sum($base);
        $noisy = [];

        for ($m = 1; $m <= 12; $m++) {
            // triangular distribution within +-NOISE: most months are close to the base
            $noise = (mt_rand(-10000, 10000) + mt_rand(-10000, 10000)) / 20000 * self::NOISE;

            if ($type === 'revenue' && isset(self::REVENUE_MISSES[$code]) && in_array($m, self::REVENUE_MISSES[$code][0], true)) {
                $noise = self::REVENUE_MISSES[$code][1] + mt_rand(-100, 100) / 10000;
            }

            if ($type === 'expense' && isset(self::EXPENSE_OVERRUNS[$code]) && in_array($m, self::EXPENSE_OVERRUNS[$code][0], true)) {
                $noise = self::EXPENSE_OVERRUNS[$code][1] + mt_rand(-100, 100) / 10000;
            }

            $noisy[$m] = $base[$m] * (1 + $noise);
        }

        $sum = array_sum($noisy);
        $k = $sum > 0 ? $target / $sum : 1;
        $out = [];

        foreach ($noisy as $m => $v) {
            $out[$m] = round($v * $k, 2);
        }

        return $out;
    }

    protected function insertLines(FinBudget $budget, array $lines): int
    {
        $rows = [];
        $now = now();

        foreach ($lines as $accountId => $months) {
            foreach ($months as $month => $amount) {
                if ($amount <= 0) {
                    continue;
                }

                $rows[] = [
                    'company_id' => $this->companyId,
                    'budget_id' => $budget->id,
                    'account_id' => $accountId,
                    'region_id' => null,
                    'product_group_id' => null,
                    'cost_center_id' => null,
                    'month' => $month,
                    'amount' => $amount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('fin_budget_lines')->insert($chunk);
        }

        return count($rows);
    }

    protected function forecasts(): int
    {
        $defs = [
            ['Прогноза приходи – сезонност', 'revenue', 'seasonal', 6, null],
            ['Прогноза разходи – линеен тренд', 'expense', 'linear', 6, null],
            ['Парични средства – сценарии', 'cash', 'linear', 12, [
                'base' => ['revenue_growth' => 0, 'expense_growth' => 0],
                'optimistic' => ['revenue_growth' => 10, 'expense_growth' => 2],
                'pessimistic' => ['revenue_growth' => -10, 'expense_growth' => 5],
            ]],
        ];

        foreach ($defs as [$name, $metric, $method, $horizon, $scenarios]) {
            $forecast = new FinForecast();
            $forecast->forceFill([
                'company_id' => $this->companyId,
                'name' => $name,
                'metric' => $metric,
                'metric_ref' => null,
                'entity_id' => null,
                'method' => $method,
                'params' => [],
                'horizon' => $horizon,
                'scenarios' => $scenarios,
                'created_by' => $this->adminId,
            ]);
            $forecast->save();
        }

        return count($defs);
    }

    protected function summary(int $budgets, int $lines, int $forecasts): void
    {
        $this->command->info("fin_budgets: $budgets, fin_budget_lines: $lines, fin_forecasts: $forecasts");

        $budget = FinBudget::withoutGlobalScope(CompanyScope::class)->find($this->groupBudgetId);
        $rows = (new BudgetActuals($this->companyId))->variance($budget, 1, self::LAST_ACTUAL_MONTH);
        $sum = ['revenue' => ['budget' => 0, 'actual' => 0], 'expense' => ['budget' => 0, 'actual' => 0]];

        foreach ($rows as $r) {
            $sum[$r['type']]['budget'] += $r['budget'];
            $sum[$r['type']]['actual'] += $r['actual'];
        }

        $fmt = fn($v) => number_format($v, 0, '.', ' ');
        $pb = $sum['revenue']['budget'] - $sum['expense']['budget'];
        $pa = $sum['revenue']['actual'] - $sum['expense']['actual'];

        $this->command->info(sprintf(
            'Group 2026 budget #%d, Jan-Oct (budget / actual): revenue %s / %s, expense %s / %s, profit %s / %s',
            $budget->id,
            $fmt($sum['revenue']['budget']), $fmt($sum['revenue']['actual']),
            $fmt($sum['expense']['budget']), $fmt($sum['expense']['actual']),
            $fmt($pb), $fmt($pa)
        ));
    }

}
