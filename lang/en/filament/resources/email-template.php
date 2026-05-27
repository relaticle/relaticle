<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Emails',
    ],

    'fields' => [
        'body' => [
            'label' => 'Body',
        ],
        'is_shared' => [
            'label' => 'Share with team',
            'helper_text' => 'When enabled, all team members can use this template.',
            'column_label' => 'Shared',
        ],
        'subject' => [
            'placeholder' => '—',
        ],
        'creator' => [
            'label' => 'Created By',
            'placeholder' => '—',
        ],
        'created_at' => [
            'label' => 'Created',
        ],
    ],

    'actions' => [
        'delete' => [
            'label' => 'Delete',
        ],
    ],
];
