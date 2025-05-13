{{--
    List of saved forecasts (side panel of the forecast screen).
    $saved        Collection of App\Models\Databackd\FinForecast (with creator, metric_title)
    $activeId     id of the forecast currently open (or null)
    $canDeleteAll true for admins; other users delete only their own forecasts
    $userId       id of the current user
--}}
@forelse ($saved as $f)
    <div class="dbk-saved-item @if ((int)$activeId === (int)$f->id) dbk-saved-active @endif" id="saved-forecast-{{ $f->id }}">
        <div class="d-flex justify-content-between align-items-start">
            <div class="pr-2" style="min-width: 0">
                <div class="dbk-saved-name" title="{{ $f->name }}">{{ $f->name }}</div>
                <div class="dbk-saved-metric">{{ $f->metric_title }}</div>
            </div>
            @if ((int)$activeId === (int)$f->id)
                <span class="badge dbk-badge is-info no-dot">@lang('databackd-planning-forecasts.active')</span>
            @endif
        </div>

        <div class="dbk-saved-meta mt-1">
            {{ __('databackd-planning-common.method.' . $f->method) }}
            · {{ $f->horizon }} @lang('databackd-planning-forecasts.months_suffix')
            · {{ $f->entity->name ?? __('databackd-planning-common.all_entities') }}
        </div>

        <div class="d-flex justify-content-between align-items-end mt-2" style="gap: 8px">
            <div class="dbk-saved-meta" style="min-width: 0">
                {{ $f->created_at ? $f->created_at->format('d.m.Y') : '' }}
                @if ($f->creator)
                    · @lang('databackd-planning-forecasts.saved_by', ['name' => $f->creator->name])
                @endif
            </div>
            <div class="dbk-planning-actions">
                <a href="{{ route('databackd.planning.forecasts.show', $f->id) }}" class="btn btn-sm btn-outline-primary"
                   title="@lang('databackd-planning-forecasts.open')" aria-label="@lang('databackd-planning-forecasts.open')">
                    <i class="fa fa-folder-open"></i>
                </a>
                @if ($canDeleteAll || (int)$f->created_by === (int)$userId)
                    <button type="button" class="btn btn-sm btn-outline-danger delete-forecast"
                            data-id="{{ $f->id }}" data-name="{{ $f->name }}"
                            title="@lang('databackd-planning-forecasts.delete')" aria-label="@lang('databackd-planning-forecasts.delete')">
                        <i class="fa fa-trash"></i>
                    </button>
                @endif
            </div>
        </div>
    </div>
@empty
    <x-dbk.empty icon="bookmark" size="sm" :text="__('databackd-planning-forecasts.saved_empty')"/>
@endforelse
