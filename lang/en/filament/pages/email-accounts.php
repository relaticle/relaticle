<?php

declare(strict_types=1);

return [
    'title' => 'Accounts',
    'actions' => [
        'connect_gmail' => 'Connect Gmail',
        'connect_azure' => 'Connect Outlook',
        're_auth' => 'Re-authenticate',
        'edit_settings' => 'Settings',
        'disconnect' => 'Disconnect',
        'sync_calendar' => [
            'enable_label' => 'Sync calendar',
            'disable_label' => 'Disable calendar sync',
            'enable_heading' => 'Enable calendar sync',
            'disable_heading' => 'Disable calendar sync',
            'disable_description' => 'This will stop syncing calendar events for this account.',
            'enable_description' => 'You will be redirected to :provider to grant calendar access.',
            'fallback_provider' => 'the provider',
        ],
        'sync_calendar_now' => 'Sync now',
    ],
    'settings' => [
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
            'placeholder' => 'Default: :default',
            'helper_text' => 'Leave blank to use the workspace default.',
        ],
        'daily_send_limit' => [
            'label' => 'Daily send limit',
            'placeholder' => 'Default: :default',
            'helper_text' => 'Leave blank to use the workspace default.',
        ],
        'modal_heading' => 'Account Settings',
        'submit_label' => 'Save',
    ],
    'notifications' => [
        'calendar_sync_queued' => [
            'title' => 'Calendar sync queued.',
            'body' => 'New events should appear within a minute.',
        ],
    ],
];
