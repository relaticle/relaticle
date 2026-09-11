<?php

declare(strict_types=1);

return [
    'subheading' => 'Update your account permissions and settings.',
    'tabs' => [
        'general' => 'General',
        'sharing' => 'Sharing',
        'blocklist' => 'Mailbox-only blocklist',
        'signatures' => 'Signatures',
    ],
    'sharing' => [
        'label' => 'Email sharing',
        'use_workspace_default' => 'Use workspace default',
        'hint' => 'What your workspace sees. Applies to all your mailboxes.',
        'workspace_default_description' => 'Follow whatever the workspace has set. Currently :tier',
    ],
    'blocklist' => [
        'label' => 'Blocked addresses and domains',
        'emails_label' => 'Blocked addresses',
        'emails_placeholder' => 'noisy@example.com',
        'emails_after_label' => 'Press Enter(⏎) to add each address.',
        'domains_label' => 'Blocked domains',
        'domains_placeholder' => 'example.com',
        'domains_after_label' => 'Press Enter(⏎) to add each domain.',
        'hint' => 'Hidden from this mailbox only. Other connected accounts are not affected.',
        'add' => 'Add to blocklist',
        'empty_heading' => 'No emails or domains yet',
        'empty_description' => 'Emails from blocklisted domains and addresses will not appear in this mailbox.',
        'notifications' => [
            'added' => 'Blocklist updated.',
            'deleted' => 'Blocklist entry removed.',
        ],
    ],
    'signatures' => [
        'label' => 'Signatures',
        'hint' => 'Used when composing from this account.',
        'add' => 'Add signature',
        'empty_heading' => 'No signatures yet',
        'empty_description' => 'Create a signature to append to emails you send from this account.',
    ],
    'actions' => [
        'save' => 'Save changes',
    ],
    'notifications' => [
        'saved' => 'Account settings saved.',
    ],
];
