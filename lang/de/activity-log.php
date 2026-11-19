<?php

declare(strict_types=1);

return [
    'events' => [
        'email_sent' => [
            'label' => 'E-Mail gesendet',
            'inline' => 'E-Mail gesendet',
            'badge' => 'Gesendet',
        ],
        'email_received' => [
            'label' => 'E-Mail empfangen',
            'inline' => 'E-Mail empfangen',
            'badge' => 'Empfangen',
        ],
        'note_created' => [
            'label' => 'Notiz',
            'inline' => 'Notiz',
        ],
        'task_created' => [
            'label' => 'Aufgabe',
            'inline' => 'Aufgabe',
        ],
        'meeting.created' => [
            'label' => 'Meeting angesetzt',
            'inline' => 'Meeting angesetzt',
        ],
        'meeting.cancelled' => [
            'label' => 'Meeting abgesagt',
            'inline' => 'Meeting abgesagt',
        ],
    ],
];
