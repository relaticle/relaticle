<?php

declare(strict_types=1);

return [
    'events' => [
        'email_sent' => [
            'label' => 'Correo electrónico enviado',
            'inline' => 'correo electrónico enviado',
            'badge' => 'Enviado',
        ],
        'email_received' => [
            'label' => 'Correo electrónico recibido',
            'inline' => 'correo electrónico recibido',
            'badge' => 'Recibido',
        ],
        'note_created' => [
            'label' => 'Nota',
            'inline' => 'nota',
        ],
        'task_created' => [
            'label' => 'Tarea',
            'inline' => 'tarea',
        ],
        'meeting.created' => [
            'label' => 'Reunión programada',
            'inline' => 'reunión programada',
        ],
        'meeting.cancelled' => [
            'label' => 'Reunión cancelada',
            'inline' => 'reunión cancelada',
        ],
    ],
];
