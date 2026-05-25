<?php

declare(strict_types=1);

return [
    // Lowercase singular/plural so Filament's "New :label" button reads naturally.
    'label' => 'task',
    'plural_label' => 'tasks',
    'navigation_label' => 'Tasks',

    'fields' => [
        'assignees' => [
            'label' => 'Assignees',
        ],
        'companies' => [
            'label' => 'Companies',
        ],
        'people' => [
            'label' => 'People',
        ],
        'creator' => [
            'label' => 'Created By',
        ],
        'created_at' => [
            'label' => 'Created At',
        ],
        'updated_at' => [
            'label' => 'Updated At',
        ],
        'deleted_at' => [
            'label' => 'Deleted At',
        ],
    ],

    'filters' => [
        'assigned_to_me' => [
            'label' => 'Assigned to me',
        ],
        'creation_source' => [
            'label' => 'Creation Source',
        ],
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
            ],
        ],
    ],
];
