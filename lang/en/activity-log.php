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
            'description' => ':name was merged into this record',
            'description_by' => ':causer merged :name into this record',
            'description_into' => 'This record was merged into :name',
            'description_into_by' => ':causer merged this record into :name',
        ],
        'meeting.created' => [
            'label' => 'Meeting scheduled',
        ],
        'meeting.cancelled' => [
            'label' => 'Meeting cancelled',
        ],
    ],
];
