<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Emails',
    ],

    'actions' => [
        'connect_gmail' => [
            'label' => 'Connect Gmail',
        ],
        'connect_azure' => [
            'label' => 'Connect Outlook',
        ],
        'reauth' => [
            'label' => 'Re-authenticate',
        ],
        'edit_settings' => [
            'label' => 'Settings',
            'modal_heading' => 'Account Settings',
        ],
        'sync_calendar_now' => [
            'label' => 'Sync now',
        ],
        'disconnect' => [
            'label' => 'Disconnect',
        ],
    ],

    'fields' => [
        'sync_inbox' => [
            'label' => 'Sync inbox',
            'helper_text' => 'Sync incoming emails to this account.',
        ],
        'sync_sent' => [
            'label' => 'Sync sent',
            'helper_text' => 'Sync emails you send from this account.',
        ],
        'contact_creation_mode' => [
            'label' => 'Auto-create contacts',
            'helper_text' => 'Controls when new Person records are created from email participants.',
        ],
        'auto_create_companies' => [
            'label' => 'Auto-create companies',
            'helper_text' => 'Create Company records for unrecognised business domains (public domains like gmail.com are always excluded).',
        ],
        'hourly_send_limit' => [
            'label' => 'Hourly send limit',
            'helper_text' => 'Leave blank to use the workspace default.',
        ],
        'daily_send_limit' => [
            'label' => 'Daily send limit',
            'helper_text' => 'Leave blank to use the workspace default.',
        ],
    ],

    'notifications' => [
        'calendar_sync_queued' => [
            'title' => 'Calendar sync queued.',
        ],
    ],
];
