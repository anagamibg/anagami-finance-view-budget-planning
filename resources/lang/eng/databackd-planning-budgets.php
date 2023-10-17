<?php

return [
    'menu' => 'Budgets',
    'title' => 'Budgets',
    'create_title' => 'New Budget',
    'help' => 'Annual budgets per legal entity or for the whole group. Totals are calculated from the budget lines: revenue accounts, expense accounts and the resulting profit.',

    // Summary
    'total_budgets' => 'Budgets',
    'drafts' => 'Drafts',
    'approved' => 'Approved',
    'years' => 'Years covered',

    // Filters
    'filter_year' => 'Year',
    'filter_version' => 'Version',
    'filter_entity' => 'Legal entity',
    'filter_all' => 'All',
    'filter_reset' => 'Reset',

    // List
    'list_title' => 'List of Budgets',
    'name' => 'Name',
    'year' => 'Year',
    'entity' => 'Legal entity',
    'scenario' => 'Scenario',
    'version' => 'Version',
    'revenue' => 'Revenue',
    'expense' => 'Expenses',
    'profit' => 'Profit',
    'author' => 'Author',
    'actions' => 'Actions',
    'approved_by_on' => 'Approved by :user on :date',
    'whole_group' => 'Whole group',
    'empty' => 'There are no budgets yet',
    'empty_filtered' => 'No budget matches the selected filters',
    'empty_hint' => 'Create the first budget - empty, from the actual data of a previous year or as a copy of another budget.',
    'new_budget' => 'New budget',

    // Row actions
    'edit' => 'Edit budget',
    'view' => 'View budget',
    'duplicate' => 'Duplicate',
    'approve' => 'Approve',
    'delete' => 'Delete',
    'locked' => 'Approved budgets cannot be changed or deleted',

    // Confirmations
    'confirm_title' => 'Are you sure?',
    'cancel' => 'Cancel',
    'confirm_delete' => 'Yes, delete',
    'delete_confirm' => 'Budget ":name" and all its lines will be deleted. This cannot be undone.',
    'confirm_approve' => 'Yes, approve',
    'approve_confirm' => 'Budget ":name" will be approved and can no longer be edited or deleted.',
    'confirm_duplicate' => 'Yes, duplicate',
    'duplicate_confirm' => 'A draft copy of budget ":name" with all its lines will be created.',

    // Messages
    'created' => 'The budget was created.',
    'created_no_actuals' => 'The budget was created empty: there is no actual revenue or expense for :year for the selected legal entity.',
    'deleted' => 'The budget was deleted.',
    'approved_ok' => 'The budget was approved.',
    'duplicated' => 'The budget was duplicated.',
    'only_draft_delete' => 'Only draft budgets can be deleted.',
    'already_approved' => 'The budget is already approved.',
    'copy_suffix' => '(copy)',

    // Create form
    'section_main' => 'Budget data',
    'section_start' => 'How to start',
    'name_placeholder' => 'e.g. Budget 2027 - base scenario',
    'currency' => 'Currency',
    'currency_help' => 'Three-letter code, e.g. EUR.',
    'currency_help_base' => 'The actual data is in :currency and is not converted to another currency.',
    'currency_actuals_base' => 'A budget from the actual data must be in :currency, the currency the data is recorded in.',
    'notes' => 'Notes',
    'notes_placeholder' => 'Assumptions, approval remarks, ...',
    'entity_help' => 'Leave "Whole group" for a consolidated budget.',

    'start_empty' => 'Empty budget',
    'start_empty_help' => 'Start with no lines and fill the plan in the budget editor.',
    'start_actuals' => 'Copy the actual data of a year',
    'start_actuals_help' => 'The actual revenue and expense per account and month of the chosen year, adjusted by a percentage.',
    'start_budget' => 'Copy another budget',
    'start_budget_help' => 'All lines of the chosen budget are copied 1:1.',

    'source_year' => 'Year of the actual data',
    'adjust_pct' => 'Adjustment, %',
    'adjust_pct_help' => 'Each amount is multiplied by (1 + % / 100). Use a negative value to reduce.',
    'fill_gaps' => 'Fill months without data',
    'fill_gaps_help' => 'Months of the source year without any data are filled from the same month of the previous year or the account average.',
    'source_budget' => 'Budget to copy',
    'no_budgets_to_copy' => 'There are no budgets to copy yet.',

    'save' => 'Create budget',
    'back' => 'Back to the list',

    'attributes' => [
        'name' => 'name',
        'year' => 'year',
        'entity_id' => 'legal entity',
        'scenario' => 'scenario',
        'currency' => 'currency',
        'notes' => 'notes',
        'start_option' => 'start option',
        'source_year' => 'year of the actual data',
        'adjust_pct' => 'adjustment',
        'source_budget_id' => 'budget to copy',
    ],
];
