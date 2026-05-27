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
        ],
        'queued' => [
            'title' => 'Mass email queued',
        ],
    ],
];
