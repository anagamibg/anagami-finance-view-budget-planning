@extends('layouts.app')

@php
    // Whole numbers without decimals, cents only when present (keeps the 12 columns compact)
    $fmt = function ($v) {
        $v = round((float)$v, 2);
        $decimals = abs($v - round($v)) > 0.001 ? 2 : 0;

        return number_format($v, $decimals, ',', ' ');
    };
    $scenarioBadge = ['base' => 'is-info', 'optimistic' => 'is-good', 'pessimistic' => 'is-warning'];
    $versionBadge = ['draft' => 'is-warning', 'approved' => 'is-good'];
    $lyYear = $budget->year - 1;
    $totals = ['revenue' => array_fill(1, 12, 0.0), 'expense' => array_fill(1, 12, 0.0)];
    $lyTotals = ['revenue' => 0.0, 'expense' => 0.0];

    foreach ($sections as $type => $rows) {
        foreach ($rows as $row) {
            foreach ($row['months'] as $m => $v) {
                $totals[$type][$m] += $v;
            }

            $lyTotals[$type] += $row['last_year'];
        }
    }
@endphp

@section('content')
    <!-- CONTENT WRAPPER START -->
    <div class="content-wrapper">

        <x-dbk.page-header :title="$budget->name" :subtitle="$readonly ? __('databackd-planning-budget-editor.subtitle_readonly') : __('databackd-planning-budget-editor.subtitle')">
            <x-slot name="actions">
                @if ($listUrl)
                    <a href="{{ $listUrl }}" class="btn btn-secondary rounded f-14 p-2">
                        <i class="fa fa-arrow-left mr-1"></i>{{ __('databackd-planning-budget-editor.back_to_list') }}
                    </a>
                @endif
                @unless ($readonly)
                    <x-forms.button-primary id="be-save" icon="check">{{ __('databackd-planning-budget-editor.buttons.save') }}</x-forms.button-primary>
                @endunless
            </x-slot>
        </x-dbk.page-header>

        <div class="dbk-card mb-3">
            <div class="dbk-card-body">
                <div class="dbk-planning-meta">
                    <div>
                        <span class="dbk-planning-meta-label">{{ __('databackd-planning-budget-editor.year') }}</span>
                        <span class="dbk-planning-meta-value dbk-num">{{ $budget->year }}</span>
                    </div>
                    <div>
                        <span class="dbk-planning-meta-label">{{ __('databackd-planning-budget-editor.entity') }}</span>
                        <span class="dbk-planning-meta-value">{{ $budget->entity?->name ?? __('databackd-planning-common.all_entities') }}</span>
                    </div>
                    <div>
                        <span class="dbk-planning-meta-label">{{ __('databackd-planning-budget-editor.scenario') }}</span>
                        <span class="dbk-badge {{ $scenarioBadge[$budget->scenario] ?? '' }}">{{ __('databackd-planning-common.scenario.' . $budget->scenario) }}</span>
                    </div>
                    <div>
                        <span class="dbk-planning-meta-label">{{ __('databackd-planning-budget-editor.version') }}</span>
                        <span class="dbk-badge {{ $versionBadge[$budget->version] ?? '' }}">{{ __('databackd-planning-common.version.' . $budget->version) }}</span>
                    </div>
                    <div>
                        <span class="dbk-planning-meta-label">{{ __('databackd-planning-budget-editor.currency') }}</span>
                        <span class="dbk-planning-meta-value">{{ $budget->currency }}</span>
                    </div>
                    @unless ($readonly)
                        <div>
                            <span class="dbk-planning-meta-label">{{ __('databackd-planning-budget-editor.save_state') }}</span>
                            <span class="be-state badge badge-light" id="be-state" role="status" aria-live="polite">{{ __('databackd-planning-budget-editor.messages.saved_state') }}</span>
                        </div>
                    @endunless
                </div>

                @if ($readonly)
                    <div class="dbk-planning-notice"><i class="fa fa-lock"></i><span>{{ __('databackd-planning-budget-editor.readonly_notice') }}</span></div>
                @elseif ($hasAccounts)
                    <p class="be-help mb-0"><i class="fa fa-keyboard mr-1" aria-hidden="true"></i>{{ __('databackd-planning-budget-editor.keyboard_help') }}</p>
                @endif
            </div>
        </div>

        @if (!$hasAccounts)
            <div class="dbk-card mb-3">
                <x-dbk.empty icon="journal-x" :text="__('databackd-planning-budget-editor.empty_accounts')"/>
            </div>
        @else
            @unless ($readonly)
                <div class="be-errors mb-3" id="be-errors" role="alert" hidden>
                    <i class="fa fa-exclamation-circle" aria-hidden="true"></i>
                    <span id="be-errors-text"></span>
                    <button type="button" class="btn btn-link p-0 ml-2" id="be-errors-first">{{ __('databackd-planning-budget-editor.errors.go_first') }}</button>
                </div>
            @endunless

            <div class="dbk-card mb-4">
                <div class="be-wrap dbk-planning-editor-wrap {{ $readonly ? 'be-readonly' : '' }}" id="be-wrap">
                    <table class="be-table {{ $readonly ? 'be-ro-grid' : '' }}" id="be-table">
                        <thead>
                        <tr>
                            <th class="be-sticky be-name">{{ __('databackd-planning-budget-editor.columns.account') }}</th>
                            @unless ($readonly)
                                <th class="be-sticky be-actions">{{ __('databackd-planning-budget-editor.columns.actions') }}</th>
                            @endunless
                            @for ($m = 1; $m <= 12; $m++)
                                <th class="text-right be-cell">{{ __('databackd-planning-common.months_short.' . $m) }}</th>
                            @endfor
                            <th class="text-right be-total">{{ __('databackd-planning-budget-editor.columns.total') }}</th>
                            <th class="text-right be-ly">{{ __('databackd-planning-budget-editor.columns.last_year', ['year' => $lyYear]) }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach (['revenue', 'expense'] as $type)
                            <tr class="be-section" data-section="{{ $type }}">
                                <td colspan="{{ 15 + ($readonly ? 0 : 1) }}">
                                    <button type="button" class="be-section-toggle" aria-expanded="true" data-section-toggle="{{ $type }}">
                                        <i class="fa fa-chevron-down" aria-hidden="true"></i>
                                        <span class="be-section-label">{{ __('databackd-planning-budget-editor.sections.' . $type) }}</span>
                                        <span class="be-section-count">{{ trans_choice('databackd-planning-budget-editor.accounts_count', count($sections[$type]), ['count' => count($sections[$type])]) }}</span>
                                    </button>
                                </td>
                            </tr>

                            @foreach ($sections[$type] as $row)
                                @php $locked = $readonly || $row['split']; @endphp
                                <tr class="be-row {{ $row['split'] ? 'be-split' : '' }}" data-account="{{ $row['id'] }}" data-type="{{ $type }}" data-locked="{{ $locked ? 1 : 0 }}">
                                    <td class="be-sticky be-name">
                                        <span class="be-code">{{ $row['code'] }}</span>{{ $row['name'] }}
                                        @if ($row['inactive'])
                                            <span class="dbk-badge no-dot be-inactive" title="{{ __('databackd-planning-budget-editor.inactive_tooltip') }}">{{ __('databackd-planning-budget-editor.inactive') }}</span>
                                        @endif
                                        @if ($row['split'] && !$readonly)
                                            <i class="fa fa-lock dbk-text-muted ml-1" data-toggle="tooltip" title="{{ __('databackd-planning-budget-editor.split_tooltip') }}"></i>
                                        @endif
                                    </td>
                                    @unless ($readonly)
                                        <td class="be-sticky be-actions">
                                            @unless ($row['split'])
                                                <button type="button" class="btn btn-light border be-act-distribute" data-toggle="tooltip" title="{{ __('databackd-planning-budget-editor.buttons.distribute') }}" aria-label="{{ __('databackd-planning-budget-editor.buttons.distribute') }}: {{ $row['code'] }}"><i class="fa fa-arrows-alt-h" aria-hidden="true"></i></button>
                                                <button type="button" class="btn btn-light border be-act-grow" data-toggle="tooltip" title="{{ __('databackd-planning-budget-editor.buttons.grow') }}" aria-label="{{ __('databackd-planning-budget-editor.buttons.grow') }}: {{ $row['code'] }}"><i class="fa fa-percent" aria-hidden="true"></i></button>
                                                <button type="button" class="btn btn-light border be-act-copy" data-toggle="tooltip" title="{{ __('databackd-planning-budget-editor.buttons.copy_right') }}" aria-label="{{ __('databackd-planning-budget-editor.buttons.copy_right') }}: {{ $row['code'] }}"><i class="fa fa-angle-double-right" aria-hidden="true"></i></button>
                                            @endunless
                                        </td>
                                    @endunless
                                    @for ($m = 1; $m <= 12; $m++)
                                        @php $v = $row['months'][$m]; @endphp
                                        <td class="be-cell" data-month="{{ $m }}" data-v="{{ $v }}">
                                            @if ($locked)
                                                <span class="be-ro {{ $v == 0 ? 'be-zero' : '' }}">{{ $v == 0 ? '–' : $fmt($v) }}</span>
                                            @else
                                                <input type="text" class="be-input" inputmode="decimal" autocomplete="off" data-month="{{ $m }}" value="{{ $v == 0 ? '' : $fmt($v) }}"
                                                       aria-label="{{ $row['code'] }} {{ $row['name'] }}, {{ __('databackd-planning-common.months.' . $m) }}">
                                            @endif
                                        </td>
                                    @endfor
                                    <td class="be-num be-total be-row-total">{{ $fmt(array_sum($row['months'])) }}</td>
                                    <td class="be-num be-ly" data-v="{{ $row['last_year'] }}">{{ $row['last_year'] == 0 ? '–' : $fmt($row['last_year']) }}</td>
                                </tr>
                            @endforeach

                            <tr class="be-subtotal" data-sub="{{ $type }}">
                                <td class="be-sticky be-name">{{ __('databackd-planning-budget-editor.subtotal.' . $type) }}</td>
                                @unless ($readonly)
                                    <td class="be-sticky be-actions"></td>
                                @endunless
                                @for ($m = 1; $m <= 12; $m++)
                                    <td class="be-num" data-sub-month="{{ $m }}">{{ $fmt($totals[$type][$m]) }}</td>
                                @endfor
                                <td class="be-num be-total" data-sub-total>{{ $fmt(array_sum($totals[$type])) }}</td>
                                <td class="be-num be-ly" data-sub-ly>{{ $fmt($lyTotals[$type]) }}</td>
                            </tr>
                        @endforeach

                        <tr class="be-profit" id="be-profit">
                            <td class="be-sticky be-name">{{ __('databackd-planning-budget-editor.profit') }}</td>
                            @unless ($readonly)
                                <td class="be-sticky be-actions"></td>
                            @endunless
                            @for ($m = 1; $m <= 12; $m++)
                                @php $p = $totals['revenue'][$m] - $totals['expense'][$m]; @endphp
                                <td class="be-num {{ $p < 0 ? 'be-neg' : '' }}" data-profit-month="{{ $m }}">{{ $fmt($p) }}</td>
                            @endfor
                            @php $pt = array_sum($totals['revenue']) - array_sum($totals['expense']); $pl = $lyTotals['revenue'] - $lyTotals['expense']; @endphp
                            <td class="be-num be-total {{ $pt < 0 ? 'be-neg' : '' }}" data-profit-total>{{ $fmt($pt) }}</td>
                            <td class="be-num be-ly {{ $pl < 0 ? 'be-neg' : '' }}" data-profit-ly>{{ $fmt($pl) }}</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
    <!-- CONTENT WRAPPER END -->
@endsection


@if ($hasAccounts)
@push('scripts')
    <script>
        (function () {
            var CFG = {
                readonly: @json((bool)$readonly),
                budgetId: @json($budget->id),
                saveUrl: @json($saveUrl),
                cellUrl: @json($cellUrl),
                csrf: @json(csrf_token()),
                lastYear: @json((object)$lastYearByAccount),
                lyYear: @json($lyYear),
                t: {
                    prompts: @json(__('databackd-planning-budget-editor.prompts')),
                    messages: @json(__('databackd-planning-budget-editor.messages')),
                    cell: @json(__('databackd-planning-budget-editor.cell')),
                    errors: @json(__('databackd-planning-budget-editor.errors')),
                    confirm: @json(__('databackd-planning-budget-editor.buttons.confirm')),
                    cancel: @json(__('databackd-planning-budget-editor.buttons.cancel'))
                }
            };

            var $table = $('#be-table'), wrap = document.getElementById('be-wrap');
            var lastMonth = {}; // account id => month of the cell edited / focused last

            // ---- Numbers --------------------------------------------------------------------------------------
            function fmt(n) {
                n = Math.round(n * 100) / 100;
                var decimals = Math.abs(n - Math.round(n)) > 0.001 ? 2 : 0;
                var parts = Math.abs(n).toFixed(decimals).split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                return (n < 0 ? '-' : '') + parts[0] + (parts[1] ? ',' + parts[1] : '');
            }

            function tr(text, map) {
                return String(text).replace(/:(\w+)/g, function (m, k) { return map.hasOwnProperty(k) ? map[k] : m; });
            }

            // Signed amount or null. Accepts "1234,56", "1234.56", "1 234,56", "1.234,56", "1,234.56", "1.500"
            // (BG thousands), "1 234 €", "(1 234)" (accounting negative); "-" / "–" (Excel zero) and "" are 0.
            // The comma is the decimal separator (bg-BG) unless both separators are present.
            function parseSignedAmt(v) {
                var s = String(v === null || v === undefined ? '' : v)
                    .replace(/[\s  ']/g, '').replace(/€|EUR|eur|лв\.?|BGN|USD|\$/g, '').replace(/−/g, '-');
                if (s === '' || /^[-–—]$/.test(s)) return 0;
                var neg = false;
                if (/^\(.*\)$/.test(s)) { neg = true; s = s.slice(1, -1); }
                if (/^[+-]/.test(s)) { neg = neg !== (s.charAt(0) === '-'); s = s.substring(1); }
                if (!/^[\d.,]+$/.test(s) || !/\d/.test(s)) return null;
                var c = s.lastIndexOf(','), d = s.lastIndexOf('.');
                if (c !== -1 && d !== -1) { // both: the last one is the decimal separator
                    var dec = c > d ? ',' : '.';
                    s = s.split(dec === ',' ? '.' : ',').join('').replace(dec, '.');
                } else if (c !== -1) {
                    if (s.split(',').length > 2) return null;
                    s = s.replace(',', '.');
                } else if (d !== -1) {
                    var dots = s.split('.');
                    if (dots.length > 2) { // 1.234.567
                        if (!dots.slice(1).every(function (g) { return g.length === 3; })) return null;
                        s = dots.join('');
                    } else if (dots[1].length === 3 && dots[0] !== '' && dots[0] !== '0') {
                        s = dots.join(''); // 1.500 = 1500
                    }
                }
                if (!/^\d*\.?\d*$/.test(s)) return null;
                var n = parseFloat(s);
                if (isNaN(n) || n >= 1e12) return null;
                n = Math.round(n * 100) / 100;
                return neg ? -n : n;
            }

            // Amount of a cell: non-negative number, or null (invalid / negative)
            function parseAmt(v) {
                var n = parseSignedAmt(v);
                return n === null || n < 0 ? null : n;
            }

            // Why a value is refused: 'negative' | 'invalid' | null
            function amtProblem(v) {
                var n = parseSignedAmt(v);
                return n === null ? 'invalid' : (n < 0 ? 'negative' : null);
            }

            function problemText(problem) {
                return problem === 'negative' ? CFG.t.messages.negative_number : CFG.t.messages.invalid_number;
            }

            // Toasts; the same text is not repeated within 5 s (e.g. many cells failing while offline)
            var lastToast = {};
            function notify(type, text) {
                var now = Date.now();
                if (lastToast[text] && now - lastToast[text] < 5000) return;
                lastToast[text] = now;
                if (window.Swal && Swal.fire) {
                    Swal.fire({toast: true, position: 'top-end', icon: type, title: text, showConfirmButton: false, timer: 4000});
                } else {
                    alert(text);
                }
            }

            // ---- Model --------------------------------------------------------------------------------------
            // vals: amounts in the grid (what the totals show), base: amounts stored on the server.
            var rows = {}, vals = {}, base = {}, types = {};
            $table.find('tr.be-row').each(function () {
                var $tr = $(this), account = String($tr.data('account'));
                rows[account] = $tr;
                types[account] = $tr.data('type');
                vals[account] = {};
                base[account] = {};
                $tr.find('td.be-cell').each(function () {
                    var m = $(this).data('month'), v = parseFloat($(this).attr('data-v')) || 0;
                    vals[account][m] = v;
                    base[account][m] = v;
                });
            });

            function key(account, month) { return account + ':' + month; }
            function $input(account, month) { return rows[account] ? rows[account].find('input.be-input[data-month="' + month + '"]') : $(); }
            function $cell(account, month) { return rows[account] ? rows[account].find('td.be-cell[data-month="' + month + '"]') : $(); }
            function accountOf($in) { return String($in.closest('tr').data('account')); }

            // ---- Totals (only the touched row and the 3 summary rows are repainted) --------------------------
            function paintRow(account) {
                var t = 0;
                for (var m = 1; m <= 12; m++) t += vals[account][m] || 0;
                rows[account].find('.be-row-total').text(fmt(t));
            }

            function recalcTotals() {
                var sums = {revenue: [], expense: []}, ly = {revenue: 0, expense: 0}, m;
                for (m = 1; m <= 12; m++) { sums.revenue[m] = 0; sums.expense[m] = 0; }
                Object.keys(vals).forEach(function (account) {
                    var type = types[account];
                    for (var i = 1; i <= 12; i++) sums[type][i] += vals[account][i] || 0;
                });
                $table.find('tr.be-row td.be-ly').each(function () {
                    ly[$(this).closest('tr').data('type')] += parseFloat($(this).attr('data-v')) || 0;
                });
                ['revenue', 'expense'].forEach(function (type) {
                    var $sub = $table.find('tr[data-sub="' + type + '"]'), t = 0;
                    for (var i = 1; i <= 12; i++) {
                        $sub.find('[data-sub-month="' + i + '"]').text(fmt(sums[type][i]));
                        t += sums[type][i];
                    }
                    $sub.find('[data-sub-total]').text(fmt(t));
                    $sub.find('[data-sub-ly]').text(fmt(ly[type]));
                });
                var $p = $('#be-profit'), pt = 0;
                for (m = 1; m <= 12; m++) {
                    var p = Math.round((sums.revenue[m] - sums.expense[m]) * 100) / 100;
                    pt += p;
                    $p.find('[data-profit-month="' + m + '"]').text(fmt(p)).toggleClass('be-neg', p < -0.004);
                }
                pt = Math.round(pt * 100) / 100;
                $p.find('[data-profit-total]').text(fmt(pt)).toggleClass('be-neg', pt < 0);
                var pl = Math.round((ly.revenue - ly.expense) * 100) / 100;
                $p.find('[data-profit-ly]').text(fmt(pl)).toggleClass('be-neg', pl < 0);
            }

            // ---- Collapsible sections (remembered per browser) -----------------------------------------------
            var COLLAPSE_KEY = 'dbk-budget-editor-collapsed';
            function readCollapsed() { try { return JSON.parse(localStorage.getItem(COLLAPSE_KEY)) || {}; } catch (e) { return {}; } }
            function setSection(type, open) {
                $table.find('tr.be-row[data-type="' + type + '"]').toggle(open);
                $table.find('[data-section-toggle="' + type + '"]').attr('aria-expanded', open ? 'true' : 'false')
                    .closest('tr').toggleClass('be-collapsed', !open);
            }
            var collapsed = readCollapsed();
            Object.keys(collapsed).forEach(function (type) { if (collapsed[type]) setSection(type, false); });
            $table.on('click', '[data-section-toggle]', function () {
                var type = $(this).data('section-toggle'), open = $(this).attr('aria-expanded') !== 'true';
                setSection(type, open);
                collapsed[type] = !open;
                try { localStorage.setItem(COLLAPSE_KEY, JSON.stringify(collapsed)); } catch (e) {}
            });

            if ($.fn.tooltip) {
                $table.find('[data-toggle="tooltip"]').tooltip({container: 'body'});
            }

            recalcTotals();

            if (CFG.readonly) return;

            // ---- Cell states, errors and the badge of the whole budget ---------------------------------------
            var jobs = [];       // requests waiting for their turn (one request at a time, in order)
            var busy = false;    // a request is on its way
            var queued = {};     // key => true while a request for the cell waits (it reads the latest value)
            var inflight = {};   // key => amount being saved
            var failed = {};     // key => message after a failed save (network, server)
            var conflicts = {};  // key => true after the server refused the cell as changed elsewhere
            var invalid = {};    // key => problem ('invalid' | 'negative')
            var offline = false; // the last request failed without an answer

            function isDirty(account, m) { return Math.abs((vals[account][m] || 0) - (base[account][m] || 0)) >= 0.005; }

            function hasUnsaved() {
                if (Object.keys(invalid).length) return true;
                for (var account in vals) {
                    for (var m = 1; m <= 12; m++) {
                        if (isDirty(account, m)) return true;
                    }
                }
                return false;
            }

            function errorKeys() {
                var all = {};
                [invalid, failed, conflicts].forEach(function (o) { Object.keys(o).forEach(function (k) { all[k] = true; }); });
                return Object.keys(all);
            }

            function refreshState() {
                var text, cls, errors = errorKeys().length;
                if (busy || jobs.length) {
                    text = CFG.t.cell.saving; cls = 'badge-warning';
                } else if (errors) {
                    text = offline ? CFG.t.messages.offline_state : CFG.t.cell.error; cls = 'badge-danger';
                } else if (hasUnsaved()) {
                    text = CFG.t.messages.unsaved; cls = 'badge-warning';
                } else {
                    text = CFG.t.messages.saved_state; cls = 'badge-light';
                }
                $('#be-state').removeClass('badge-light badge-warning badge-danger').addClass(cls).text(text);

                // Error summary above the table (WCAG 3.3.1): how many cells need attention + jump to the first
                var $bar = $('#be-errors');
                if (errors) {
                    var msg = errors === 1 ? CFG.t.errors.one : tr(CFG.t.errors.many, {count: errors});
                    if (offline) msg += ' ' + CFG.t.messages.offline_retry;
                    $('#be-errors-text').text(msg);
                    $bar.prop('hidden', false);
                } else {
                    $bar.prop('hidden', true);
                }
            }

            // The indicator inside a cell: state = saving | saved | error | null (remove)
            function cellState(account, m, state, detail) {
                var $td = $cell(account, m), $in = $td.find('input.be-input');
                clearTimeout($td.data('csTimer'));
                $td.find('.be-cs').remove();
                $in.toggleClass('be-invalid', state === 'error').attr('aria-invalid', state === 'error' ? 'true' : null);
                if (state === 'error' && detail) {
                    $in.attr('title', detail);
                } else {
                    $in.removeAttr('title');
                }
                if (!state) return;
                var label = CFG.t.cell[state] + (state === 'error' && detail ? ': ' + detail : '');
                var icon = {saving: 'fa-spinner fa-spin', saved: 'fa-check', error: 'fa-exclamation-circle'}[state];
                $('<span>').addClass('be-cs be-cs-' + state).attr({title: label})
                    .append($('<i>').addClass('fa ' + icon).attr('aria-hidden', 'true'))
                    .append($('<span>').addClass('sr-only').text(label))
                    .appendTo($td);
                if (state === 'saved') {
                    $td.data('csTimer', setTimeout(function () {
                        $td.find('.be-cs-saved').fadeOut(250, function () { $(this).remove(); });
                    }, 2000));
                }
            }

            function clearProblems(k) {
                delete failed[k];
                delete conflicts[k];
                delete invalid[k];
            }

            // Reads an input into the model; returns false when its value is not a valid amount
            function readInput($in) {
                var account = accountOf($in), m = $in.data('month'), k = key(account, m), problem = amtProblem($in.val());
                if (problem) {
                    invalid[k] = problem;
                    vals[account][m] = 0; // the totals ignore an invalid cell
                    return false;
                }
                delete invalid[k];
                vals[account][m] = parseAmt($in.val());
                return true;
            }

            // ---- Save queue ---------------------------------------------------------------------------------
            function noRequest() { return $.Deferred().resolve().promise(); }

            function enqueue(job) {
                jobs.push(job);
                pump();
                refreshState();
            }

            function pump() {
                if (busy || !jobs.length) return;
                busy = true;
                var request;
                try {
                    request = jobs.shift().run();
                } catch (e) {
                    request = $.Deferred().reject().promise();
                }
                request.always(function () {
                    busy = false;
                    pump();
                    refreshState();
                });
            }

            // Message for a request that got no usable answer
            function failText(xhr) {
                if (!xhr || xhr.status === 0) { offline = true; return CFG.t.messages.offline; }
                offline = false;
                if (xhr.status === 419 || xhr.status === 401) return CFG.t.messages.session_expired;
                if (xhr.status === 403 || xhr.status === 404) return CFG.t.messages.no_access;
                return CFG.t.messages.save_failed;
            }

            function onConflict(account, m, current) {
                var k = key(account, m);
                base[account][m] = current;
                conflicts[k] = true;
                cellState(account, m, 'error', tr(CFG.t.messages.conflict_cell, {current: fmt(current)}));
            }

            // One cell, with the value it has when its turn comes
            function runCell(account, m) {
                var k = key(account, m);
                delete queued[k];
                if (invalid[k]) return noRequest(); // became invalid while waiting
                var n = vals[account][m];
                if (!isDirty(account, m)) { // changed back to the stored amount while waiting
                    cellState(account, m, null);
                    return noRequest();
                }
                var expected = base[account][m];
                inflight[k] = n;
                cellState(account, m, 'saving');

                return $.ajax({
                    url: CFG.cellUrl, type: 'POST', dataType: 'json',
                    headers: {'X-CSRF-TOKEN': CFG.csrf},
                    data: {_method: 'PUT', account_id: account, month: m, amount: n, expected: expected}
                }).done(function (res) {
                    offline = false;
                    if (res.status === 'success') {
                        base[account][m] = res.amount;
                        delete conflicts[k];
                        cellState(account, m, isDirty(account, m) ? null : 'saved');
                        return;
                    }
                    if (res.error_name === 'conflict' && res.data) {
                        onConflict(account, m, parseFloat(res.data.current) || 0);
                        notify('warning', CFG.t.messages.conflict);
                        return;
                    }
                    failed[k] = res.message || CFG.t.messages.save_failed;
                    cellState(account, m, 'error', failed[k]);
                    notify('error', failed[k]);
                }).fail(function (xhr) {
                    failed[k] = failText(xhr);
                    cellState(account, m, 'error', failed[k]);
                    notify('error', failed[k]);
                }).always(function () {
                    delete inflight[k];
                    // edited again while the request was on its way: save the newer value too
                    if (!invalid[k] && !queued[k] && !failed[k] && !conflicts[k] && isDirty(account, m) && document.activeElement !== $input(account, m)[0]) {
                        commit(account, m);
                    }
                });
            }

            // Several cells in one request (paste, row buttons, "Save", retry). Only the cells that still differ
            // from the stored amounts when the turn comes are sent, each with the amount it expects on the server.
            function runBatch(list, opts) {
                opts = opts || {};
                var cells = {}, expected = {}, sent = [];
                list.forEach(function (c) {
                    var k = key(c.account, c.month);
                    if (invalid[k] || !isDirty(c.account, c.month)) return;
                    (cells[c.account] = cells[c.account] || {})[c.month] = vals[c.account][c.month];
                    (expected[c.account] = expected[c.account] || {})[c.month] = base[c.account][c.month];
                    sent.push({account: c.account, month: c.month, n: vals[c.account][c.month]});
                    cellState(c.account, c.month, 'saving');
                });
                if (!sent.length) {
                    if (opts.button) {
                        opts.button.prop('disabled', false);
                        notify(errorKeys().length ? 'error' : 'success', errorKeys().length ? CFG.t.messages.invalid_cells : CFG.t.messages.nothing_to_save);
                    }
                    return noRequest();
                }

                return $.ajax({
                    url: CFG.saveUrl, type: 'POST', dataType: 'json',
                    headers: {'X-CSRF-TOKEN': CFG.csrf},
                    data: {cells: cells, expected: expected}
                }).done(function (res) {
                    offline = false;
                    if (res.status === 'success') {
                        var refused = {};
                        (res.conflicts || []).forEach(function (c) {
                            refused[key(c.account_id, c.month)] = true;
                            onConflict(String(c.account_id), c.month, parseFloat(c.current) || 0);
                        });
                        sent.forEach(function (c) {
                            var k = key(c.account, c.month);
                            if (refused[k]) return;
                            base[c.account][c.month] = c.n;
                            delete failed[k];
                            delete conflicts[k];
                            cellState(c.account, c.month, isDirty(c.account, c.month) ? null : 'saved');
                        });
                        if (res.conflicts && res.conflicts.length) {
                            notify('warning', CFG.t.messages.conflict);
                        } else if (opts.button) {
                            notify('success', res.message);
                        }
                        return;
                    }
                    sent.forEach(function (c) {
                        failed[key(c.account, c.month)] = res.message || CFG.t.messages.save_failed;
                        cellState(c.account, c.month, 'error', failed[key(c.account, c.month)]);
                    });
                    notify('error', res.message || CFG.t.messages.save_failed);
                }).fail(function (xhr) {
                    var text = failText(xhr);
                    sent.forEach(function (c) {
                        failed[key(c.account, c.month)] = text;
                        cellState(c.account, c.month, 'error', text);
                    });
                    notify('error', text);
                }).always(function () {
                    if (opts.button) opts.button.prop('disabled', false);
                });
            }

            function commit(account, m) {
                var k = key(account, m);
                if (invalid[k]) {
                    cellState(account, m, 'error', problemText(invalid[k]));
                    refreshState();
                    return;
                }
                if (queued[k]) return; // the waiting request reads the latest value
                if (inflight.hasOwnProperty(k) && Math.abs(inflight[k] - vals[account][m]) < 0.005) return; // already on its way
                if (!inflight.hasOwnProperty(k) && !isDirty(account, m)) { // nothing to save
                    delete failed[k];
                    delete conflicts[k];
                    if ($cell(account, m).find('.be-cs-error').length) cellState(account, m, null);
                    refreshState();
                    return;
                }
                delete failed[k];
                queued[k] = true;
                enqueue({run: function () { return runCell(account, m); }});
            }

            function commitMany(list, opts) {
                list.forEach(function (c) { delete failed[key(c.account, c.month)]; });
                enqueue({run: function () { return runBatch(list, opts); }});
            }

            function allDirtyOrFailed() {
                var list = [];
                Object.keys(vals).forEach(function (account) {
                    if (!rows[account].find('input.be-input').length) return;
                    for (var m = 1; m <= 12; m++) {
                        var k = key(account, m);
                        if (!invalid[k] && (isDirty(account, m) || failed[k])) list.push({account: account, month: m});
                    }
                });
                return list;
            }

            // Back online: retry what failed
            window.addEventListener('online', function () {
                if (!offline) return;
                offline = false;
                var list = allDirtyOrFailed().filter(function (c) { return failed[key(c.account, c.month)]; });
                if (list.length) commitMany(list);
                refreshState();
            });

            // ---- Writing values into the grid ---------------------------------------------------------------
            function setValue(account, m, n) {
                var $in = $input(account, m);
                if (!$in.length) return false;
                $in.val(n ? fmt(n) : '');
                clearProblems(key(account, m));
                vals[account][m] = n;
                cellState(account, m, null);
                return true;
            }

            // Row buttons: write the values and save them right away (one request)
            function setRowValues(account, values) {
                var list = [];
                for (var m = 1; m <= 12; m++) {
                    if (values[m] !== undefined && setValue(account, m, values[m])) list.push({account: account, month: m});
                }
                paintRow(account);
                recalcTotals();
                commitMany(list);
            }

            // ---- Focus: keep the focused cell clear of the sticky header and columns (WCAG 2.4.11) -------------
            function ensureVisible(el) {
                if (!wrap) return;
                var td = el.closest('td') || el, w = wrap.getBoundingClientRect(), r = td.getBoundingClientRect();
                var left = 0, top = 0, pad = 8;
                $table.find('thead th.be-sticky').each(function () {
                    if (getComputedStyle(this).position === 'sticky' && this.offsetParent !== null) left = Math.max(left, this.getBoundingClientRect().right - w.left);
                });
                var thead = $table.find('thead')[0];
                if (thead) top = thead.getBoundingClientRect().height;
                if (r.left < w.left + left) wrap.scrollLeft -= (w.left + left - r.left) + pad;
                else if (r.right > w.left + wrap.clientWidth) wrap.scrollLeft += r.right - (w.left + wrap.clientWidth) + pad;
                if (r.top < w.top + top) wrap.scrollTop -= (w.top + top - r.top) + pad;
                else if (r.bottom > w.top + wrap.clientHeight) wrap.scrollTop += r.bottom - (w.top + wrap.clientHeight) + pad;
            }

            // ---- Events ---------------------------------------------------------------------------------------
            $table.on('input', 'input.be-input', function () {
                var $in = $(this), account = accountOf($in), m = $in.data('month'), k = key(account, m);
                var ok = readInput($in);
                delete failed[k];
                delete conflicts[k];
                $in.toggleClass('be-invalid', !ok);
                if ($cell(account, m).find('.be-cs-error').length && ok) cellState(account, m, null);
                paintRow(account);
                recalcTotals();
                refreshState();
            });

            // Autosave: the amount is stored when the cell is left (change / blur / Enter / arrows / Tab)
            $table.on('change', 'input.be-input', function () {
                commit(accountOf($(this)), $(this).data('month'));
            });

            $table.on('focus', 'input.be-input', function () {
                lastMonth[accountOf($(this))] = $(this).data('month');
                ensureVisible(this);
                this.select();
            });

            $table.on('blur', 'input.be-input', function () {
                var $in = $(this), n = parseAmt($in.val());
                if (n !== null && !invalid[key(accountOf($in), $in.data('month'))]) $in.val(n ? fmt(n) : '');
                commit(accountOf($in), $in.data('month'));
            });

            function editableRows() {
                return $table.find('tr.be-row:visible').filter(function () { return $(this).find('input.be-input').length; });
            }

            function focusCell($tr, month) {
                var $target = $tr.find('input.be-input[data-month="' + month + '"]');
                if ($target.length) $target.trigger('focus');
            }

            // Spreadsheet keys: Enter / Shift+Enter / ↑ ↓ — row; ← → — month (when the text is selected or the caret
            // is at the edge); Esc — back to the stored amount; F2 — edit inside the text
            $table.on('keydown', 'input.be-input', function (e) {
                var el = this, $in = $(this), account = accountOf($in), month = $in.data('month');
                var len = el.value.length, allSelected = el.selectionStart === 0 && el.selectionEnd === len;

                if (e.key === 'Escape') {
                    e.preventDefault();
                    setValue(account, month, base[account][month]);
                    paintRow(account);
                    recalcTotals();
                    refreshState();
                    el.select();
                    return;
                }
                if (e.key === 'F2') {
                    e.preventDefault();
                    el.setSelectionRange(len, len);
                    return;
                }
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    var atEdge = e.key === 'ArrowLeft' ? (el.selectionStart === 0 && el.selectionEnd === 0) : (el.selectionStart === len && el.selectionEnd === len);
                    if (!(allSelected || atEdge) || e.shiftKey) return;
                    var target = month + (e.key === 'ArrowLeft' ? -1 : 1);
                    if (target < 1 || target > 12) return;
                    e.preventDefault();
                    commit(account, month);
                    focusCell($in.closest('tr'), target);
                    return;
                }
                if (e.key !== 'Enter' && e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
                e.preventDefault();
                commit(account, month); // also at the first / last row, where the focus does not move
                var up = e.key === 'ArrowUp' || (e.key === 'Enter' && e.shiftKey);
                var $rows = editableRows();
                var idx = $rows.index($in.closest('tr')) + (up ? -1 : 1);
                if (idx >= 0 && idx < $rows.length) focusCell($rows.eq(idx), month);
            });

            // Paste of a block of cells copied from Excel / Google Sheets (tab-separated columns, one line per row)
            $table.on('paste', 'input.be-input', function (e) {
                var cd = (e.originalEvent || e).clipboardData, text = cd ? cd.getData('text/plain') : '';
                if (!text) return;
                text = text.replace(/\r\n?/g, '\n').replace(/\n+$/, '');
                if (text.indexOf('\t') === -1 && text.indexOf('\n') === -1) {
                    // one value: clean it here, so "1 234,56 €" or a trailing line break from Excel are accepted
                    e.preventDefault();
                    $(this).val(text.trim()).trigger('input');
                    commit(accountOf($(this)), $(this).data('month'));
                    return;
                }
                e.preventDefault();

                var $start = $(this), $allRows = $table.find('tr.be-row:visible');
                var r0 = $allRows.index($start.closest('tr')), m0 = $start.data('month');
                var lines = text.split('\n'), list = [], pasted = 0, skipped = 0, bad = 0, touchedRows = {};

                lines.forEach(function (line, i) {
                    var $tr = $allRows.eq(r0 + i), parts = line.split('\t');
                    parts.forEach(function (raw, j) {
                        var m = m0 + j;
                        if (r0 + i >= $allRows.length || m > 12 || !$tr.find('input.be-input').length) { skipped++; return; }
                        var account = String($tr.data('account')), $in = $input(account, m);
                        touchedRows[account] = true;
                        var problem = amtProblem(raw);
                        if (problem) {
                            $in.val(String(raw).trim());
                            invalid[key(account, m)] = problem;
                            vals[account][m] = 0;
                            cellState(account, m, 'error', problemText(problem));
                            bad++;
                            return;
                        }
                        setValue(account, m, parseAmt(raw));
                        list.push({account: account, month: m});
                        pasted++;
                    });
                });

                Object.keys(touchedRows).forEach(paintRow);
                recalcTotals();
                if (list.length) commitMany(list);
                refreshState();

                var msg = tr(CFG.t.messages.pasted, {count: pasted});
                if (skipped) msg += ' ' + tr(CFG.t.messages.pasted_skipped, {count: skipped});
                if (bad) msg += ' ' + tr(CFG.t.messages.pasted_invalid, {count: bad});
                notify(bad ? 'warning' : 'success', msg);
            });

            function ask(opts) {
                return Swal.fire({
                    title: opts.title, text: opts.text, input: 'text', inputPlaceholder: opts.placeholder,
                    showCancelButton: true, confirmButtonText: CFG.t.confirm, cancelButtonText: CFG.t.cancel,
                    inputValidator: function (v) {
                        var n = opts.signed ? parseSignedAmt(v) : parseAmt(v);
                        if (n === null || String(v).trim() === '') return opts.signed ? CFG.t.messages.invalid_percent : problemText(amtProblem(v) || 'invalid');
                        if (opts.signed && n < -100) return CFG.t.messages.invalid_percent;
                    }
                }).then(function (r) { return r.isConfirmed ? r.value : null; });
            }

            function rowName($tr) { return $.trim($tr.find('.be-name').first().text().replace(/\s+/g, ' ')); }

            // Row button: spread the annual amount evenly (the last month takes the rounding remainder)
            $table.on('click', '.be-act-distribute', function () {
                var $tr = $(this).closest('tr'), account = String($tr.data('account'));
                ask({
                    title: CFG.t.prompts.distribute_title,
                    text: tr(CFG.t.prompts.distribute_text, {account: rowName($tr)}),
                    placeholder: CFG.t.prompts.placeholder
                }).then(function (value) {
                    if (value === null) return;
                    var cents = Math.round(parseAmt(value) * 100), each = Math.floor(cents / 12), v = {}, used = 0;
                    for (var m = 1; m <= 11; m++) { v[m] = each / 100; used += each; }
                    v[12] = (cents - used) / 100;
                    setRowValues(account, v);
                });
            });

            // Row button: +X% on the actual amounts of last year (per month)
            $table.on('click', '.be-act-grow', function () {
                var $tr = $(this).closest('tr'), account = String($tr.data('account'));
                var ly = CFG.lastYear[account] || {}, has = false, m;
                for (m = 1; m <= 12; m++) { if (parseFloat(ly[m]) > 0) has = true; }
                if (!has) {
                    notify('info', tr(CFG.t.messages.no_last_year, {year: CFG.lyYear}));
                    return;
                }
                ask({
                    title: CFG.t.prompts.grow_title,
                    text: tr(CFG.t.prompts.grow_text, {year: CFG.lyYear, account: rowName($tr)}),
                    placeholder: CFG.t.prompts.placeholder_pct,
                    signed: true
                }).then(function (value) {
                    if (value === null) return;
                    var pct = parseSignedAmt(value), v = {};
                    for (var i = 1; i <= 12; i++) {
                        v[i] = Math.max(0, Math.round((parseFloat(ly[i]) || 0) * (1 + pct / 100) * 100) / 100);
                    }
                    setRowValues(account, v);
                });
            });

            // Row button: copy the last edited / selected month into the following months
            $table.on('click', '.be-act-copy', function () {
                var $tr = $(this).closest('tr'), account = String($tr.data('account')), from = lastMonth[account];
                if (!from) {
                    notify('info', CFG.t.messages.copy_pick_month);
                    return;
                }
                if (invalid[key(account, from)]) {
                    notify('error', problemText(invalid[key(account, from)]));
                    return;
                }
                if (from === 12) {
                    notify('info', CFG.t.messages.copy_last_month);
                    return;
                }
                var n = vals[account][from], v = {};
                for (var m = from + 1; m <= 12; m++) v[m] = n;
                setRowValues(account, v);
            });

            // "Save": every unsaved or failed cell in one request (queued behind the cell requests)
            $('#be-save').on('click', function () {
                var $btn = $(this);
                if (Object.keys(invalid).length) {
                    notify('error', CFG.t.messages.invalid_cells);
                    focusFirstError();
                    return;
                }
                $btn.prop('disabled', true);
                commitMany(allDirtyOrFailed(), {button: $btn});
            });

            function focusFirstError() {
                var keys = errorKeys();
                if (!keys.length) return;
                var first = null;
                $table.find('tr.be-row').each(function () {
                    var account = String($(this).data('account'));
                    for (var m = 1; m <= 12; m++) {
                        if (keys.indexOf(key(account, m)) !== -1) { first = {account: account, month: m}; return false; }
                    }
                });
                if (!first) return;
                var type = types[first.account];
                if ($table.find('[data-section-toggle="' + type + '"]').attr('aria-expanded') !== 'true') setSection(type, true);
                $input(first.account, first.month).trigger('focus');
            }

            $('#be-errors-first').on('click', focusFirstError);

            $(window).on('beforeunload', function () {
                return busy || jobs.length || hasUnsaved() || errorKeys().length ? CFG.t.messages.confirm_discard : undefined;
            });

            refreshState();
        })();
    </script>
@endpush
@endif
