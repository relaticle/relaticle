<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Emails',
    ],

    'create' => [
        'label' => 'New Signature',
    ],

    'edit' => [
        'label' => 'Edit',
    ],

    'delete' => [
        'label' => 'Delete',
    ],

    'fields' => [
        'connected_account' => [
            'label' => 'Email account',
        ],
        'name' => [
            'label' => 'Signature name',
        ],
        'content_html' => [
            'label' => 'Signature content',
        ],
        'is_default' => [
            'label' => 'Set as default for this account',
        ],
    ],

    'notifications' => [
        'created' => [
            'title' => 'Signature created.',
        ],
        'updated' => [
            'title' => 'Signature updated.',
        ],
        'deleted' => [
            'title' => 'Signature deleted.',
        ],
    ],
];
