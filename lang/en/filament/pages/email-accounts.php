<?php

declare(strict_types=1);

return [
    'title' => 'Email and calendar accounts',
    'navigation_label' => 'Accounts',
    'subheading' => 'Manage and sync your email and calendar accounts to stay organized.',
    'actions' => [
        'connect_gmail' => 'Connect Google Account',
        'connect_azure' => 'Connect Microsoft Account',
        'manage' => 'Manage',
        'reconnect' => 'Reconnect',
        'set_default' => 'Set as default',
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
        'reimport_history' => [
            'label' => 'Re-import history',
            'heading' => 'Re-import mailbox history?',
            'description' => 'Already synced mail and events stay in Relaticle. We will create missing people and companies using the current workspace record-creation setting, and import any messages not stored yet. This can take a while on a large mailbox.',
        ],
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
        'disconnected' => [
            'title' => 'Account disconnected.',
            'body' => 'The account and its signatures have been removed.',
        ],
        'default_set' => [
            'title' => 'Default account updated.',
            'body' => ':email is now your default sending account.',
        ],
        'reimport_queued' => [
            'title' => 'History import queued.',
            'body' => 'People and companies will appear as the import runs. You can keep using Relaticle.',
        ],
    ],
    'default_badge' => 'Default',
    'sections' => [
        'connected' => [
            'heading' => 'Connected accounts',
            'description' => 'We take your privacy very seriously. Read our <a href=":url" target="_blank" class="underline">Privacy Policy</a>.',
        ],
    ],
    'synced_at' => 'Synced :time',
    'in_sync' => 'In Sync',
    'send_missing_tooltip' => 'Send access was not granted. Grant it to send mail from Relaticle.',
    'importing' => 'Syncing',
    'importing_percent' => ':percent%',
    'sync_status' => [
        'title_syncing' => 'Syncing',
        'title_complete' => 'Import complete',
        'meetings_processed' => '{1}:count meeting processed|[2,*]:count meetings processed',
        'emails_processed' => '{1}:count email processed|[2,*]:count emails processed',
        'close' => 'Dismiss',
        'open_settings' => 'Open account settings',
    ],
    'capabilities' => [
        'email' => 'Email',
        'calendar' => 'Calendar',
    ],
    'not_connected' => [
        'action' => 'Configure mailbox',
        'inbox' => [
            'heading' => 'Send emails in Relaticle',
            'description' => 'Connect your mailbox to read and reply without leaving Relaticle. Mass sending, templates, attachments, and more.',
        ],
        'record' => [
            'heading' => 'Keep emails on the record',
            'description' => 'Connect your mailbox to see every conversation with this record and reply in one click.',
        ],
        'meetings' => [
            'heading' => 'See your meetings in Relaticle',
            'description' => 'Connect your mailbox to track meetings alongside your CRM records.',
        ],
    ],
];
