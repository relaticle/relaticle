<?php

declare(strict_types=1);

return [
    'events' => [
        'email_sent' => [
            'label' => 'E-mail envoyé',
            'inline' => 'e-mail envoyé',
            'badge' => 'Envoyé',
        ],
        'email_received' => [
            'label' => 'E-mail reçu',
            'inline' => 'e-mail reçu',
            'badge' => 'Reçu',
        ],
        'note_created' => [
            'label' => 'Note',
            'inline' => 'note',
        ],
        'task_created' => [
            'label' => 'Tâche',
            'inline' => 'tâche',
        ],
        'meeting.created' => [
            'label' => 'Réunion planifiée',
            'inline' => 'réunion planifiée',
        ],
        'meeting.cancelled' => [
            'label' => 'Réunion annulée',
            'inline' => 'réunion annulée',
        ],
    ],
];
