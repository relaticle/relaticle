<?php

declare(strict_types=1);

return [
    'events' => [
        'email_sent' => [
            'label' => 'Email sent',
            'badge' => 'Sent',
        ],
        'email_received' => [
            'label' => 'Email received',
            'badge' => 'Received',
        ],
        'note_created' => [
            'label' => 'Note',
        ],
        'task_created' => [
            'label' => 'Task',
        ],
        'record_merged' => [
            'label' => 'Merged',
            'description' => ':first and :second merged into this record',
            'description_by' => ':causer merged :first and :second into this record',
        ],
        'meeting.created' => [
            'label' => 'Meeting scheduled',
        ],
        'meeting.cancelled' => [
            'label' => 'Meeting cancelled',
        ],
    ],
];
