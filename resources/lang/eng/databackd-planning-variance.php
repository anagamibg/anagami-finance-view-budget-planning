<?php

return [
    'menu' => 'Budget vs Actual',
    'title' => 'Budget vs Actual',
    'subtitle' => 'Compare the planned amounts of a budget with the real turnover per account, with favorable and unfavorable variances.',

    'budget_label' => ':name (:year, :version)',
    'period_label' => ':period, :year',
    'export_excel' => 'Export to Excel',
    'export_title' => 'Budget vs Actual',

    'filters' => [
        'budget' => 'Budget',
        'mode' => 'Period',
        'month' => 'Month',
        'entity' => 'Legal entity',
        'region' => 'Region',
        'product_group' => 'Product group',
        'cost_center' => 'Cost center',
        'all' => 'All',
        'apply' => 'Show',
        'reset' => 'Clear filters',
    ],

    'modes' => [
        'month' => 'Single month',
        'ytd' => 'Year to date',
        'year' => 'Whole year',
    ],
    'ytd_period' => 'Jan - :month',
    'year_period' => 'Whole year',

    'columns' => [
        'account' => 'Account',
        'budget' => 'Budget',
        'actual' => 'Actual',
        'diff' => 'Variance',
        'diff_pct' => 'Variance %',
    ],

    'sections' => [
        'revenue' => 'Revenue',
        'expense' => 'Expenses',
    ],
    'subtotal' => 'Total :section',
    'profit' => 'Profit',

    'kpi' => [
        'budget' => 'Budget',
        'actual' => 'Actual',
    ],

    'chart' => [
        'title' => 'Budget vs Actual by Month',
        'budget' => 'Budget',
        'actual' => 'Actual',
        'hint' => 'The grey column is the budget for the month, the coloured one the actual amount. A coloured column above the grey one means more than planned.',
    ],

    'top' => [
        'title' => 'Largest Variances',
        'subtitle' => 'Accounts with the largest difference from the budget in the period',
        'empty' => 'No variances from the budget in the period',
    ],

    'notices' => [
        'no_actuals' => 'There are no actual figures for :year yet, so actual amounts are 0 and every variance is against an empty period.',
        'actuals_until' => 'Actual figures are available up to :month :year. For the later months of the period the actual amount is 0, so their variances are not real.',
        'current_month' => ':month is not over yet: actual amounts are up to today, while the budget covers the whole month.',
        'no_dimension_budget' => 'The budget has no breakdown by the selected dimension, so actual amounts are compared with a budget of 0.',
        'currency' => 'The budget is in :budget, while the actual figures are in :base. Amounts are not converted, so the comparison is only indicative.',
    ],

    'no_budget' => 'no budget',

    'legend' => [
        'favorable' => 'Favorable variance',
        'unfavorable' => 'Unfavorable variance',
        'hint' => 'Green means better than plan (revenue above plan or expenses below plan), red means worse. Variance = actual − budget.',
    ],

    'no_rows' => 'There are no budget or actual amounts for the selected period and filters.',

    'empty' => [
        'title' => 'There are no budgets yet',
        'text' => 'Create a budget for the year and this page will show where actual revenue and expenses differ from the plan.',
        'link' => 'Create budget',
    ],
];
