<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Planned amount of one account for one month (optionally split by region / product group / cost center).
 * `amount` follows the sign convention of FinEntry: always positive, the sign comes from the account type.
 *
 * @property int $id
 * @property int $company_id
 * @property int $budget_id
 * @property int $account_id
 * @property int|null $region_id
 * @property int|null $product_group_id
 * @property int|null $cost_center_id
 * @property int $month 1-12
 * @property float $amount
 */
class FinBudgetLine extends BaseModel
{

    use HasCompany;

    protected $table = 'fin_budget_lines';
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'float', 'month' => 'integer'];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(FinBudget::class, 'budget_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(FinDimension::class, 'region_id');
    }

    public function productGroup(): BelongsTo
    {
        return $this->belongsTo(FinDimension::class, 'product_group_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinDimension::class, 'cost_center_id');
    }

}
