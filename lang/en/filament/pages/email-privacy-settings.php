<?php

declare(strict_types=1);

return [
    'title' => 'Workspace Privacy',
    'navigation_label' => 'Workspace Privacy',
    'tabs' => [
        'aria' => 'Workspace email settings',
        'visibility' => 'Email visibility',
        'sharing' => 'Sharing',
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
    'visibility' => [
        'heading' => 'Email visibility',
        'description' => 'Hide emails and calendar event entries involving certain contacts, everywhere in Relaticle.',
        'add' => 'Add contacts',
        'edit_enforcement' => 'Change enforcement level',
        'search_placeholder' => 'Search addresses or domains',
        'empty_heading' => 'No custom contacts yet',
        'empty_hint' => 'System defaults above always apply. Add custom addresses or domains when you need more coverage.',
        'emails_label' => 'Email addresses',
        'emails_placeholder' => 'e.g. legal@acme.com',
        'emails_after_label' => 'Press Enter(⏎) to add each address.',
        'domains_label' => 'Domains',
        'domains_placeholder' => 'e.g. acme.com',
        'domains_after_label' => 'Press Enter(⏎) to add each domain.',
        'enforcement' => [
            'protected' => [
                'label' => 'Protected',
                'description' => 'Emails and calendar events are hidden, unless one or more included contacts are not protected and not blocked.',
            ],
            'blocked' => [
                'label' => 'Blocked',
                'description' => 'Emails and calendar events are hidden even when non-protected or non-blocked contacts are included.',
            ],
        ],
        'notifications' => [
            'added' => 'Email visibility updated.',
            'updated' => 'Enforcement level updated.',
            'deleted' => 'Visibility entry removed.',
        ],
        'table' => [
            'address' => 'Email / Domain',
            'enforcement' => 'Enforcement level',
            'updated' => 'Last update',
            'added_by' => 'Added by',
            'actions' => 'Actions',
            'members_row' => "Workspace members' email addresses",
            'system_default' => 'System default',
            'unknown_user' => 'Unknown',
        ],
    ],
    'blocklist' => [
        'heading' => 'Workspace blocklist',
        'description' => 'Emails from these addresses and domains are hidden from every connected mailbox in this workspace.',
        'add' => 'Add to blocklist',
        'empty_heading' => 'No emails or domains yet',
        'empty_description' => 'Emails from blocklisted domains and addresses will not appear in Relaticle for any mailbox in this workspace.',
        'emails_label' => 'Blocked addresses',
        'emails_placeholder' => 'noisy@example.com',
        'emails_after_label' => 'Press Enter(⏎) to add each address.',
        'domains_label' => 'Blocked domains',
        'domains_placeholder' => 'example.com',
        'domains_after_label' => 'Press Enter(⏎) to add each domain.',
        'notifications' => [
            'added' => 'Blocklist updated.',
            'deleted' => 'Blocklist entry removed.',
        ],
        'table' => [
            'address' => 'Email / Domain',
            'type' => 'Type',
            'added_by' => 'Added by',
            'actions' => 'Actions',
            'unknown_user' => 'Unknown',
        ],
    ],
    'privacy_protections' => [
        'heading' => 'Privacy protections',
        'description' => 'System defaults keep internal team conversations and your workspace domain private inside Relaticle. Add custom addresses or domains below when you need more coverage.',
        'add' => 'Add protection',
        'empty_heading' => 'No custom protections yet',
        'empty_description' => 'Add addresses or domains that should stay hidden from teammates. System defaults above always apply.',
        'notifications' => [
            'added' => 'Privacy protections updated.',
            'deleted' => 'Protection removed.',
        ],
        'table' => [
            'address' => 'Email / Domain',
            'protection' => 'Protection',
            'source' => 'Source',
            'actions' => 'Actions',
            'protected' => 'Protected',
            'system_default' => 'System default',
            'members_row' => "Workspace members' email addresses",
            'unknown_user' => 'Unknown',
        ],
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
        'description' => 'Applies to every connected mailbox and calendar in the workspace. Changing this setting only affects newly synced emails and events. To apply it to mail already in Relaticle, open Email → Accounts and choose Re-import history on each mailbox.',
        'recommended' => 'Recommended',
        'modes' => [
            'all' => [
                'label' => 'All contacts',
                'description' => 'Records will be created for all contacts who appear in the emails and calendar events of your workspace members.',
            ],
            'selective' => [
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
