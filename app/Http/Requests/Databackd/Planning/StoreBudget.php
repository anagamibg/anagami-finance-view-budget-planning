<?php

namespace App\Http\Requests\Databackd\Planning;

use App\Http\Requests\CoreRequest;
use App\Models\Databackd\FinBudget;
use Illuminate\Validation\Rule;

class StoreBudget extends CoreRequest
{

    public function authorize()
    {
        return !in_array('client', user_roles());
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'currency' => strtoupper(trim((string)($this->input('currency') ?: FinBudget::baseCurrency()))),
            'adjust_pct' => $this->filled('adjust_pct') ? str_replace(',', '.', (string)$this->input('adjust_pct')) : 0,
        ]);
    }

    public function rules()
    {
        $companyId = company()->id;

        return [
            'name' => ['required', 'string', 'max:191'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'entity_id' => ['nullable', 'integer', Rule::exists('fin_entities', 'id')->where('company_id', $companyId)],
            'scenario' => ['required', Rule::in(FinBudget::SCENARIOS)],
            'currency' => ['required', Rule::in(FinBudget::currencyOptions())],
            'notes' => ['nullable', 'string', 'max:5000'],
            'start_option' => ['required', Rule::in(['empty', 'actuals', 'budget'])],
            'source_year' => ['required_if:start_option,actuals', 'nullable', 'integer', 'between:2000,2100'],
            'adjust_pct' => ['nullable', 'numeric', 'between:-100,1000'],
            'source_budget_id' => [
                'required_if:start_option,budget', 'nullable', 'integer',
                Rule::exists('fin_budgets', 'id')->where('company_id', $companyId),
            ],
        ];
    }

    /**
     * The actual amounts are in the base currency and are not converted: a budget built from them must use it.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $base = FinBudget::baseCurrency();

            if ($this->input('start_option') === 'actuals' && $this->input('currency') !== $base && !$validator->errors()->has('currency')) {
                $validator->errors()->add('currency', __('databackd-planning-budgets.currency_actuals_base', ['currency' => $base]));
            }
        });
    }

    public function attributes()
    {
        return __('databackd-planning-budgets.attributes');
    }

}
