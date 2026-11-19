<?php

declare(strict_types=1);

return [
    'approved' => [
        'title' => 'Acceso al correo aprobado',
        'body' => ':owner aprobó su solicitud.',
        'body_without_owner' => 'Su solicitud fue aprobada.',
    ],

    'denied' => [
        'title' => 'Acceso al correo denegado',
        'body' => ':owner denegó su solicitud.',
        'body_without_owner' => 'Su solicitud fue denegada.',
    ],

    'expired' => [
        'title' => 'La solicitud de acceso al correo venció',
        'body' => ':owner no respondió a tiempo.',
        'body_without_owner' => 'Su solicitud venció sin respuesta.',
    ],

    'mail' => [
        'subject_line' => 'Asunto: :subject',
        'action_view' => 'Ver correo',
        'action_open' => 'Abrir Relaticle',
    ],
];
