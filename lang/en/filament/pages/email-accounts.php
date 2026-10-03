<?php

declare(strict_types=1);

return [
    'title' => 'Email and calendar accounts',
    'navigation_label' => 'Accounts',
    'subheading' => 'Manage and sync your email and calendar accounts to stay organized.',
    'actions' => [
        'connect_gmail' => 'Connect Google account',
        'connect_azure' => 'Connect Microsoft account',
        'manage' => 'Manage',
        'edit_settings' => 'Edit settings',
        'reconnect' => 'Reconnect',
        'set_default' => 'Set as default',
        'retry_sync' => 'Retry sync',
        'disconnect' => 'Disconnect account',
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
            'heading' => 'Re-import account history?',
            'description' => 'Already synced mail and events stay in Relaticle. We will create missing people and companies using the current workspace record-creation setting, and import any messages not stored yet. This can take a while on a large mailbox.',
        ],
        'retry_failed_import' => [
            'label' => 'Retry',
        ],
    ],
    'settings' => [
        'sync_inbox' => [
            'label' => 'Sync inbox',
            'helper_text' => 'Bring emails you receive into Relaticle.',
        ],
        'sync_sent' => [
            'label' => 'Sync sent',
            'helper_text' => 'Bring emails you send from this account into Relaticle.',
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
        'modal_heading' => 'Account settings',
        'submit_label' => 'Save',
    ],
    'notifications' => [
        'connected' => [
            'title' => 'Account connected.',
            'body' => 'Emails and meetings will appear as the import runs.',
        ],
        'calendar_sync_queued' => [
            'title' => 'Calendar sync started.',
            'body' => 'Your meetings will update on this page as the sync finishes.',
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
        'sync_retry_queued' => [
            'title' => 'Sync retry queued.',
            'body' => 'We are fetching what could not be stored. This page updates as it finishes.',
        ],
        'retry_failed_import_queued' => [
            'title' => 'Retry queued.',
            'body' => 'We are retrying messages that could not be imported. This page updates as they finish.',
        ],
        'retry_failed_import_unavailable' => [
            'title' => 'Retry unavailable',
            'body' => 'No failed imports are available to retry for this import.',
        ],
    ],
    'default_badge' => 'Default',
    'sections' => [
        'connected' => [
            'heading' => 'Connected accounts',
            'description' => 'Read how we handle your data in our <a href=":url" target="_blank" class="underline">Privacy Policy</a>.',
        ],
    ],
    'synced_at' => 'Synced :time',
    'in_sync' => 'In sync',
    'sync_error' => [
        'badge' => 'Sync issue',
        'heading' => 'Some items could not be synced',
    ],
    'importing' => 'Syncing',
    'importing_percent' => ':percent%',
    'importing_count' => '{1}:count email|[2,*]:count emails',
    'statuses' => [
        'active' => 'Active',
        'error' => 'Sync issue',
        'disconnected' => 'Disconnected',
        'reauth_required' => 'Reconnect needed',
    ],
    'history_import' => [
        'processed' => ':processed of :total processed',
        'successful_jobs' => ':count imported successfully',
    ],
    'history_import_failure' => [
        'max_attempts' => 'This message could not be stored after several tries. Use Retry above. If it keeps failing, wait a few minutes and try again.',
    ],
    'capabilities' => [
        'email' => 'Email',
        'calendar' => 'Calendar',
    ],
    'not_connected' => [
        'inbox' => [
            'heading' => 'Send emails in Relaticle',
            'description' => 'Connect your email account to read and reply without leaving Relaticle. You also get mass sending, templates, and attachments.',
        ],
        'record' => [
            'heading' => 'Keep emails on the record',
            'description' => 'Connect your email account to see every conversation with this record and reply in one click.',
        ],
        'meetings' => [
            'heading' => 'See your meetings in Relaticle',
            'description' => 'Connect your account to track meetings alongside your CRM records.',
        ],
    ],
];
