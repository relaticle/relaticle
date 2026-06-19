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
        'creation_source' => [
            'label' => 'Creation Source',
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

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Import people',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
                'create_company' => [
                    'label' => 'Create Company',
                ],
            ],
        ],
        'view' => [
            'actions' => [
                'edit' => [
                    'label' => 'Edit',
                ],
                'ask_about_this' => [
                    'label' => 'Ask about this',
                ],
                'copy_page_url' => [
                    'label' => 'Copy page URL',
                ],
                'copy_record_id' => [
                    'label' => 'Copy record ID',
                ],
            ],
            'infolist' => [
                'fields' => [
                    'avatar' => [
                        'label' => '',
                    ],
                    'name' => [
                        'label' => '',
                    ],
                    'company' => [
                        'label' => 'Company',
                    ],
                ],
            ],
        ],
    ],
];
