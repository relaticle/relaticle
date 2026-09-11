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

    'empty' => [
        'heading' => 'No templates',
        'description' => 'Save a message you send often so you can reuse it.',
    ],

    'actions' => [
        'create' => [
            'label' => 'New template',
        ],
        'edit' => [
            'label' => 'Edit template',
        ],
        'delete' => [
            'label' => 'Delete',
        ],
    ],
];
