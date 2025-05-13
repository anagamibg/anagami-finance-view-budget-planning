@extends('layouts.app')

@section('content')
    @php
        $metricValue = $def['metric'] . ($def['metric_ref'] !== null ? ':' . $def['metric_ref'] : '');
        $generalMetrics = ['revenue', 'expense', 'profit', 'cash'];
    @endphp

    <div class="content-wrapper dbk-planning-forecasts">
        <x-dbk.page-header :subtitle="__('databackd-planning-forecasts.help')"/>

        <!-- CONTROLS START -->
        <div class="dbk-filters">
            <div class="dbk-filters-row">
                <div class="dbk-filter dbk-filter-wide" id="metric-col">
                    <label>@lang('databackd-planning-forecasts.metric')</label>
                    <select class="form-control select-picker" id="f-metric" data-live-search="true" data-size="10">
                        <optgroup label="{{ __('databackd-planning-forecasts.group_general') }}">
                            @foreach ($generalMetrics as $m)
                                <option value="{{ $m }}" @selected($metricValue === $m)>{{ __('databackd-planning-common.metric.' . $m) }}</option>
                            @endforeach
                        </optgroup>
                        @if ($accounts->count())
                            <optgroup label="{{ __('databackd-planning-forecasts.group_accounts') }}">
                                @foreach ($accounts as $a)
                                    <option value="account:{{ $a->code }}" @selected($metricValue === 'account:' . $a->code)>{{ $a->code }} — {{ $a->name }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if ($productGroups->count())
                            <optgroup label="{{ __('databackd-planning-forecasts.group_product_groups') }}">
                                @foreach ($productGroups as $g)
                                    <option value="product_group:{{ $g->id }}" @selected($metricValue === 'product_group:' . $g->id)>{{ $g->name }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                </div>

                <div class="dbk-filter">
                    <label>@lang('databackd-planning-forecasts.entity')</label>
                    <select class="form-control select-picker" id="f-entity" data-size="8">
                        <option value="">@lang('databackd-planning-common.all_entities')</option>
                        @foreach ($entities as $e)
                            <option value="{{ $e->id }}" @selected((int)$def['entity_id'] === (int)$e->id)>{{ $e->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="dbk-filter">
                    <label>@lang('databackd-planning-forecasts.horizon')</label>
                    <select class="form-control select-picker" id="f-horizon">
                        @foreach (\App\Http\Controllers\Databackd\Planning\ForecastController::HORIZONS as $h)
                            <option value="{{ $h }}" @selected($def['horizon'] === $h)>@lang('databackd-planning-forecasts.horizon_months', ['n' => $h])</option>
                        @endforeach
                    </select>
                </div>

                <div class="dbk-filter">
                    <label>@lang('databackd-planning-forecasts.method')</label>
                    <select class="form-control select-picker" id="f-method">
                        @foreach (\App\Models\Databackd\FinForecast::METHODS as $m)
                            <option value="{{ $m }}" @selected($def['method'] === $m)>{{ __('databackd-planning-common.method.' . $m) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="dbk-filter {{ $def['method'] === 'moving_average' ? '' : 'd-none' }}" id="extra-window">
                    <label>@lang('databackd-planning-forecasts.window')</label>
                    <select class="form-control select-picker" id="f-window">
                        @foreach ([3, 6] as $w)
                            <option value="{{ $w }}" @selected($def['window'] === $w)>@lang('databackd-planning-forecasts.window_option', ['n' => $w])</option>
                        @endforeach
                    </select>
                </div>

                <div class="dbk-filter {{ $def['method'] === 'manual' ? '' : 'd-none' }}" id="extra-growth">
                    <label>@lang('databackd-planning-forecasts.growth_pct')</label>
                    <input type="number" step="0.1" min="-50" max="50" class="form-control height-35 f-14" id="f-growth" value="{{ $def['growth_pct'] }}">
                </div>

                <div class="dbk-filter {{ $def['method'] === 'manual' ? '' : 'd-none' }}" id="extra-corridor">
                    <label>@lang('databackd-planning-forecasts.corridor_pct')</label>
                    <input type="number" step="0.5" min="0" max="50" class="form-control height-35 f-14" id="f-corridor" value="{{ $def['corridor_pct'] }}">
                </div>
            </div>

            <!-- Scenario assumptions -->
            <div id="scenario-inputs" class="mt-3 pt-3 border-top {{ $def['mode'] === 'scenarios' ? '' : 'd-none' }}">
                <div class="dbk-card-title mb-1">@lang('databackd-planning-forecasts.scenarios_title')</div>
                <p class="dbk-form-hint mt-0 mb-3">@lang('databackd-planning-forecasts.scenarios_help')</p>
                <div class="row">
                    @foreach (['optimistic' => 'opt', 'pessimistic' => 'pes'] as $name => $prefix)
                        <div class="col-lg-6 mb-2">
                            <div class="dbk-planning-scenario-box">
                                <div class="dbk-planning-scenario-title"><span class="dbk-planning-swatch" data-scenario-swatch="{{ $name }}"></span>{{ __('databackd-planning-common.scenario.' . $name) }}</div>
                                <div class="row">
                                    <div class="col-sm-6 mb-2 mb-sm-0">
                                        <label class="f-12 dbk-text-muted mb-1">@lang('databackd-planning-forecasts.scenario_revenue_growth')</label>
                                        <input type="number" step="0.5" min="-100" max="100" class="form-control height-35 f-14 scenario-input"
                                               id="f-{{ $prefix }}-rev" value="{{ $def[$prefix . '_rev'] }}">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="f-12 dbk-text-muted mb-1">@lang('databackd-planning-forecasts.scenario_expense_growth')</label>
                                        <input type="number" step="0.5" min="-100" max="100" class="form-control height-35 f-14 scenario-input"
                                               id="f-{{ $prefix }}-exp" value="{{ $def[$prefix . '_exp'] }}">
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <!-- CONTROLS END -->

        <!-- MODE TABS + SAVE -->
        <div class="dbk-planning-toolbar">
            <div class="dbk-segmented dbk-mode-tabs" id="mode-tabs" role="group">
                <button type="button" class="nav-link {{ $def['mode'] === 'forecast' ? 'active' : '' }}" data-mode="forecast">
                    <i class="fa fa-chart-line"></i> @lang('databackd-planning-forecasts.tab_forecast')
                </button>
                <button type="button" class="nav-link {{ $def['mode'] === 'scenarios' ? 'active' : '' }}" data-mode="scenarios">
                    <i class="fa fa-random"></i> @lang('databackd-planning-forecasts.tab_scenarios')
                </button>
            </div>
            <x-forms.button-primary id="open-save-modal" icon="save">@lang('databackd-planning-forecasts.save_forecast')</x-forms.button-primary>
        </div>

        <div class="row">
            <div class="col-xl-9 mb-3">
                <div id="forecast-notices"></div>

                <!-- FORECAST RESULT -->
                <div id="result-forecast" class="{{ $def['mode'] === 'forecast' ? '' : 'd-none' }}">
                    <div id="forecast-kpi" class="dbk-kpi-grid"></div>
                    <div id="forecast-empty" class="dbk-planning-notice is-warning mt-0 mb-3 d-none"><i class="fa fa-exclamation-triangle"></i><span>@lang('databackd-planning-forecasts.no_data')</span></div>

                    <div class="dbk-card mb-3">
                        <div class="dbk-card-header">
                            <div style="min-width: 0">
                                <h3 class="dbk-card-title">@lang('databackd-planning-forecasts.chart_title')</h3>
                                <p class="dbk-card-subtitle" id="forecast-subtitle"></p>
                            </div>
                        </div>
                        <div class="dbk-card-body">
                            <x-dbk.chart id="forecast-chart" size="lg" :label="__('databackd-planning-forecasts.chart_title')"/>
                        </div>
                        <div class="dbk-forecast-explain">
                            <p class="mb-1"><i class="bi bi-lightbulb" aria-hidden="true"></i><strong class="js-method-name"></strong> <span class="js-method-help"></span></p>
                            <p class="mb-0 dbk-text-muted">@lang('databackd-planning-forecasts.corridor_help')</p>
                        </div>
                    </div>

                    <x-dbk.card :title="__('databackd-planning-forecasts.table_title')" body-class="p-0" :header-border="true">
                        <div class="dbk-table-wrap is-flush dbk-planning-scroll">
                            <table class="dbk-table dbk-table--compact dbk-table--sticky" id="forecast-table">
                                <thead>
                                <tr>
                                    <th>@lang('databackd-planning-forecasts.col_month')</th>
                                    <th>@lang('databackd-planning-forecasts.col_type')</th>
                                    <th class="num">@lang('databackd-planning-forecasts.col_value')</th>
                                    <th class="num">@lang('databackd-planning-forecasts.col_lower')</th>
                                    <th class="num">@lang('databackd-planning-forecasts.col_upper')</th>
                                </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </x-dbk.card>
                </div>

                <!-- SCENARIOS RESULT -->
                <div id="result-scenarios" class="{{ $def['mode'] === 'scenarios' ? '' : 'd-none' }}">
                    <x-dbk.card :title="__('databackd-planning-forecasts.scenario_chart_title')" class="mb-3">
                        <x-dbk.chart id="scenario-chart" size="lg" :label="__('databackd-planning-forecasts.scenario_chart_title')"/>
                        <div class="dbk-forecast-explain is-inset">
                            <p class="mb-1"><i class="bi bi-lightbulb" aria-hidden="true"></i><strong class="js-method-name"></strong> <span class="js-method-help"></span></p>
                            <p class="mb-0 dbk-text-muted">@lang('databackd-planning-forecasts.scenario_explain')</p>
                        </div>
                    </x-dbk.card>

                    <x-dbk.card :title="__('databackd-planning-forecasts.scenario_summary_title')" body-class="p-0" class="mb-3" :header-border="true">
                        <div class="dbk-table-wrap is-flush">
                            <table class="dbk-table dbk-table--sticky-first" id="scenario-summary-table">
                                <thead>
                                <tr>
                                    <th>@lang('databackd-planning-forecasts.col_scenario')</th>
                                    <th class="num">@lang('databackd-planning-forecasts.col_revenue')</th>
                                    <th class="num">@lang('databackd-planning-forecasts.col_expense')</th>
                                    <th class="num">@lang('databackd-planning-forecasts.col_profit')</th>
                                    <th class="num">@lang('databackd-planning-forecasts.col_cash_end')</th>
                                </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <div class="dbk-planning-footnote" id="scenario-note"></div>
                    </x-dbk.card>

                    <x-dbk.card :title="__('databackd-planning-forecasts.scenario_monthly_title')" body-class="p-0" :header-border="true">
                        <div class="dbk-table-wrap is-flush dbk-planning-scroll">
                            <table class="dbk-table dbk-table--compact dbk-table--sticky" id="scenario-rows-table">
                                <thead>
                                <tr>
                                    <th>@lang('databackd-planning-forecasts.col_month')</th>
                                    @foreach (['base', 'optimistic', 'pessimistic'] as $s)
                                        <th class="num"><span class="dbk-planning-swatch" data-scenario-swatch="{{ $s }}"></span>{{ __('databackd-planning-common.scenario.' . $s) }}</th>
                                    @endforeach
                                </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </x-dbk.card>
                </div>
            </div>

            <!-- SAVED FORECASTS -->
            <div class="col-xl-3 mb-3">
                <x-dbk.card :title="__('databackd-planning-forecasts.saved_title')">
                    <div id="saved-panel">
                        @include('databackd.planning.forecasts._saved')
                    </div>
                </x-dbk.card>
            </div>
        </div>
    </div>

    <!-- SAVE MODAL -->
    <div class="modal fade" id="save-forecast-modal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form id="save-forecast-form" onsubmit="return false;">
                    <div class="modal-header">
                        <h5 class="modal-title f-18 f-w-500">@lang('databackd-planning-forecasts.save_title')</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('databackd-misc.close') }}"><span aria-hidden="true">×</span></button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group mb-0">
                            <label class="f-14 text-dark-grey mb-12" for="forecast-name">@lang('databackd-planning-forecasts.forecast_name')</label>
                            <input type="text" class="form-control height-35 f-14" name="name" id="forecast-name" maxlength="191"
                                   placeholder="{{ __('databackd-planning-forecasts.forecast_name_placeholder') }}" autocomplete="off">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-cancel rounded f-14 p-2 mr-2" data-dismiss="modal">@lang('databackd-planning-forecasts.cancel')</button>
                        <button type="submit" class="btn btn-primary rounded f-14 p-2" id="save-forecast-btn">
                            <i class="fa fa-check mr-1"></i> @lang('databackd-planning-forecasts.save')
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
@php
    $jsText = [
                'history' => __('databackd-planning-forecasts.type_history'),
                'forecast' => __('databackd-planning-forecasts.type_forecast'),
                'corridor' => __('databackd-planning-common.corridor'),
                'currency' => $currencySymbol,
                'method_name' => [
                    'linear' => __('databackd-planning-common.method.linear'),
                    'moving_average' => __('databackd-planning-common.method.moving_average'),
                    'seasonal' => __('databackd-planning-common.method.seasonal'),
                    'manual' => __('databackd-planning-common.method.manual'),
                ],
                'method_help' => [
                    'linear' => __('databackd-planning-forecasts.method_help.linear'),
                    'moving_average' => __('databackd-planning-forecasts.method_help.moving_average'),
                    'seasonal' => __('databackd-planning-forecasts.method_help.seasonal'),
                    'manual' => __('databackd-planning-forecasts.method_help.manual'),
                ],
                'kpi_last' => __('databackd-planning-forecasts.kpi_last'),
                'kpi_end' => __('databackd-planning-forecasts.kpi_end'),
                'kpi_total' => __('databackd-planning-forecasts.kpi_total'),
                'kpi_avg' => __('databackd-planning-forecasts.kpi_avg'),
                'kpi_change' => __('databackd-planning-forecasts.kpi_change'),
                'vs_base' => __('databackd-planning-forecasts.vs_base'),
                'scenario_note' => __('databackd-planning-forecasts.scenario_note'),
                'chart_title' => __('databackd-planning-forecasts.chart_title'),
                'profit' => __('databackd-planning-common.profit'),
                'error' => __('databackd-planning-forecasts.error'),
                'confirm_title' => __('databackd-planning-forecasts.delete_confirm_title'),
                'confirm_text' => __('databackd-planning-forecasts.delete_confirm_text', ['name' => ':name']),
                'confirm_delete' => __('databackd-planning-forecasts.confirm_delete'),
                'cancel' => __('databackd-planning-forecasts.cancel'),
                'scenario' => [
                    'base' => __('databackd-planning-common.scenario.base'),
                    'optimistic' => __('databackd-planning-common.scenario.optimistic'),
                    'pessimistic' => __('databackd-planning-common.scenario.pessimistic'),
                ],
    ];
@endphp
    <script>
        (function () {
            var URL_DATA = @json(route('databackd.planning.forecasts.data'));
            var URL_SAVED = @json(route('databackd.planning.forecasts.saved'));
            var URL_STORE = @json(route('databackd.planning.forecasts.store'));
            var URL_DESTROY = @json(route('databackd.planning.forecasts.destroy', ':id'));
            var T = @json($jsText);

            var mode = @json($def['mode']);
            var activeId = @json($def['forecast_id']);
            var forecastChart = null, scenarioChart = null, timer = null, seq = 0;

            // ---- helpers -------------------------------------------------------------------------------------
            function esc(s) {
                return $('<div>').text(s === null || s === undefined ? '' : s).html();
            }

            function fmt(v) {
                if (v === null || v === undefined || isNaN(v)) {
                    return '—';
                }
                var n = Math.round(Number(v));
                var s = String(Math.abs(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                return (n < 0 ? '−' : '') + s;
            }

            // signed percentage with a real minus sign: "+10 %", "−27,6 %"
            function pct(v) {
                var n = Number(v);
                return (n > 0 ? '+' : (n < 0 ? '−' : '')) + String(Math.abs(n)).replace('.', ',') + ' %';
            }

            function money(v) {
                return v === null || v === undefined ? '—' : fmt(v) + ' ' + T.currency;
            }

            function delta(v, goodWhenPositive) {
                if (Math.abs(v) < 0.5) {
                    return '<span class="dbk-planning-delta">± 0 ' + esc(T.vs_base) + '</span>';
                }
                var good = (v > 0) === goodWhenPositive;
                return '<span class="dbk-planning-delta ' + (good ? 'is-good' : 'is-bad') + '">' + (v > 0 ? '+' : '') + fmt(v) + ' ' + T.currency + ' ' + esc(T.vs_base) + '</span>';
            }

            // Same markup as the dbk.kpi Blade component (components/dbk/kpi.blade.php)
            function kpi(label, value, sub, icon) {
                return '<div class="dbk-kpi"><div class="dbk-kpi-top"><p class="dbk-kpi-label">' + esc(label) + '</p>' +
                    (icon ? '<span class="dbk-kpi-icon" aria-hidden="true"><i class="bi ' + icon + '"></i></span>' : '') + '</div>' +
                    '<div class="dbk-kpi-value">' + value + '</div>' + (sub ? '<div class="dbk-kpi-foot">' + sub + '</div>' : '') + '</div>';
            }

            function pctDelta(v, goodWhenPositive) {
                if (v === null || v === undefined) {
                    return '';
                }
                var state = Number(v) === 0 ? 'neutral' : ((v > 0) === goodWhenPositive ? 'good' : 'bad');
                var arrow = Number(v) === 0 ? 'bi-dash' : (v > 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short');
                return '<span class="dbk-delta is-' + state + '"><i class="bi ' + arrow + '"></i>' + (v > 0 ? '+' : (v < 0 ? '−' : '')) + String(Math.abs(v)).replace('.', ',') + ' %</span>';
            }

            var SCENARIO_COLORS = {base: dbkChart.color(0), optimistic: dbkChart.color(2), pessimistic: dbkChart.color(1)};

            $('[data-scenario-swatch]').each(function () {
                $(this).css('background', SCENARIO_COLORS[$(this).data('scenario-swatch')]);
            });

            function xTicks() {
                return window.innerWidth < 576 ? 4 : (window.innerWidth < 992 ? 8 : 12);
            }

            function hideLower(options) {
                return $.extend(true, {
                    plugins: {
                        legend: {labels: {filter: function (item) { return item.text !== '__lower'; }}},
                        tooltip: {filter: function (item) { return item.dataset.label !== '__lower'; }}
                    }
                }, options || {});
            }

            // ---- state ---------------------------------------------------------------------------------------
            function readState() {
                var metric = ($('#f-metric').val() || 'revenue').split(':');
                var s = {
                    metric: metric[0],
                    metric_ref: metric[1] || '',
                    entity_id: $('#f-entity').val() || '',
                    horizon: $('#f-horizon').val(),
                    method: $('#f-method').val(),
                    window: $('#f-window').val(),
                    growth_pct: $('#f-growth').val(),
                    corridor_pct: $('#f-corridor').val(),
                    mode: mode,
                    opt_rev: $('#f-opt-rev').val(),
                    opt_exp: $('#f-opt-exp').val(),
                    pes_rev: $('#f-pes-rev').val(),
                    pes_exp: $('#f-pes-exp').val()
                };
                return s;
            }

            function syncUi() {
                var method = $('#f-method').val();
                $('.js-method-name').text((T.method_name[method] || '') + ':');
                $('.js-method-help').text(T.method_help[method] || '');
                $('#extra-window').toggleClass('d-none', method !== 'moving_average');
                $('#extra-growth, #extra-corridor').toggleClass('d-none', method !== 'manual');
                $('#scenario-inputs').toggleClass('d-none', mode !== 'scenarios');
                $('#metric-col').toggleClass('d-none', mode === 'scenarios');
                $('#result-forecast').toggleClass('d-none', mode !== 'forecast');
                $('#result-scenarios').toggleClass('d-none', mode !== 'scenarios');
                $('#mode-tabs .nav-link').removeClass('active').filter('[data-mode="' + mode + '"]').addClass('active');
            }

            function pushUrl(state) {
                try {
                    var q = {};
                    $.each(state, function (k, v) {
                        if (v !== '' && v !== null && v !== undefined) {
                            q[k] = v;
                        }
                    });
                    history.replaceState(null, '', window.location.pathname + '?' + $.param(q));
                } catch (e) {
                }
            }

            // ---- rendering -----------------------------------------------------------------------------------
            function renderNotices(list) {
                var html = '';
                $.each(list || [], function (i, n) {
                    var warn = n.type === 'warning';
                    html += '<div class="dbk-planning-notice mt-0 mb-3' + (warn ? ' is-warning' : '') + '" role="status"><i class="fa ' + (warn ? 'fa-exclamation-triangle' : 'fa-info-circle') + '" aria-hidden="true"></i><span>' + esc(n.text) + '</span></div>';
                });
                $('#forecast-notices').html(html);
            }

            function renderForecast(d) {
                var s = d.summary;
                var goodUp = readState().metric !== 'expense';
                var change = pctDelta(s.change_pct, goodUp);
                renderNotices(d.notices);
                var html = kpi(T.kpi_last, money(s.last), d.last_label ? '<span>' + esc(d.last_label) + '</span>' : '', 'bi-clock-history') + kpi(T.kpi_end, money(s.end), change, 'bi-flag');
                html += d.is_stock ? kpi(T.kpi_avg, money(s.avg), '', 'bi-bar-chart') : kpi(T.kpi_total, money(s.total), '', 'bi-calculator');
                html += d.is_stock ? kpi(T.kpi_change, s.change_pct === null ? '—' : pct(s.change_pct), '', 'bi-percent') : kpi(T.kpi_avg, money(s.avg), '', 'bi-bar-chart');
                $('#forecast-kpi').html(html);
                $('#forecast-empty').toggleClass('d-none', !!d.has_history);
                $('#forecast-subtitle').text(d.title);

                var labels = [], hist = [], fc = [], lo = [], up = [], rows = '', lastHistIdx = -1;

                $.each(d.rows, function (i, r) {
                    if (r.type === 'history') {
                        lastHistIdx = i;
                    }
                });

                $.each(d.rows, function (i, r) {
                    labels.push(r.label);
                    if (r.type === 'history') {
                        hist.push(r.value);
                        fc.push(i === lastHistIdx ? r.value : null);
                        lo.push(i === lastHistIdx ? r.value : null);
                        up.push(i === lastHistIdx ? r.value : null);
                    } else {
                        hist.push(null);
                        fc.push(r.value);
                        lo.push(r.lower);
                        up.push(r.upper);
                    }

                    var isF = r.type === 'forecast';
                    rows += '<tr class="' + (isF ? 'dbk-row-forecast' : '') + '">' +
                        '<td>' + esc(r.label) + '</td>' +
                        '<td><span class="dbk-badge ' + (isF ? 'is-warning' : '') + '">' + esc(isF ? T.forecast : T.history) + '</span></td>' +
                        '<td class="num">' + money(r.value) + '</td>' +
                        '<td class="num dbk-text-muted">' + (isF ? money(r.lower) : '—') + '</td>' +
                        '<td class="num dbk-text-muted">' + (isF ? money(r.upper) : '—') + '</td></tr>';
                });

                $('#forecast-table tbody').html(rows);

                // History (solid) + forecast (dashed) + corridor: the area between "__lower" and the upper bound (fill '-1')
                var fcColor = dbkChart.role('forecast');
                forecastChart = dbkChart.line('forecast-chart', {
                    labels: labels,
                    unit: 'money',
                    emptyText: false,
                    datasets: [
                        {label: T.history, data: hist, role: 'profit'},
                        {label: '__lower', data: lo, color: fcColor, borderColor: 'transparent', backgroundColor: 'transparent', pointHoverRadius: 0},
                        {label: T.corridor, data: up, color: fcColor, borderColor: 'transparent', area: true, fill: '-1', backgroundColor: dbkChart.alpha(fcColor, 0.14), pointHoverRadius: 0},
                        {label: T.forecast, data: fc, role: 'forecast', dashed: true}
                    ],
                    options: hideLower({
                        scales: {x: {ticks: {maxTicksLimit: xTicks()}}},
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        if (ctx.datasetIndex === 2) {
                                            var low = ctx.chart.data.datasets[1].data[ctx.dataIndex];
                                            return ' ' + T.corridor + ': ' + money(low) + ' – ' + money(ctx.parsed.y);
                                        }
                                        return ' ' + ctx.dataset.label + ': ' + money(ctx.parsed.y);
                                    }
                                }
                            }
                        }
                    })
                });
            }

            function renderScenarios(d) {
                renderNotices(d.notices);
                var colors = SCENARIO_COLORS;
                var n = d.history_count;
                var datasets = [];
                var hist = d.profit_history.concat(new Array(d.forecast_labels.length).fill(null));
                datasets.push({label: T.history, data: hist, role: 'neutral'});

                var summary = '', monthly = '';

                $.each(d.scenarios, function (i, sc) {
                    var data = new Array(n).fill(null);
                    data[n - 1] = d.profit_history[n - 1];
                    data = data.concat(sc.monthly);
                    datasets.push({label: sc.label, data: data, color: colors[sc.name], dashed: sc.name !== 'base'});

                    var isBase = sc.name === 'base';
                    summary += '<tr><td><span class="d-inline-flex align-items-center font-weight-bold"><span class="dbk-planning-swatch" style="background:' + colors[sc.name] + '"></span>' + esc(sc.label) + '</span>' +
                        (isBase ? '' : '<span class="dbk-planning-delta">' + pct(sc.revenue_growth) + ' / ' + pct(sc.expense_growth) + '</span>') + '</td>' +
                        '<td class="num">' + money(sc.revenue) + (isBase ? '' : delta(sc.d_revenue, true)) + '</td>' +
                        '<td class="num">' + money(sc.expense) + (isBase ? '' : delta(sc.d_expense, false)) + '</td>' +
                        '<td class="num font-weight-bold">' + money(sc.profit) + (isBase ? '' : delta(sc.d_profit, true)) + '</td>' +
                        '<td class="num font-weight-bold">' + money(sc.cash_end) + (isBase ? '' : delta(sc.d_cash_end, true)) + '</td></tr>';
                });

                $.each(d.forecast_labels, function (i, label) {
                    monthly += '<tr><td>' + esc(label) + '</td>';
                    $.each(d.scenarios, function (j, sc) {
                        var v = sc.monthly[i];
                        monthly += '<td class="num' + (v < 0 ? ' neg' : '') + '">' + money(v) + '</td>';
                    });
                    monthly += '</tr>';
                });

                $('#scenario-summary-table tbody').html(summary);
                $('#scenario-rows-table tbody').html(monthly);
                $('#scenario-note').html('<i class="fa fa-info-circle mr-1"></i>' + esc(d.cash_label) + ': <strong>' + money(d.cash_now) + '</strong>. ' + esc(T.scenario_note));

                scenarioChart = dbkChart.line('scenario-chart', {
                    labels: d.labels,
                    unit: 'money',
                    emptyText: false,
                    datasets: datasets,
                    options: {scales: {x: {ticks: {maxTicksLimit: xTicks()}}}}
                });
            }

            // ---- loading -------------------------------------------------------------------------------------
            function load() {
                var state = readState();
                var my = ++seq;
                pushUrl(state);
                $('#result-forecast, #result-scenarios').addClass('dbk-is-loading');

                $.getJSON(URL_DATA, state).done(function (d) {
                    if (my !== seq) {
                        return;
                    }
                    if (d.mode === 'scenarios') {
                        renderScenarios(d);
                    } else {
                        renderForecast(d);
                    }
                }).fail(function () {
                    if (my === seq && window.Swal) {
                        Swal.fire({toast: true, position: 'top-end', icon: 'error', title: T.error, showConfirmButton: false, timer: 4000});
                    }
                }).always(function () {
                    if (my === seq) {
                        $('#result-forecast, #result-scenarios').removeClass('dbk-is-loading');
                    }
                });
            }

            function changed() {
                if (activeId) {
                    activeId = null;
                    $('#saved-panel .dbk-saved-item').removeClass('dbk-saved-active').find('.badge').remove();
                }
                syncUi();
                clearTimeout(timer);
                timer = setTimeout(load, 250);
            }

            function reloadSaved() {
                $.getJSON(URL_SAVED, {active: activeId || ''}).done(function (r) {
                    $('#saved-panel').html(r.html);
                });
            }

            // ---- events --------------------------------------------------------------------------------------
            $('#f-metric, #f-entity, #f-horizon, #f-method, #f-window').on('change', changed);
            $('#f-growth, #f-corridor, .scenario-input').on('input change', changed);

            $('#mode-tabs').on('click', '.nav-link', function () {
                mode = $(this).data('mode');
                changed();
            });

            $('#open-save-modal').on('click', function () {
                $('#forecast-name').val('').removeClass('is-invalid');
                $('#save-forecast-modal').modal('show');
            });

            $('#save-forecast-modal').on('shown.bs.modal', function () {
                $('#forecast-name').trigger('focus');
            });

            $('#save-forecast-form').on('submit', function (e) {
                e.preventDefault();
                var payload = $.extend(readState(), {name: $('#forecast-name').val(), _token: @json(csrf_token())});

                $.easyAjax({
                    type: 'POST',
                    url: URL_STORE,
                    container: '#save-forecast-form',
                    disableButton: true,
                    buttonSelector: '#save-forecast-btn',
                    data: payload,
                    success: function (response) {
                        if (response.status == 'success') {
                            $('#save-forecast-modal').modal('hide');
                            activeId = response.id || null;
                            reloadSaved();
                        }
                    }
                });
            });

            $('body').on('click', '.delete-forecast', function () {
                var id = $(this).data('id');
                var name = $(this).data('name');

                Swal.fire({
                    title: T.confirm_title,
                    text: T.confirm_text.replace(':name', name),
                    icon: 'warning',
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: T.confirm_delete,
                    cancelButtonText: T.cancel,
                    customClass: {confirmButton: 'btn btn-primary mr-3', cancelButton: 'btn btn-secondary'},
                    showClass: {popup: 'swal2-noanimation', backdrop: 'swal2-noanimation'},
                    buttonsStyling: false
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }

                    $.easyAjax({
                        type: 'POST',
                        url: URL_DESTROY.replace(':id', id),
                        blockUI: true,
                        data: {'_token': @json(csrf_token()), '_method': 'DELETE'},
                        success: function (response) {
                            if (response.status == 'success') {
                                if (activeId == id) {
                                    activeId = null;
                                }
                                reloadSaved();
                            }
                        }
                    });
                });
            });

            syncUi();
            load();
        })();
    </script>
@endpush
