<?php

declare(strict_types=1);

return [
    // Lowercase singular/plural so Filament's "New :label" button reads naturally.
    'label' => 'task',
    'plural_label' => 'tasks',
    'navigation_label' => 'Tasks',

    'fields' => [
        'title' => [
            'label' => 'Title',
        ],
        'assignees' => [
            'label' => 'Assignees',
            'placeholder' => 'Assign a member',
        ],
        'companies' => [
            'label' => 'Companies',
        ],
        'people' => [
            'label' => 'People',
        ],
        'opportunities' => [
            'label' => 'Opportunities',
        ],
        'creator' => [
            'label' => 'Created By',
        ],
        'created_at' => [
            'label' => 'Created at',
        ],
        'updated_at' => [
            'label' => 'Updated at',
        ],
        'deleted_at' => [
            'label' => 'Deleted at',
        ],
    ],

    'actions' => [
        'copy_url' => 'Copy task URL',
        'copy_id' => 'Copy task ID',
    ],

    'empty_state' => [
        'description' => 'Tasks you add appear here.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Import tasks',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
                'export' => [
                    'label' => 'Export tasks',
                    'heading' => 'Export Tasks',
                ],
            ],
        ],
    ],
];
