<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Emails',
    ],

    'actions' => [
        'save' => [
            'label' => 'Save',
        ],
    ],

    'notifications' => [
        'saved' => [
            'title' => 'Privacy settings saved.',
        ],
    ],

    'workspace_default' => [
        'description' => 'Applied to all newly synced emails unless a team member sets their own preference.',
        'fields' => [
            'default_email_sharing_tier' => [
                'label' => 'Default Sharing Tier for Connected Email Accounts',
            ],
        ],
    ],

    'auto_hide_internal' => [
        'description' => 'Internal emails are automatically hidden from teammates\' views.',
        'fields' => [
            'internal_emails_info' => [
                'label' => '',
                'content' => 'Emails where every participant is a member of this workspace are classified as internal and are automatically hidden from all teammates. Only the syncing user can see them. This behaviour is always on and cannot be disabled.',
            ],
        ],
    ],

    'protected_recipients' => [
        'description' => 'Emails involving these addresses or domains are hidden from all teammates workspace-wide. Only the syncing user can see them.',
        'fields' => [
            'protected_emails' => [
                'label' => 'Email addresses',
                'placeholder' => 'e.g. legal@acme.com',
            ],
            'protected_domains' => [
                'label' => 'Domains',
                'placeholder' => 'e.g. acme.com',
            ],
        ],
    ],
];
