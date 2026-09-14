<?php

declare(strict_types=1);

return [
    'sharing_confirmation' => [
        'heading' => 'Update sharing for existing emails?',
        'description' => 'This updates every synced email that still follows your sharing default. Emails you changed individually are left as they are. New tier: :tier.',
        'full_access_description' => 'Full access shares the body, subject line, and attachments with everyone in your workspace. This applies to every synced email that still follows your sharing default. Emails you changed individually are left as they are.',
        'full_access_label' => 'Type ":phrase" to confirm',
        'phrase' => 'I understand',
        'phrase_mismatch' => 'Type ":phrase" exactly to confirm.',
    ],
    'sharing_preference' => [
        'heading' => 'My Email Sharing Preference',
        'description' => 'Overrides the workspace default for emails you sync. Changes apply to existing synced emails that still follow your default. Emails you changed individually are left as they are.',
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
