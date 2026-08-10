<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Templates',
    'fields' => [
        'body_html' => [
            'label' => 'Body',
        ],
        'is_shared' => [
            'label' => 'Share with team',
            'helper_text' => 'When enabled, all team members can use this template.',
        ],
    ],

    'columns' => [
        'subject' => [
            'placeholder' => '—',
        ],
        'is_shared' => [
            'label' => 'Shared',
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
