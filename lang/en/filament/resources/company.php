<?php

declare(strict_types=1);

return [
    // Filament title-cases the singular/plural labels for display contexts
    // (navigation menu, page headings) but injects them raw into the
    // "New :label" create button. Keep lowercase here so the button reads
    // "New company" while titles render as "Company"/"Companies".
    'label' => 'company',
    'plural_label' => 'companies',
    'navigation_label' => 'Companies',

    'fields' => [
        'name' => [
            'label' => 'Company',
        ],
        'account_owner' => [
            'label' => 'Account Owner',
        ],
        'account_owner_id' => [
            'label' => 'Account Owner',
        ],
        'created_by' => [
            'label' => 'Created By',
        ],
        'creator' => [
            'label' => 'Created By',
        ],
        'creation_source' => [
            'label' => 'Creation Source',
        ],
        'created_at' => [
            'label' => 'Creation Date',
        ],
        'updated_at' => [
            'label' => 'Last Update',
        ],
        'deleted_at' => [
            'label' => 'Deleted At',
        ],
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Import companies',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'account_owner' => [
                        'label' => 'Account Owner',
                    ],
                ],
            ],
            'activity_log' => [
                'description' => 'All activity for this company, grouped by week.',
            ],
        ],
    ],

    'relation_managers' => [
        'people' => [
            'model_label' => 'person',
        ],
    ],
];
