<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Emails',
    ],

    'columns' => [
        'participants_to' => [
            'label' => 'Recipients',
        ],
        'scheduled_for' => [
            'label' => 'Scheduled for',
        ],
    ],

    'fields' => [
        'scheduled_for' => [
            'label' => 'Send at',
        ],
    ],

    'notifications' => [
        'cancelled' => [
            'title' => 'Cancelled',
        ],
        'rescheduled' => [
            'title' => 'Rescheduled',
        ],
        'retry_queued' => [
            'title' => 'Retry queued',
        ],
        'bulk_cancelled' => [
            'title' => 'Cancelled :count emails',
        ],
    ],

    'actions' => [
        'bulk_cancel' => [
            'label' => 'Cancel selected',
        ],
    ],
];
