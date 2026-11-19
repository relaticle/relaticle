<?php

declare(strict_types=1);

return [
    // Lowercase singular/plural so Filament's "New :label" button reads naturally.
    'label' => 'opportunity',
    'plural_label' => 'opportunities',
    'navigation_label' => 'Opportunities',

    'fields' => [
        'name' => [
            'label' => 'Opportunity',
            'placeholder' => 'Enter opportunity title',
        ],
        'company_id' => [
            'label' => 'Company',
        ],
        'contact_id' => [
            'label' => 'Point of Contact',
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
        'description' => 'Opportunities you add or import appear here.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Import opportunities',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
                'export' => [
                    'label' => 'Export opportunities',
                    'heading' => 'Export Opportunities',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'company' => [
                        'label' => 'Company',
                    ],
                    'contact' => [
                        'label' => 'Point of Contact',
                    ],
                ],
            ],
        ],
    ],
];
