<?php

declare(strict_types=1);

return [
    'title' => 'Access Tokens',

    'sections' => [
        'create' => [
            'title' => 'Create Access Token',
            'description' => 'Access tokens allow third-party services and AI agents to authenticate with our application on your behalf.',
        ],
        'manage' => [
            'title' => 'Manage Access Tokens',
            'description' => 'You may delete any of your existing tokens if they are no longer needed.',
        ],
    ],

    'form' => [
        'name' => 'Token Name',
        'team' => 'Workspace',
        'expiration' => 'Expiration',
        'expiration_placeholder' => 'Select expiration...',
        'permissions' => 'Permissions',
        'token' => 'Token',
    ],

    'table' => [
        'columns' => [
            'name' => 'Name',
            'team' => 'Workspace',
            'abilities' => 'Permissions',
            'expires_at' => 'Expires',
            'last_used_at' => 'Last Used',
            'created_at' => 'Created',
        ],
        'placeholders' => [
            'no_team' => '—',
            'never' => 'Never',
        ],
    ],

    'actions' => [
        'create' => 'Create',
    ],

    'permissions' => [
        'all' => 'All',
    ],

    'modals' => [
        'show_token' => [
            'title' => 'Access Token',
            'description' => 'Please copy your new access token. For your security, it won\'t be shown again.',
            'cancel_label' => 'Close',
            'copy_to_clipboard_tooltip' => 'Copy to clipboard',
            'copied_tooltip' => 'Copied!',
        ],
        'permissions' => [
            'title' => 'Access Token Permissions',
            'action_label' => 'Permissions',
        ],
        'delete' => [
            'title' => 'Delete Access Token',
            'description' => 'Are you sure you would like to delete this access token?',
        ],
    ],

    'notifications' => [
        'permissions_updated' => 'Access token permissions updated.',
        'deleted' => 'Access token deleted.',
    ],

    'empty_state' => [
        'heading' => 'No access tokens',
        'description' => 'Create a token above to get started.',
    ],

    'integrations' => [
        'heading' => 'What to do next',
        'api_link' => 'REST API',
        'api_description' => 'Manage CRM data programmatically.',
        'mcp_link' => 'MCP Server',
        'mcp_description' => 'Connect AI assistants like Claude.',
    ],

    'user_menu' => 'Access Tokens',
];
