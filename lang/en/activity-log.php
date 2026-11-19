<?php

declare(strict_types=1);

return [
    'events' => [
        'email_sent' => [
            'label' => 'Email sent',
            'inline' => 'email sent',
            'badge' => 'Sent',
        ],
        'email_received' => [
            'label' => 'Email received',
            'inline' => 'email received',
            'badge' => 'Received',
        ],
        'note_created' => [
            'label' => 'Note',
            'inline' => 'note',
        ],
        'task_created' => [
            'label' => 'Task',
            'inline' => 'task',
        ],
        'meeting.created' => [
            'label' => 'Meeting scheduled',
            'inline' => 'meeting scheduled',
        ],
        'meeting.cancelled' => [
            'label' => 'Meeting cancelled',
            'inline' => 'meeting cancelled',
        ],
    ],
];
