<?php

declare(strict_types=1);

return [
    'title' => 'Signatures',
    'heading' => 'Email signatures',
    'default_badge' => 'Default',
    'empty' => 'No signatures yet. Select :action to add one.',
    'actions' => [
        'create' => 'New signature',
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
