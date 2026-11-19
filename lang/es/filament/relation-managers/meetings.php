<?php

declare(strict_types=1);

return [
    'title' => 'Reuniones',
    'label' => 'reunión',
    'plural_label' => 'reuniones',

    'columns' => [
        'title' => [
            'label' => 'Título',
        ],
        'starts_at' => [
            'label' => 'Hora',
        ],
        'attendees_count' => [
            'label' => 'Asistentes',
        ],
        'response_status' => [
            'label' => 'Mi respuesta',
        ],
    ],

    'actions' => [
        'link_to_record' => [
            'label' => 'Vincular a un registro',
        ],
        'unlink_from_record' => [
            'label' => 'Desvincular de este registro',
        ],
    ],

    'notifications' => [
        'linked' => [
            'title' => 'Reunión vinculada correctamente.',
        ],
        'unlinked' => [
            'title' => 'Reunión desvinculada correctamente.',
        ],
    ],
];
