<?php

declare(strict_types=1);

return [
    'title' => 'Workspace Privacy',
    'navigation_label' => 'Workspace Privacy',
    'actions' => [
        'save' => 'Save',
    ],
    'workspace_default' => [
        'heading' => 'Workspace Default Sharing Tier',
        'description' => 'Applied to all newly synced emails unless a team member sets their own preference.',
        'tier_label' => 'Default Sharing Tier for Connected Email Accounts',
    ],
    'privacy_protections' => [
        'heading' => 'Privacy Protections',
        'description' => 'Internal emails are always hidden from teammates. Add addresses or domains below to protect those emails workspace-wide too.',
    ],
    'protected_recipients' => [
        'heading' => 'Protected Recipients',
        'description' => 'Emails involving these addresses or domains are hidden from all teammates workspace-wide. Only the syncing user can see them.',
        'emails_label' => 'Email addresses',
        'emails_placeholder' => 'e.g. legal@acme.com',
        'emails_after_label' => 'Press Enter(⏎) to add each address.',
        'domains_label' => 'Domains',
        'domains_placeholder' => 'e.g. acme.com',
        'domains_after_label' => 'All emails from these domains will be protected.',
    ],
    'notifications' => [
        'saved' => 'Privacy settings saved.',
    ],
];
