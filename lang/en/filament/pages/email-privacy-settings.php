<?php

declare(strict_types=1);

return [
    'title' => 'Workspace Privacy',
    'navigation_label' => 'Workspace Privacy',
    'tabs' => [
        'aria' => 'Workspace email settings',
        'visibility' => 'Email visibility',
        'record_creation' => 'Record creation',
    ],
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
    'record_creation' => [
        'heading' => 'Automatic record creation',
        'description' => 'Applies to every connected mailbox and calendar in the workspace, including forwarding. Changes affect newly synced emails and events.',
        'recommended' => 'Recommended',
        'modes' => [
            'all' => [
                'label' => 'All contacts',
                'description' => 'Records will be created for all contacts who appear in the emails and calendar events of your workspace members.',
            ],
            'bidirectional' => [
                'label' => 'Selective contact creation',
                'description' => 'Records will only be created for contacts who receive emails from your workspace members, or appear in their calendar events.',
            ],
            'none' => [
                'label' => 'None',
                'description' => 'No records will automatically be created. Email and calendar events will still be linked with records created manually.',
            ],
        ],
        'companies' => [
            'label' => 'Automatically create company records',
            'description' => 'Company records will be automatically created based on the domain in a person\'s email address.',
        ],
    ],
    'notifications' => [
        'saved' => 'Privacy settings saved.',
    ],
];
