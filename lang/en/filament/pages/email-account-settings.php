<?php

declare(strict_types=1);

return [
    'subheading' => 'Update your account permissions and settings.',
    'tabs' => [
        'general' => 'General',
        'sharing' => 'Sharing',
        'blocklist' => 'Blocklist',
        'signatures' => 'Signatures',
    ],
    'sharing' => [
        'label' => 'Email sharing',
        'use_workspace_default' => 'Use workspace default',
        'hint' => 'What your workspace sees. Applies to all your mailboxes.',
        'workspace_default_description' => 'Follow whatever the workspace has set — currently :tier',
    ],
    'blocklist' => [
        'label' => 'Blocked addresses and domains',
        'emails_label' => 'Blocked addresses',
        'emails_placeholder' => 'noisy@example.com',
        'domains_label' => 'Blocked domains',
        'domains_placeholder' => 'example.com',
        'hint' => 'Hidden from your view, across all your mailboxes.',
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
