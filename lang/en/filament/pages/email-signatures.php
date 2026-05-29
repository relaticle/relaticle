<?php

declare(strict_types=1);

return [
    'title' => 'Signatures',
    'actions' => [
        'create' => 'New Signature',
        'edit' => 'Edit',
        'delete' => 'Delete',
    ],
    'fields' => [
        'connected_account' => 'Email account',
        'name' => 'Signature name',
        'content' => 'Signature content',
        'is_default' => 'Set as default for this account',
    ],
    'notifications' => [
        'created' => 'Signature created.',
        'updated' => 'Signature updated.',
        'deleted' => 'Signature deleted.',
    ],
];
