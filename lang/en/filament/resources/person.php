<?php

declare(strict_types=1);

return [
    // Lowercase singular/plural so Filament's "New :label" button reads naturally.
    'label' => 'person',
    'plural_label' => 'people',
    'navigation_label' => 'People',

    'fields' => [
        'name' => [
            'label' => 'Person',
        ],
        'company' => [
            'label' => 'Company',
        ],
        'company_id' => [
            'label' => 'Company',
        ],
        'account_owner_id' => [
            'label' => 'Account Owner',
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

    'empty_state' => [
        'description' => 'People you add or import appear here.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Import people',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
                'export' => [
                    'label' => 'Export people',
                    'heading' => 'Export People',
                ],
                'create_company' => [
                    'label' => 'Create Company',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'company' => [
                        'label' => 'Company',
                    ],
                ],
            ],
        ],
    ],
];
