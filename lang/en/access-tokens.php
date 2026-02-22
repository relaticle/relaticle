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
        'team' => 'Team',
        'expiration' => 'Expiration',
        'permissions' => 'Permissions',
    ],

    'actions' => [
        'create' => 'Create',
    ],

    'modals' => [
        'show_token' => [
            'title' => 'Access Token',
            'description' => 'Please copy your new access token. For your security, it won\'t be shown again.',
        ],
        'permissions' => [
            'title' => 'Access Token Permissions',
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
