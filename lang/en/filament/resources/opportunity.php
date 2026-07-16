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
                    'label' => 'Import opportunities',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
            ],
        ],
        'view' => [
            'actions' => [
                'edit' => [
                    'label' => 'Edit',
                ],
                'view_emails' => [
                    'label' => 'Emails',
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
                    'name' => [
                        'label' => '',
                    ],
                    'company' => [
                        'label' => 'Company',
                    ],
                    'contact' => [
                        'label' => 'Point of Contact',
                    ],
                ],
            ],
            'communication_intelligence' => [
                'fields' => [
                    'last_interaction' => [
                        'label' => 'Last Interaction',
                        'placeholder' => 'Never',
                    ],
                    'last_email' => [
                        'label' => 'Last Email',
                        'placeholder' => 'Never',
                    ],
                    'days_since_last_email' => [
                        'label' => 'Days Since Last Email',
                        'value' => ':days days ago',
                        'empty' => 'No emails yet',
                    ],
                    'email_count' => [
                        'label' => 'Total Emails',
                    ],
                ],
            ],
        ],
    ],
];
