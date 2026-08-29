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
        're_auth' => 'Re-authenticate',
        'edit_settings' => 'Settings',
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
    'importing_count' => 'Importing :count emails',
    'importing_progress' => 'Importing :imported of :estimated emails',
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
            'description' => 'Connect your mailbox and enable calendar sync to track meetings alongside your CRM records.',
        ],
    ],
];
