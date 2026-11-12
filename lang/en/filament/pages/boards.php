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
        'lanes' => 'Lanes',
        'no_lanes' => 'No lanes',
    ],

    'layout' => [
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
        'stage_order' => 'Stage order',
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
        'stage_order' => 'Status order',
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
