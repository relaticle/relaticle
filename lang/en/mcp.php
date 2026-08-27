<?php

declare(strict_types=1);

return [
    'consent' => [
        'title' => 'Authorize :client',
        'intro' => ':client is asking to connect to your Relaticle workspace.',
        'signed_in_as' => 'Signed in as',

        'workspace' => [
            'heading' => 'Which workspace?',
            'description' => ':client will only see data from the workspace you choose. To use a different one later, revoke this connector on the Access Tokens page in Relaticle and add it again.',
            'aria_label' => 'Workspace selection',
            'personal' => 'Personal',
            'paused' => 'Paused — subscribe to connect',
            'all_paused' => 'Every workspace on this account is paused. Subscribe to Cloud Pro before connecting — a connector authorized against a paused workspace cannot read or write any data.',
            'none' => [
                'heading' => 'You do not belong to any workspaces.',
                'description' => 'Create or join a workspace in Relaticle before authorizing this connector.',
            ],
        ],

        'permissions' => [
            'heading' => 'What it will be able to do',
            'description' => 'In the workspace above, and nowhere else.',
            'read' => [
                'title' => 'Read and search your records',
                'description' => 'Companies, people, opportunities, tasks and notes.',
            ],
            'write' => [
                'title' => 'Create and update them',
                'description' => 'Add records, change fields, and link notes and tasks to them.',
            ],
            'delete' => [
                'title' => 'Delete them',
                'description' => 'Removing a record is permanent.',
            ],
            'excluded' => 'It cannot reach your other workspaces, team members, billing, or account settings.',
        ],

        'actions' => [
            'cancel' => 'Cancel',
            'authorize' => 'Authorize',
            'authorizing' => 'Authorizing...',
        ],

        'revoke_hint' => 'You can revoke this connector at any time from the Access Tokens page in Relaticle.',
    ],
];
