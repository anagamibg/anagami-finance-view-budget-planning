@if (databackd_module('databackd_planning')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.planning.forecasts.index')" :text="__('databackd-planning-forecasts.menu')" />
@endif
