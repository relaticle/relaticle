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
            'placeholder' => 'None. Write below',
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
        'skipped' => [
            'title' => 'Some recipients skipped',
            'body' => ':skipped selected record(s) have no email address and were not added.',
        ],
        'queued' => [
            'title' => 'Mass email queued',
            'body' => 'Sending to :count recipient(s).',
            'body_with_skipped' => 'Queued :count recipient(s), skipped :skipped without an email address.',
        ],
    ],
];
