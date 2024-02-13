@extends('layouts.app')

@section('content')
    <div class="content-wrapper dbk-planning-create">
        <x-form id="budget-form" method="POST" class="ajax-form">
            <div class="row">
                <div class="col-lg-6 mb-3">
                    <x-dbk.card :title="__('databackd-planning-budgets.section_main')" class="h-100">
                        <div class="row">
                            <div class="col-12">
                                <x-forms.text fieldId="budget_name" fieldName="name" fieldRequired="true"
                                              :fieldLabel="__('databackd-planning-budgets.name')"
                                              :fieldPlaceholder="__('databackd-planning-budgets.name_placeholder')"
                                              :fieldValue="''"/>
                            </div>
                            <div class="col-md-4">
                                <x-forms.number fieldId="budget_year" fieldName="year" fieldRequired="true"
                                                :fieldLabel="__('databackd-planning-budgets.year')"
                                                :fieldValue="$defaultYear" minValue="2000" maxValue="2100"/>
                            </div>
                            <div class="col-md-8">
                                <x-forms.select fieldId="budget_entity_id" fieldName="entity_id" :search="true"
                                                :fieldLabel="__('databackd-planning-budgets.entity')">
                                    <option value="">@lang('databackd-planning-budgets.whole_group')</option>
                                    @foreach ($entities as $entity)
                                        <option value="{{ $entity->id }}">{{ $entity->name }}</option>
                                    @endforeach
                                </x-forms.select>
                                <p class="dbk-form-hint mb-3">@lang('databackd-planning-budgets.entity_help')</p>
                            </div>
                            <div class="col-md-6">
                                <x-forms.select fieldId="budget_scenario" fieldName="scenario" fieldRequired="true"
                                                :fieldLabel="__('databackd-planning-budgets.scenario')">
                                    @foreach (\App\Models\Databackd\FinBudget::SCENARIOS as $scenario)
                                        <option value="{{ $scenario }}">{{ __('databackd-planning-common.scenario.' . $scenario) }}</option>
                                    @endforeach
                                </x-forms.select>
                            </div>
                            <div class="col-md-6">
                                <x-forms.select fieldId="budget_currency" fieldName="currency" fieldRequired="true"
                                                :fieldLabel="__('databackd-planning-budgets.currency')">
                                    @foreach ($currencies as $code)
                                        <option value="{{ $code }}" @selected($code === $baseCurrency)>{{ $code }}</option>
                                    @endforeach
                                </x-forms.select>
                                <p class="dbk-form-hint mb-3">@lang('databackd-planning-budgets.currency_help_base', ['currency' => $baseCurrency])</p>
                            </div>
                            <div class="col-12">
                                <x-forms.textarea fieldId="budget_notes" fieldName="notes"
                                                  :fieldLabel="__('databackd-planning-budgets.notes')"
                                                  :fieldPlaceholder="__('databackd-planning-budgets.notes_placeholder')"/>
                            </div>
                        </div>
                    </x-dbk.card>
                </div>

                <div class="col-lg-6 mb-3">
                    <x-dbk.card :title="__('databackd-planning-budgets.section_start')" class="h-100">
                        <div class="dbk-start-option active" data-option="empty">
                            <div class="custom-control custom-radio">
                                <input type="radio" class="custom-control-input" id="start_empty" name="start_option" value="empty" checked>
                                <label class="custom-control-label cursor-pointer" for="start_empty">@lang('databackd-planning-budgets.start_empty')</label>
                            </div>
                            <div class="dbk-start-help">@lang('databackd-planning-budgets.start_empty_help')</div>
                        </div>

                        <div class="dbk-start-option" data-option="actuals">
                            <div class="custom-control custom-radio">
                                <input type="radio" class="custom-control-input" id="start_actuals" name="start_option" value="actuals">
                                <label class="custom-control-label cursor-pointer" for="start_actuals">@lang('databackd-planning-budgets.start_actuals')</label>
                            </div>
                            <div class="dbk-start-help">@lang('databackd-planning-budgets.start_actuals_help')</div>
                            <div class="dbk-start-fields">
                                <div class="row">
                                    <div class="col-md-6">
                                        <x-forms.select fieldId="source_year" fieldName="source_year"
                                                        :fieldLabel="__('databackd-planning-budgets.source_year')">
                                            @foreach ($sourceYears as $y)
                                                <option value="{{ $y }}" @selected($y == $defaultSourceYear)>{{ $y }}</option>
                                            @endforeach
                                        </x-forms.select>
                                    </div>
                                    <div class="col-md-6">
                                        <x-forms.number fieldId="adjust_pct" fieldName="adjust_pct"
                                                        :fieldLabel="__('databackd-planning-budgets.adjust_pct')"
                                                        fieldValue="5" minValue="-100" maxValue="1000"/>
                                    </div>
                                </div>
                                <p class="dbk-form-hint mt-n2 mb-2">@lang('databackd-planning-budgets.adjust_pct_help')</p>
                                <div class="custom-control custom-checkbox mt-2">
                                    <input type="checkbox" class="custom-control-input" id="fill_gaps" name="fill_gaps" value="1" checked>
                                    <label class="custom-control-label cursor-pointer f-14" for="fill_gaps">@lang('databackd-planning-budgets.fill_gaps')</label>
                                </div>
                                <p class="dbk-form-hint mb-0">@lang('databackd-planning-budgets.fill_gaps_help')</p>
                            </div>
                        </div>

                        <div class="dbk-start-option" data-option="budget">
                            <div class="custom-control custom-radio">
                                <input type="radio" class="custom-control-input" id="start_budget" name="start_option" value="budget"
                                       @disabled($budgetOptions->isEmpty())>
                                <label class="custom-control-label cursor-pointer" for="start_budget">@lang('databackd-planning-budgets.start_budget')</label>
                            </div>
                            <div class="dbk-start-help">
                                {{ $budgetOptions->isEmpty() ? __('databackd-planning-budgets.no_budgets_to_copy') : __('databackd-planning-budgets.start_budget_help') }}
                            </div>
                            @if ($budgetOptions->isNotEmpty())
                                <div class="dbk-start-fields">
                                    <x-forms.select fieldId="source_budget_id" fieldName="source_budget_id" :search="true"
                                                    :fieldLabel="__('databackd-planning-budgets.source_budget')">
                                        @foreach ($budgetOptions as $option)
                                            <option value="{{ $option->id }}">
                                                {{ $option->name }} · {{ $option->year }} · {{ $option->entity->name ?? __('databackd-planning-budgets.whole_group') }}
                                            </option>
                                        @endforeach
                                    </x-forms.select>
                                </div>
                            @endif
                        </div>
                    </x-dbk.card>
                </div>
            </div>

            <div class="dbk-planning-form-actions">
                <x-forms.link-secondary :link="route('databackd.planning.budgets.index')" class="mr-3">
                    @lang('databackd-planning-budgets.back')
                </x-forms.link-secondary>
                <x-forms.button-primary id="save-budget" icon="check">@lang('databackd-planning-budgets.save')</x-forms.button-primary>
            </div>
        </x-form>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            $('.select-picker').selectpicker('refresh');

            $('input[name=start_option]').on('change', function () {
                $('.dbk-start-option').removeClass('active');
                $(this).closest('.dbk-start-option').addClass('active');
            });

            $('.dbk-start-option').on('click', function (e) {
                if ($(e.target).closest('.dbk-start-fields, input, label').length) {
                    return;
                }

                var radio = $(this).find('input[name=start_option]');

                if (!radio.prop('disabled')) {
                    radio.prop('checked', true).trigger('change');
                }
            });

            $('#save-budget').click(function () {
                $.easyAjax({
                    url: "{{ route('databackd.planning.budgets.store') }}",
                    container: '#budget-form',
                    type: "POST",
                    blockUI: true,
                    disableButton: true,
                    buttonSelector: "#save-budget",
                    data: $('#budget-form').serialize()
                });
            });
        });
    </script>
@endpush
