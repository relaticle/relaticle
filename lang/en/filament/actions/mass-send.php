<?php

declare(strict_types=1);

return [
    'label' => 'Send Email',

    'fields' => [
        'from' => [
            'label' => 'From',
        ],
        'template' => [
            'label' => 'Template',
            'placeholder' => 'None — write below',
        ],
        'body' => [
            'label' => 'Body',
        ],
    ],

    'notifications' => [
        'no_recipients' => [
            'title' => 'No valid recipients',
            'body' => 'None of the selected people have an email address.',
        ],
        'queued' => [
            'title' => 'Mass email queued',
            'body' => 'Sending to :count recipient(s).',
            'body_with_skipped' => 'Queued :count recipient(s), skipped :skipped without an email address.',
        ],
    ],
];
