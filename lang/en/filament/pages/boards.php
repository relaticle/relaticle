<?php

declare(strict_types=1);

return [
    'view_switcher' => [
        'board' => 'Board',
        'cards' => 'Cards',
        'opportunity_board' => 'Pipeline',
    ],

    'stage_order_failed' => 'The stage order could not be saved.',

    'view_settings' => [
        'panel' => 'Board view settings',
        'stage_actions' => 'Actions for :stage',
        'grouped_by' => 'Grouped by',
        'grouped_by_heading' => 'Group columns by',
        'lanes' => 'Lanes',
        'no_lanes' => 'No lanes',
        'no_lanes_short' => 'None',
        'visible_columns' => 'Visible columns',
        'show_column' => 'Show :column',
        'last_column' => 'One column stays visible.',
        'no_value_column' => 'No :attribute',
        'show_labels' => 'Show attribute labels',
        'hide_empty' => 'Hide empty attributes',
        'visible_attributes' => 'Visible attributes',
        'attribute_actions' => 'Actions for :attribute',
        'remove_attribute' => 'Remove from card',
        'add_attribute' => 'Add attribute to view',
        'search_attributes' => 'Search :entity attributes',
        'all_attributes_shown' => 'Every attribute is on the card',
        'no_attribute_matches' => 'No attribute matches',
    ],

    'card_attributes' => [
        'description' => 'Description',
        'checklist' => 'Checklist',
        'empty' => 'Empty',
    ],

    'layout' => [
        'column_width' => 'Column width',
        'card_size' => 'Card size',
        'narrow' => 'Narrow columns',
        'wide' => 'Wide columns',
        'compact' => 'Compact cards',
        'full' => 'Full cards',
    ],

    'move_denied' => 'You do not have permission to move this card.',

    'sorted_no_reorder' => 'Cards are sorted by :field. Clear the sort to arrange them by hand.',

    'move_to' => [
        'label' => 'Move to',
        'moved' => 'Moved to :column',
    ],

    'opportunities' => [
        'title' => 'Opportunities',
        'actions' => [
            'add' => 'Add Opportunity',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
        'form' => [
            'name_placeholder' => 'Enter opportunity title',
        ],
        'groupings' => [
            'company' => 'Company',
            'no_company' => 'No company',
        ],
    ],

    'tasks' => [
        'title' => 'Tasks',
        'actions' => [
            'add' => 'Add Task',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
        'groupings' => [
            'assignee' => 'Assignee',
            'unassigned' => 'Unassigned',
        ],
        'checklist_progress' => 'Checklist progress',
        'checklist_progress_tooltip' => '{1} :checked of :total item done|[2,*] :checked of :total items done',
    ],
];
