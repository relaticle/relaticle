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
