@extends('layouts.app')

@php
    $money = function ($value, $currency) {
        $symbol = $currency === 'EUR' ? '€' : $currency;

        return number_format($value, 0, ',', ' ') . ' ' . $symbol;
    };
@endphp

@section('content')
    <div class="content-wrapper dbk-planning-budgets">
        <x-dbk.page-header :subtitle="__('databackd-planning-budgets.help')">
            <x-slot name="actions">
                <x-forms.link-primary :link="route('databackd.planning.budgets.create')" icon="plus">
                    @lang('databackd-planning-budgets.new_budget')
                </x-forms.link-primary>
            </x-slot>
        </x-dbk.page-header>

        <div class="dbk-kpi-grid">
            <x-dbk.kpi :label="__('databackd-planning-budgets.total_budgets')" :value="$totalBudgets" icon="bi-journal-text"/>
            <x-dbk.kpi :label="__('databackd-planning-budgets.drafts')" :value="$draftCount" icon="bi-pencil-square"/>
            <x-dbk.kpi :label="__('databackd-planning-budgets.approved')" :value="$approvedCount" icon="bi-check2-circle" tone="revenue"/>
            <x-dbk.kpi :label="__('databackd-planning-budgets.years')" :value="$yearCount" icon="bi-calendar3"/>
        </div>

        <x-dbk.card :title="__('databackd-planning-budgets.list_title')" body-class="p-0" class="mb-4" :header-border="true">
            @if ($totalBudgets > 0)
                <form method="GET" action="{{ route('databackd.planning.budgets.index') }}" class="dbk-planning-filterbar">
                    <div class="dbk-filter">
                        <label for="bud-filter-year">@lang('databackd-planning-budgets.filter_year')</label>
                        <select name="year" id="bud-filter-year" class="form-control height-35 f-14" onchange="this.form.submit()">
                            <option value="">@lang('databackd-planning-budgets.filter_year'): @lang('databackd-planning-budgets.filter_all')</option>
                            @foreach ($years as $y)
                                <option value="{{ $y }}" @selected($filterYear == $y)>{{ $y }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="dbk-filter">
                        <label for="bud-filter-version">@lang('databackd-planning-budgets.filter_version')</label>
                        <select name="version" id="bud-filter-version" class="form-control height-35 f-14" onchange="this.form.submit()">
                            <option value="">@lang('databackd-planning-budgets.filter_version'): @lang('databackd-planning-budgets.filter_all')</option>
                            @foreach (\App\Models\Databackd\FinBudget::VERSIONS as $v)
                                <option value="{{ $v }}" @selected($filterVersion == $v)>{{ __('databackd-planning-common.version.' . $v) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="dbk-filter">
                        <label for="bud-filter-entity">@lang('databackd-planning-budgets.filter_entity')</label>
                        <select name="entity_id" id="bud-filter-entity" class="form-control height-35 f-14" onchange="this.form.submit()">
                            <option value="">@lang('databackd-planning-budgets.filter_entity'): @lang('databackd-planning-budgets.filter_all')</option>
                            <option value="group" @selected($filterEntity === 'group')>@lang('databackd-planning-budgets.whole_group')</option>
                            @foreach ($entities as $e)
                                <option value="{{ $e->id }}" @selected($filterEntity == $e->id)>{{ $e->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($filtered)
                        <a href="{{ route('databackd.planning.budgets.index') }}" class="btn btn-sm btn-outline-secondary mb-1">
                            <i class="fa fa-times mr-1"></i>@lang('databackd-planning-budgets.filter_reset')
                        </a>
                    @endif
                </form>
            @endif

            @if ($budgets->isEmpty())
                <x-dbk.empty icon="journal-text"
                             :title="$filtered ? __('databackd-planning-budgets.empty_filtered') : __('databackd-planning-budgets.empty')"
                             :text="$filtered ? null : __('databackd-planning-budgets.empty_hint')">
                    @unless ($filtered)
                        <x-forms.link-primary :link="route('databackd.planning.budgets.create')" icon="plus">
                            @lang('databackd-planning-budgets.new_budget')
                        </x-forms.link-primary>
                    @endunless
                </x-dbk.empty>
            @else
                <div class="dbk-table-wrap is-flush">
                    <table class="dbk-table dbk-table--sticky-first dbk-planning-budgets-table" id="budgets-table">
                        <thead>
                        <tr>
                            <th>@lang('databackd-planning-budgets.name')</th>
                            <th>@lang('databackd-planning-budgets.year')</th>
                            <th>@lang('databackd-planning-budgets.entity')</th>
                            <th>@lang('databackd-planning-budgets.scenario')</th>
                            <th>@lang('databackd-planning-budgets.version')</th>
                            <th class="num">@lang('databackd-planning-budgets.revenue')</th>
                            <th class="num">@lang('databackd-planning-budgets.expense')</th>
                            <th class="num">@lang('databackd-planning-budgets.profit')</th>
                            <th>@lang('databackd-planning-budgets.author')</th>
                            <th class="num dbk-planning-col-actions">@lang('databackd-planning-budgets.actions')</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($budgets as $b)
                            @php
                                $t = $totals[$b->id];
                                $editUrl = $editorExists ? route('databackd.planning.budget-editor.edit', $b->id) : null;
                            @endphp
                            <tr id="budget-row-{{ $b->id }}">
                                <td>
                                    @if ($editUrl)
                                        <a href="{{ $editUrl }}" class="dbk-planning-name">{{ $b->name }}</a>
                                    @else
                                        <span class="font-weight-bold">{{ $b->name }}</span>
                                    @endif
                                    @if ($b->notes)
                                        <div class="dbk-planning-note" title="{{ $b->notes }}">{{ $b->notes }}</div>
                                    @endif
                                </td>
                                <td class="dbk-num">{{ $b->year }}</td>
                                <td>{{ $b->entity->name ?? __('databackd-planning-budgets.whole_group') }}</td>
                                <td>
                                    <span class="dbk-badge {{ ['base' => 'is-info', 'optimistic' => 'is-good', 'pessimistic' => 'is-warning'][$b->scenario] ?? '' }}">
                                        {{ __('databackd-planning-common.scenario.' . $b->scenario) }}
                                    </span>
                                </td>
                                <td>
                                    @if ($b->is_approved)
                                        <span class="dbk-badge no-dot is-good"
                                              title="{{ __('databackd-planning-budgets.approved_by_on', ['user' => $b->approver->name ?? '—', 'date' => $b->approved_at?->format('d.m.Y') ?? '—']) }}">
                                            <i class="fa fa-lock"></i>{{ __('databackd-planning-common.version.approved') }}
                                        </span>
                                    @else
                                        <span class="dbk-badge no-dot"><i class="fa fa-edit"></i>{{ __('databackd-planning-common.version.draft') }}</span>
                                    @endif
                                </td>
                                <td class="num">{{ $money($t['revenue'], $b->currency) }}</td>
                                <td class="num">{{ $money($t['expense'], $b->currency) }}</td>
                                <td class="num font-weight-bold {{ $t['profit'] < 0 ? 'neg' : 'pos' }}">{{ $money($t['profit'], $b->currency) }}</td>
                                <td>{{ $b->creator->name ?? '—' }}</td>
                                <td class="num dbk-planning-col-actions">
                                    <div class="dbk-planning-actions">
                                        @if ($editUrl)
                                            <a href="{{ $editUrl }}" class="btn btn-sm btn-outline-secondary"
                                               title="{{ $b->is_approved ? __('databackd-planning-budgets.view') : __('databackd-planning-budgets.edit') }}"
                                               aria-label="{{ $b->is_approved ? __('databackd-planning-budgets.view') : __('databackd-planning-budgets.edit') }}">
                                                <i class="fa fa-{{ $b->is_approved ? 'eye' : 'edit' }}"></i>
                                            </a>
                                        @endif
                                        <button type="button" class="btn btn-sm btn-outline-secondary duplicate-budget"
                                                data-id="{{ $b->id }}" data-name="{{ $b->name }}"
                                                title="@lang('databackd-planning-budgets.duplicate')" aria-label="@lang('databackd-planning-budgets.duplicate')">
                                            <i class="fa fa-copy"></i>
                                        </button>
                                        @if ($b->is_approved)
                                            <span class="d-inline-block" title="@lang('databackd-planning-budgets.locked')">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled><i class="fa fa-lock"></i></button>
                                            </span>
                                        @else
                                            <button type="button" class="btn btn-sm btn-outline-success approve-budget"
                                                    data-id="{{ $b->id }}" data-name="{{ $b->name }}"
                                                    title="@lang('databackd-planning-budgets.approve')" aria-label="@lang('databackd-planning-budgets.approve')">
                                                <i class="fa fa-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger delete-budget"
                                                    data-id="{{ $b->id }}" data-name="{{ $b->name }}"
                                                    title="@lang('databackd-planning-budgets.delete')" aria-label="@lang('databackd-planning-budgets.delete')">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-dbk.card>
    </div>
@endsection

@push('scripts')
    <script>
        function budgetAction(btn, urlTemplate, method, text, confirmLabel, icon) {
            var id = btn.data('id');
            var name = btn.data('name');

            Swal.fire({
                title: @json(__('databackd-planning-budgets.confirm_title')),
                text: text.replace(':name', function () { return name; }),
                icon: icon,
                showCancelButton: true,
                focusConfirm: false,
                confirmButtonText: confirmLabel,
                cancelButtonText: @json(__('databackd-planning-budgets.cancel')),
                customClass: {
                    confirmButton: 'btn btn-primary mr-3',
                    cancelButton: 'btn btn-secondary'
                },
                showClass: {popup: 'swal2-noanimation', backdrop: 'swal2-noanimation'},
                buttonsStyling: false
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                var data = {'_token': '{{ csrf_token() }}'};

                if (method === 'DELETE') {
                    data['_method'] = 'DELETE';
                }

                $.easyAjax({
                    type: 'POST',
                    url: urlTemplate.replace(':id', id),
                    blockUI: true,
                    data: data,
                    success: function (response) {
                        if (response.status == 'success') {
                            // a duplicate is opened in the editor right away, the other actions refresh the list
                            if (response.url) {
                                window.location.href = response.url;
                            } else {
                                window.location.reload();
                            }
                        }
                    }
                });
            });
        }

        $('body').on('click', '.delete-budget', function () {
            budgetAction($(this), "{{ route('databackd.planning.budgets.destroy', ':id') }}", 'DELETE',
                @json(__('databackd-planning-budgets.delete_confirm', ['name' => ':name'])),
                @json(__('databackd-planning-budgets.confirm_delete')), 'warning');
        });

        $('body').on('click', '.approve-budget', function () {
            budgetAction($(this), "{{ route('databackd.planning.budgets.approve', ':id') }}", 'POST',
                @json(__('databackd-planning-budgets.approve_confirm', ['name' => ':name'])),
                @json(__('databackd-planning-budgets.confirm_approve')), 'question');
        });

        $('body').on('click', '.duplicate-budget', function () {
            budgetAction($(this), "{{ route('databackd.planning.budgets.duplicate', ':id') }}", 'POST',
                @json(__('databackd-planning-budgets.duplicate_confirm', ['name' => ':name'])),
                @json(__('databackd-planning-budgets.confirm_duplicate')), 'question');
        });
    </script>
@endpush
