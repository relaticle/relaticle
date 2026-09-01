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
                    'logo' => [
                        'label' => '',
                    ],
                    'creator' => [
                        'label' => 'Created By',
                    ],
                    'account_owner' => [
                        'label' => 'Account Owner',
                    ],
                    'created_at' => [
                        'label' => 'Created Date',
                    ],
                    'updated_at' => [
                        'label' => 'Last Updated',
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
                    'inbound_email_count' => [
                        'label' => 'Received',
                    ],
                    'outbound_email_count' => [
                        'label' => 'Sent',
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
        'notes' => [
            'fields' => [
                'people' => [
                    'label' => 'People',
                ],
            ],
        ],
        'tasks' => [
            'fields' => [
                'assignees' => [
                    'label' => 'Assignee',
                ],
                'people' => [
                    'label' => 'People',
                ],
                'created_at' => [
                    'label' => 'Created At',
                ],
            ],
        ],
    ],
];
