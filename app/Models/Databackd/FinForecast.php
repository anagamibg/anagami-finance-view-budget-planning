<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Models\User;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saved forecast definition (what to forecast and how); the numbers are recomputed on open.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $metric revenue|expense|profit|cash|account|product_group
 * @property string|null $metric_ref account code or fin_dimensions.id, depending on the metric
 * @property int|null $entity_id
 * @property string $method linear|moving_average|seasonal|manual
 * @property array|null $params
 * @property int $horizon months
 * @property array|null $scenarios scenario parameters, e.g. {optimistic: {revenue_growth: 5, expense_growth: 0}, ...}
 * @property int|null $created_by
 */
class FinForecast extends BaseModel
{

    use HasCompany;

    const METHODS = ['linear', 'moving_average', 'seasonal', 'manual'];
    const METRICS = ['revenue', 'expense', 'profit', 'cash', 'account', 'product_group'];

    protected $table = 'fin_forecasts';
    protected $guarded = ['id'];
    protected $casts = ['params' => 'array', 'scenarios' => 'array', 'horizon' => 'integer'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(FinEntity::class, 'entity_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}
