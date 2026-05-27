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

    'communication_intelligence' => [
        'fields' => [
            'last_interaction_at' => [
                'label' => 'Last Interaction',
                'placeholder' => 'Never',
            ],
            'last_email_at' => [
                'label' => 'Last Email',
                'placeholder' => 'Never',
            ],
            'days_since_last_email' => [
                'label' => 'Days Since Last Email',
                'days_ago' => ':count days ago',
                'no_emails_yet' => 'No emails yet',
            ],
            'email_count' => [
                'label' => 'Total Emails',
            ],
            'inbound_email_count' => [
                'label' => 'Received',
            ],
            'outbound_email_count' => [
                'label' => 'Sent',
            ],
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
                'view_emails' => [
                    'label' => 'Emails',
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
