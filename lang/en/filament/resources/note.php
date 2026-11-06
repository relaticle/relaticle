<?php

declare(strict_types=1);

return [
    // Lowercase singular/plural so Filament's "New :label" button reads naturally.
    'label' => 'note',
    'plural_label' => 'notes',
    'navigation_label' => 'Notes',

    'fields' => [
        'title' => [
            'label' => 'Title',
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
            'label' => 'Created At',
        ],
        'updated_at' => [
            'label' => 'Updated At',
        ],
    ],

    'filters' => [
        'creation_source' => [
            'label' => 'Creation Source',
        ],
    ],

    'created_periods' => [
        'today' => 'Created today',
        'this_week' => 'Created this week',
        'this_month' => 'Created this month',
        'this_year' => 'Created this year',
        'earlier' => 'Created earlier',
    ],

    'cards' => [
        'untitled' => 'Untitled note',
        'no_content' => 'This note has no content.',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'deleted' => 'Deleted',
    ],

    'empty_state' => [
        'description' => 'Notes you add appear here.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Import notes',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
            ],
        ],
    ],
];
