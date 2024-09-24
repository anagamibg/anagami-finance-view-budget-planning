@extends('layouts.app')

@php
    // real minus sign and "12,4 %" (ui-guidelines §6)
    $money = fn($value) => ((float)$value < 0 ? '−' : '') . number_format(abs((float)$value), 2, ',', "\u{00A0}");
    $signed = fn($value) => ($value > 0 ? '+' : '') . $money($value);
    $pct = fn($value) => $value === null ? '–' : (($value > 0 ? '+' : ($value < 0 ? '−' : '')) . number_format(abs((float)$value), 1, ',', "\u{00A0}") . "\u{00A0}%");
    $months = trans('databackd-planning-common.months');
@endphp

@section('content')
    <div class="content-wrapper dbk-planning-variance">

        <x-dbk.page-header :subtitle="__('databackd-planning-variance.subtitle')">
            @if ($report)
                <x-slot name="actions">
                    <x-forms.link-secondary :link="$exportUrl" icon="file-excel">@lang('databackd-planning-variance.export_excel')</x-forms.link-secondary>
                </x-slot>
            @endif
        </x-dbk.page-header>

        @if (!$report)
            <div class="dbk-card mb-3">
                <x-dbk.empty icon="bar-chart-line" :title="__('databackd-planning-variance.empty.title')" :text="__('databackd-planning-variance.empty.text')">
                    @if ($budgetsUrl)
                        <x-forms.link-primary :link="$budgetsUrl" icon="plus">@lang('databackd-planning-variance.empty.link')</x-forms.link-primary>
                    @endif
                </x-dbk.empty>
            </div>
        @else
            {{-- Filters --}}
            <form method="GET" action="{{ route('databackd.planning.variance.index') }}" id="variance-form" class="dbk-filters">
                <div class="dbk-filters-row">
                    <div class="dbk-filter dbk-filter-wide" style="max-width: 360px; flex-basis: 280px">
                        <label>@lang('databackd-planning-variance.filters.budget')</label>
                        <select name="budget_id" class="form-control select-picker" data-size="8" data-live-search="true">
                            @foreach ($budgets as $item)
                                <option value="{{ $item->id }}" @selected($item->id === $budget->id)>
                                    @lang('databackd-planning-variance.budget_label', ['name' => $item->name, 'year' => $item->year, 'version' => __('databackd-planning-common.version.' . $item->version)])
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="dbk-filter">
                        <label>@lang('databackd-planning-variance.filters.mode')</label>
                        <select name="mode" id="variance-mode" class="form-control select-picker" data-size="6">
                            @foreach (\App\Http\Controllers\Databackd\Planning\BudgetVarianceController::MODES as $m)
                                <option value="{{ $m }}" @selected($mode === $m)>@lang('databackd-planning-variance.modes.' . $m)</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="dbk-filter">
                        <label>@lang('databackd-planning-variance.filters.month')</label>
                        <select name="month" id="variance-month" class="form-control select-picker" data-size="6">
                            @foreach ($months as $number => $name)
                                <option value="{{ $number }}" @selected($month === $number)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="dbk-filter">
                        <label>@lang('databackd-planning-variance.filters.entity')</label>
                        <div class="dbk-planning-readonly-field" title="{{ $budget->entity->name ?? __('databackd-planning-common.all_entities') }}">
                            {{ $budget->entity->name ?? __('databackd-planning-common.all_entities') }}
                        </div>
                    </div>

                    @foreach (\App\Http\Controllers\Databackd\Planning\BudgetVarianceController::DIMENSIONS as $param => $dimType)
                        <div class="dbk-filter">
                            <label>@lang('databackd-planning-variance.filters.' . $dimType)</label>
                            <select name="{{ $param }}" class="form-control select-picker" data-size="8" data-live-search="true">
                                <option value="">@lang('databackd-planning-variance.filters.all')</option>
                                @foreach ($dimensions->get($dimType, collect()) as $dimension)
                                    <option value="{{ $dimension->id }}" @selected(($selected[$param] ?? null) == $dimension->id)>{{ $dimension->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach

                    <div class="dbk-filters-actions">
                        <x-forms.link-secondary :link="route('databackd.planning.variance.index')" icon="times-circle">@lang('databackd-planning-variance.filters.reset')</x-forms.link-secondary>
                        <x-forms.button-primary type="submit" icon="filter">@lang('databackd-planning-variance.filters.apply')</x-forms.button-primary>
                    </div>
                </div>
            </form>

            <p class="dbk-planning-caption">{{ $report['caption'] }}</p>

            @foreach ($report['notices'] as $notice)
                <div @class(['dbk-planning-notice dbk-variance-notice', 'is-warning' => $notice['type'] === 'warning']) role="status">
                    <i class="fa {{ $notice['type'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle' }}" aria-hidden="true"></i><span>{{ $notice['text'] }}</span>
                </div>
            @endforeach

            {{-- KPI cards --}}
            <div class="dbk-kpi-grid dbk-kpi-grid--3">
                @foreach (['revenue' => 'bi-graph-up-arrow', 'expense' => 'bi-receipt', 'profit' => 'bi-piggy-bank'] as $type => $icon)
                    @php $k = $report['kpi'][$type]; @endphp
                    <x-dbk.kpi :label="__('databackd-planning-common.' . $type)"
                               :value="$money($k['actual']) . ' ' . $baseCurrency"
                               :icon="$icon" :tone="$type"
                               :change="$k['diff_pct']" change-unit="%" :good="$type === 'expense' ? 'down' : 'up'"
                               :hint="$signed($k['diff']) . ' · ' . __('databackd-planning-variance.kpi.budget') . ': ' . $money($k['budget'])"/>
                @endforeach
            </div>

            <div class="row">
                {{-- Chart: actual bars over a wide ghost bar of the budget (bullet chart) --}}
                <div class="col-xl-8 mb-3">
                    <x-dbk.card :title="__('databackd-planning-variance.chart.title')" class="h-100">
                        <x-slot name="actions">
                            <div class="dbk-segmented" role="group" id="variance-chart-switch">
                                @foreach (['revenue', 'expense', 'profit'] as $type)
                                    <button type="button" data-type="{{ $type }}" class="{{ $loop->first ? 'active' : '' }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">@lang('databackd-planning-common.' . $type)</button>
                                @endforeach
                            </div>
                        </x-slot>
                        <x-dbk.chart id="variance-chart" size="lg" :label="__('databackd-planning-variance.chart.title')"/>
                        <p class="dbk-variance-chart-hint">@lang('databackd-planning-variance.chart.hint')</p>
                    </x-dbk.card>
                </div>

                {{-- Variance bars: the accounts with the largest deviation, green = better than plan, red = worse --}}
                <div class="col-xl-4 mb-3">
                    <x-dbk.card :title="__('databackd-planning-variance.top.title')" :subtitle="__('databackd-planning-variance.top.subtitle')" class="h-100">
                        <x-dbk.chart id="variance-top-chart" size="lg" :label="__('databackd-planning-variance.top.title')"/>
                        <div class="dbk-variance-top-legend" aria-hidden="true">
                            <span><i class="is-good"></i>@lang('databackd-planning-variance.legend.favorable')</span>
                            <span><i class="is-bad"></i>@lang('databackd-planning-variance.legend.unfavorable')</span>
                        </div>
                    </x-dbk.card>
                </div>
            </div>

            {{-- Table --}}
            <div class="dbk-card mb-3">
                <div class="dbk-table-wrap is-flush">
                    <table class="dbk-table dbk-table--compact dbk-table--sticky-first variance-table">
                        <thead>
                            <tr>
                                <th>@lang('databackd-planning-variance.columns.account')</th>
                                <th class="num">@lang('databackd-planning-variance.columns.budget')</th>
                                <th class="num">@lang('databackd-planning-variance.columns.actual')</th>
                                <th class="num">@lang('databackd-planning-variance.columns.diff')</th>
                                <th class="num">@lang('databackd-planning-variance.columns.diff_pct')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['table'] as $row)
                                @if ($row['kind'] === 'section')
                                    <tr class="v-section dbk-row-group"><td colspan="5">{{ $row['label'] }}</td></tr>
                                @else
                                    @php $good = $row['favorable']; @endphp
                                    <tr @class(['v-' . $row['kind'], 'dbk-row-total' => $row['kind'] !== 'row', 'is-profit' => $row['kind'] === 'profit'])>
                                        <td>
                                            @if ($row['code'] !== '')
                                                <span class="dbk-planning-code">{{ $row['code'] }}</span>
                                            @endif
                                            {{ $row['label'] }}
                                        </td>
                                        <td class="num">{{ $money($row['budget']) }}</td>
                                        <td class="num">{{ $money($row['actual']) }}</td>
                                        <td class="num {{ $good ? 'dbk-text-good' : 'dbk-text-bad' }}">{{ $signed($row['diff']) }}</td>
                                        <td class="num">
                                            @if ($row['diff_pct'] === null)
                                                <span class="dbk-text-muted f-12">{{ (float)$row['budget'] == 0 && (float)$row['actual'] != 0 ? __('databackd-planning-variance.no_budget') : '–' }}</span>
                                            @else
                                                <span class="dbk-badge no-dot {{ $good ? 'is-good' : 'is-bad' }}">
                                                    <i class="bi {{ $row['diff_pct'] > 0 ? 'bi-arrow-up-short' : ($row['diff_pct'] < 0 ? 'bi-arrow-down-short' : 'bi-dash') }}" aria-hidden="true"></i>{{ $pct($row['diff_pct']) }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                            @if (empty($report['rows']))
                                <tr><td colspan="5" class="text-center dbk-text-muted py-4">@lang('databackd-planning-variance.no_rows')</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="dbk-planning-legend">
                <span class="dbk-badge is-good">@lang('databackd-planning-variance.legend.favorable')</span>
                <span class="dbk-badge is-bad">@lang('databackd-planning-variance.legend.unfavorable')</span>
                <span>@lang('databackd-planning-variance.legend.hint')</span>
            </div>
        @endif

    </div>
@endsection

@if ($report)
    @push('scripts')
        <script>
            $(function () {
                var chartData = @json($report['chart']);
                var top = @json($report['top']);
                var labels = {
                    budget: @json(__('databackd-planning-variance.chart.budget')),
                    actual: @json(__('databackd-planning-variance.chart.actual')),
                    diff: @json(__('databackd-planning-variance.columns.diff')),
                    topEmpty: @json(__('databackd-planning-variance.top.empty'))
                };
                var currency = @json($baseCurrency);

                function money(v) {
                    return dbkChart.fmt.number(v, 2) + ' ' + currency;
                }

                // Variance bars (horizontal): deviation per account, coloured by favourable / unfavourable
                function renderTop() {
                    if (!top.length) {
                        dbkChart.empty('variance-top-chart', labels.topEmpty);
                        return;
                    }

                    dbkChart.bar('variance-top-chart', {
                        horizontal: true,
                        legend: false,
                        // short labels (the full name is in the tooltip title): Chart.js clips long ones on narrow cards
                        labels: top.map(function (r) { return r.label.length > 20 ? r.label.slice(0, 19) + '…' : r.label; }),
                        datasets: [{
                            label: labels.diff,
                            data: top.map(function (r) { return r.diff; }),
                            colors: top.map(function (r) { return dbkChart.role(r.favorable ? 'good' : 'bad'); })
                        }],
                        unit: 'number',
                        options: {
                            layout: {padding: {left: 4}},
                            scales: {x: {ticks: {maxTicksLimit: 4, maxRotation: 0}}},
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        title: function (items) {
                                            return items.length ? top[items[0].dataIndex].label : '';
                                        },
                                        label: function (ctx) {
                                            var r = top[ctx.dataIndex];
                                            var pct = r.diff_pct === null ? '' : ' (' + (r.diff_pct > 0 ? '+' : '') + dbkChart.fmt.number(r.diff_pct, 1) + ' %)';
                                            return ' ' + labels.diff + ': ' + (r.diff > 0 ? '+' : '') + money(r.diff) + pct;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // Bullet chart: the budget is a wide light "ghost" bar, the actual a narrower bar drawn on top of it.
                function render(type) {
                    var series = chartData[type] || {actual: [], budget: []};

                    dbkChart.bar('variance-chart', {
                        labels: chartData.labels,
                        unit: 'money',
                        legend: true,
                        datasets: [
                            {label: labels.actual, data: series.actual, role: type, grouped: false, barPercentage: 0.5, categoryPercentage: 0.8, maxBarThickness: 18, order: 1},
                            {label: labels.budget, data: series.budget, color: dbkChart.alpha(dbkChart.role('budget'), 0.45), grouped: false, barPercentage: 0.9, categoryPercentage: 0.8, maxBarThickness: 40, order: 2}
                        ],
                        options: {interaction: {mode: 'index', intersect: false}}
                    });
                }

                render('revenue');
                renderTop();

                $('#variance-chart-switch button').on('click', function () {
                    $('#variance-chart-switch button').removeClass('active').attr('aria-pressed', 'false');
                    $(this).addClass('active').attr('aria-pressed', 'true');
                    render($(this).data('type'));
                });

                function syncMonth() {
                    var disabled = $('#variance-mode').val() === 'year';
                    $('#variance-month').prop('disabled', disabled).selectpicker('refresh');
                }

                $('#variance-mode').on('change', syncMonth);
                syncMonth();
            });
        </script>
    @endpush
@endif
