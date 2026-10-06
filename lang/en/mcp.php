<?php

declare(strict_types=1);

return [
    'connection' => [
        'fallback_name' => 'An AI assistant',
    ],

    'consent' => [
        'title' => 'Authorize :client',
        'intro' => [
            'one' => ':client is asking to connect to :workspace.',
            'choose' => ':client is asking to connect to one of your workspaces.',
        ],
        'redirect' => 'After you authorize, you go back to :host. Continue only if you trust that site.',
        'signed_in_as' => 'Signed in as :email.',

        'workspace' => [
            'heading' => 'Which workspace?',
            'description' => 'To use a different one later, revoke this connector and add it again.',
            'aria_label' => 'Workspace selection',
            'personal' => 'Personal',
            'paused' => 'Paused. Subscribe to connect',
            'all_paused' => 'Every workspace on this account is paused. Subscribe to Cloud Pro before connecting. A connector authorized against a paused workspace cannot read or write any data.',
            'none' => [
                'heading' => 'You do not belong to any workspaces.',
                'description' => 'Create or join a workspace in Relaticle before authorizing this connector.',
            ],
        ],

        'permissions' => [
            'heading' => ':client will be able to',
            'read' => 'Read and search your records',
            'write' => 'Create and update them',
            'delete' => 'Delete them permanently',
            'email_read' => 'Read the email you can see in this workspace',
            'email_draft' => 'Save email drafts for you to review',
            'email_send' => 'Send email as you, after a hold you can cancel',
            'excluded' => 'It cannot reach your other workspaces, workspace members, billing, or account settings.',
        ],

        'actions' => [
            'cancel' => 'Cancel',
            'authorize' => 'Authorize',
            'authorizing' => 'Authorizing...',
        ],

        'revoke_hint' => 'Revoke this connector at any time from the Access Tokens page.',
    ],
];
