<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Solicitudes de acceso',
    'tabs' => [
        'aria' => 'Pestañas de solicitudes de acceso',
        'incoming' => 'Recibidas',
        'outgoing' => 'Enviadas',
    ],
    'filters' => [
        'label' => 'Estado',
        'all' => 'Todas',
    ],
    'statuses' => [
        'pending' => 'Pendiente',
        'approved' => 'Aprobada',
        'denied' => 'Denegada',
        'cancelled' => 'Cancelada',
        'expired' => 'Vencida',
    ],
    'columns' => [
        'requested_by' => 'Solicitada por',
        'sent_to' => 'Enviada a',
        'email' => 'Correo',
        'access' => 'Acceso',
        'requested' => 'Solicitada',
    ],
    'search' => [
        'placeholder' => 'Buscar por nombre o asunto…',
    ],
    'request' => [
        'requested_incoming' => 'solicitó acceso',
        'requested_outgoing' => 'usted solicitó acceso',
        'unknown_user' => 'Persona desconocida',
        'email_unavailable' => 'El correo asociado ya no está disponible.',
    ],
    'empty' => [
        'filtered_heading' => [
            'pending' => 'No hay solicitudes pendientes',
            'approved' => 'No hay solicitudes aprobadas',
            'denied' => 'No hay solicitudes denegadas',
            'cancelled' => 'No hay solicitudes canceladas',
            'expired' => 'No hay solicitudes vencidas',
        ],
        'filtered_description' => 'Pruebe con otro filtro o borre el activo.',
        'show_all' => 'Mostrar todas',
        'incoming_heading' => 'No hay solicitudes recibidas',
        'outgoing_heading' => 'No hay solicitudes enviadas',
        'incoming_description' => 'Cuando alguien pida acceso a uno de sus correos privados, aparecerá aquí.',
        'outgoing_description' => 'Aún no ha pedido acceso a ningún correo.',
    ],
    'actions' => [
        'open_email' => 'Abrir correo',
        'approve' => [
            'label' => 'Aprobar',
            'modal_heading' => 'Aprobar solicitud de acceso',
            'modal_description' => '¿Conceder a :name acceso a este correo?',
            'modal_submit_label' => 'Aprobar',
        ],
        'deny' => [
            'label' => 'Denegar',
            'modal_heading' => 'Denegar solicitud de acceso',
            'modal_description' => '¿Denegar la solicitud de acceso de :name?',
            'modal_submit_label' => 'Denegar',
        ],
        'cancel' => [
            'label' => 'Cancelar solicitud',
            'modal_heading' => 'Cancelar solicitud de acceso',
            'modal_description' => '¿Retirar su solicitud de acceso al correo de :name?',
            'modal_submit_label' => 'Cancelar solicitud',
        ],
        'fallback_user' => 'esta persona',
    ],
    'notifications' => [
        'approved' => 'Solicitud de acceso aprobada.',
        'denied' => 'Solicitud de acceso denegada.',
        'cancelled' => 'Solicitud de acceso cancelada.',
        'no_longer_pending' => 'Esta solicitud ya no está pendiente.',
    ],
];
