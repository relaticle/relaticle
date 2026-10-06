<?php

declare(strict_types=1);

return [
    'title' => 'Workspace privacy',
    'navigation_label' => 'Workspace privacy',
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
        'heading' => 'Workspace default sharing tier',
        'description' => 'Applied to synced emails for members who follow the workspace default. Existing emails update when you save, except emails a member changed individually.',
        'tier_label' => 'Default sharing tier for connected email accounts',
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
        'emails_after_label' => 'Press Enter to add each address.',
        'domains_label' => 'Domains',
        'domains_placeholder' => 'e.g. acme.com',
        'domains_after_label' => 'Press Enter to add each domain.',
        'include_subdomains_label' => 'Include subdomains',
        'include_subdomains_hint' => 'Also hide mail from addresses like user@mail.example.com when you add example.com.',
        'include_subdomains_short' => 'Include subdomains',
        'enforcement' => [
            'protected' => [
                'label' => 'Protected',
                'description' => 'Hidden only when every contact on the email or event is protected or blocked.',
            ],
            'blocked' => [
                'label' => 'Blocked',
                'description' => 'Always hidden when this contact is on the email or event.',
            ],
        ],
        'notifications' => [
            'added' => 'Email visibility updated.',
            'updated' => 'Enforcement level updated.',
            'deleted' => 'Visibility entry removed.',
        ],
        'table' => [
            'address' => 'Email / Domain',
            'subdomains' => 'Subdomains',
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
        'empty_heading' => 'No addresses or domains yet',
        'empty_description' => 'Emails from blocklisted domains and addresses will not appear in Relaticle for any mailbox in this workspace.',
        'emails_label' => 'Blocked addresses',
        'emails_placeholder' => 'noisy@example.com',
        'emails_after_label' => 'Press Enter to add each address.',
        'domains_label' => 'Blocked domains',
        'domains_placeholder' => 'example.com',
        'domains_after_label' => 'Press Enter to add each domain.',
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
    'record_creation' => [
        'heading' => 'Automatic record creation',
        'description' => 'Applies to every connected mailbox and calendar in the workspace. Changing this setting only affects newly synced emails and events.',
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
            'description' => 'When enabled, a company is created from a person\'s email domain. This follows the contact setting above. It is unavailable when record creation is None.',
        ],
    ],
    'tiers' => [
        'private' => [
            'label' => 'Private',
            'description' => 'Nothing is shared. Only you can see these emails.',
        ],
        'metadata_only' => [
            'label' => 'Participants only',
            'description' => 'Your workspace sees the participants and timestamp. Subject and message stay private.',
        ],
        'subject' => [
            'label' => 'Subject line and participants',
            'description' => 'Your workspace sees the subject line, participants, and timestamp. The message stays private unless you share it.',
        ],
        'full' => [
            'label' => 'Full access',
            'description' => 'Your workspace sees the body, subject line, and attachments.',
        ],
    ],
    'notifications' => [
        'saved' => 'Privacy settings saved.',
    ],
];
