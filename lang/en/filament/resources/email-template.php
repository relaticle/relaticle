<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Templates',
    'fields' => [
        'name' => [
            'label' => 'Template name',
        ],
        'subject' => [
            'label' => 'Subject',
        ],
        'body_html' => [
            'label' => 'Body',
        ],
        'is_shared' => [
            'label' => 'Share with workspace',
            'helper_text' => 'Everyone in your workspace can use this template.',
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
            'label' => 'Created by',
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
