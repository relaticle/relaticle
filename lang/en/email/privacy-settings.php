<?php

declare(strict_types=1);

return [
    'sharing_preference' => [
        'heading' => 'My Email Sharing Preference',
        'description' => 'Overrides the workspace default for emails you sync. Set to blank to use the workspace default.',
        'tier_label' => 'Default sharing tier',
        'use_workspace_default' => 'Use workspace default',
        'workspace_default_hint' => 'Workspace default: :tier',
    ],
    'blocklist' => [
        'heading' => 'Blocked Addresses & Domains',
        'description' => 'Emails involving these addresses or domains will be hidden from your view.',
        'type_label' => 'Type',
        'value_label' => 'Value',
        'value_placeholder' => 'e.g. spam@example.com or spammy.com',
        'add_entry' => 'Add entry',
    ],
    'actions' => [
        'save' => 'Save',
    ],
    'notifications' => [
        'saved' => 'Email privacy settings saved.',
    ],
];
