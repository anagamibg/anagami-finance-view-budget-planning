<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Models\User;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Annual budget (one scenario/version) of a legal entity or of the whole group (entity_id = null).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $entity_id
 * @property string $name
 * @property int $year
 * @property string $version draft|approved
 * @property string $scenario base|optimistic|pessimistic
 * @property string $currency
 * @property string|null $notes
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $created_by
 */
class FinBudget extends BaseModel
{

    use HasCompany;

    const VERSIONS = ['draft', 'approved'];
    const SCENARIOS = ['base', 'optimistic', 'pessimistic'];

    protected $table = 'fin_budgets';
    protected $guarded = ['id'];
    protected $casts = ['approved_at' => 'datetime', 'year' => 'integer'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(FinEntity::class, 'entity_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinBudgetLine::class, 'budget_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getIsApprovedAttribute(): bool
    {
        return $this->version === 'approved';
    }

    /**
     * Base currency of the current company: the currency of all actual amounts (`fin_entries.amount_base`).
     */
    public static function baseCurrency(): string
    {
        $code = company()?->currency?->currency_code;

        return $code ? strtoupper($code) : 'EUR';
    }

    /**
     * Currency codes a budget may use: the base currency first, then the other currencies of the company.
     *
     * @return string[]
     */
    public static function currencyOptions(): array
    {
        $codes = \App\Models\Currency::orderBy('currency_code')->pluck('currency_code')
            ->map(fn($c) => strtoupper(trim((string)$c)))->filter(fn($c) => preg_match('/^[A-Z]{3}$/', $c))->all();

        return array_values(array_unique(array_merge([self::baseCurrency()], $codes)));
    }

}
