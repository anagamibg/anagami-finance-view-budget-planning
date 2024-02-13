@if (databackd_module('databackd_planning')) {{-- AFV (S8): package module --}}
@if (!in_array('client', user_roles()))
    <x-sub-menu-item :link="route('databackd.planning.budgets.index')" :text="__('databackd-planning-budgets.menu')"
                     data-dbk-match="account/databackd/planning/budget-editor" /> {{-- AFV ui (S12): the budget editor is part of "Budgets" --}}
@endif
@endif
