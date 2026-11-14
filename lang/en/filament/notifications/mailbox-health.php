<?php

declare(strict_types=1);

return [
    'stale' => [
        'owner' => [
            'title' => 'Your mailbox stopped syncing',
            'body' => 'Your mailbox :mailbox has not synced for a day. Reconnect it to bring in new mail.',
        ],
        'admin' => [
            'title' => 'A mailbox stopped syncing',
            'body' => ':owner connected :mailbox, and it has not synced for days. Ask :owner to reconnect it.',
        ],
    ],
    'reauth_required' => [
        'owner' => [
            'title' => 'Reconnect your mailbox',
            'body' => 'Reconnect :mailbox. Relaticle lost access to it.',
        ],
        'admin' => [
            'title' => 'A mailbox needs reconnecting',
            'body' => 'Relaticle lost access to :mailbox, connected by :owner. Ask :owner to reconnect it.',
        ],
    ],
    'error' => [
        'owner' => [
            'title' => 'Your mailbox stopped syncing',
            'body' => 'Mail sync for :mailbox failed. Reconnect it to bring in new mail.',
        ],
        'admin' => [
            'title' => 'A mailbox stopped syncing',
            'body' => 'Mail sync for :mailbox, connected by :owner, keeps failing. Ask :owner to reconnect it.',
        ],
    ],
    'paused_billing' => [
        'owner' => [
            'title' => 'Mail sync is paused',
            'body' => 'Mail sync for :mailbox is paused until billing is resumed.',
        ],
        'admin' => [
            'title' => 'Mail sync is paused',
            'body' => 'Mail sync for :mailbox, connected by :owner, is paused until billing is resumed.',
        ],
    ],
    'actions' => [
        'reconnect' => 'Reconnect mailbox',
        'billing' => 'View billing',
    ],
    'mail' => [
        'greeting' => 'Hello :name,',
    ],
];
