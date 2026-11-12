<?php

declare(strict_types=1);

return [
    'view_switcher' => [
        'board' => 'Board',
        'cards' => 'Cards',
        'opportunity_board' => 'Pipeline',
    ],

    'view_settings' => [
        'panel' => 'Board view settings',
        'stage_actions' => 'Actions for :stage',
    ],

    'move_denied' => 'You do not have permission to move this card.',

    'move_to' => [
        'label' => 'Move to',
        'moved' => 'Moved to :column',
    ],

    'filters_button' => [
        'label' => 'Filters',
        'clear' => 'Clear filters',
    ],

    'opportunities' => [
        'title' => 'Opportunities',
        'stage_order' => 'Stage order',
        'actions' => [
            'add' => 'Add Opportunity',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
        'filters' => [
            'company' => 'Company',
            'contact' => 'Point of Contact',
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
        'filters' => [
            'assignee' => 'Assignee',
        ],
        'groupings' => [
            'assignee' => 'Assignee',
            'unassigned' => 'Unassigned',
        ],
        'checklist_progress' => 'Checklist progress',
        'checklist_progress_tooltip' => '{1} :checked of :total item done|[2,*] :checked of :total items done',
    ],
];
