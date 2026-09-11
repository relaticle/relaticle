<?php

declare(strict_types=1);

return [
    'sharing_preference' => [
        'heading' => 'My Email Sharing Preference',
        'description' => 'Overrides the workspace default for emails you sync. Set to blank to use the workspace default.',
        'tier_label' => 'Default sharing tier',
        'use_workspace_default' => 'Use workspace default',
        'workspace_default_description' => 'Follow whatever the workspace has set. Currently :tier',
    ],
    'blocklist' => [
        'heading' => 'Blocked Addresses & Domains',
        'description' => 'Emails involving these addresses or domains will be hidden from your view.',
        'emails_label' => 'Blocked addresses',
        'emails_placeholder' => 'noisy@example.com',
        'emails_helper' => 'Emails involving these addresses are hidden from your view.',
        'domains_label' => 'Blocked domains',
        'domains_placeholder' => 'example.com',
        'domains_helper' => 'Emails involving any address at these domains are hidden from your view.',
    ],
    'actions' => [
        'save' => 'Save',
    ],
    'notifications' => [
        'saved' => 'Email privacy settings saved.',
    ],
];
