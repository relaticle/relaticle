<?php

declare(strict_types=1);

return [
    'actions' => [
        'manage_sharing' => [
            'label' => 'Uso compartido',
            'modal_heading' => 'Configuración de uso compartido',
            'submit' => 'Guardar',
        ],
        'summarize_thread' => [
            'label' => 'Resumir conversación',
            'modal_heading' => 'Resumen de la conversación con IA',
            'empty' => 'No hay resumen disponible para esta conversación.',
            'failed' => 'El proveedor de IA no pudo generar un resumen. Inténtelo de nuevo más tarde.',
            'generated' => 'Generado :time',
            'copy' => 'Copiar',
            'copied' => 'Copiado',
        ],
        'request_access' => [
            'label' => 'Solicitar acceso',
            'modal_heading' => 'Solicitar acceso',
        ],
        'approve_access_request' => [
            'modal_heading' => 'Aprobar solicitud de acceso',
        ],
        'deny_access_request' => [
            'modal_heading' => 'Denegar solicitud de acceso',
        ],
    ],
    'fields' => [
        'privacy_tier' => [
            'label' => '¿Quién puede ver este correo?',
        ],
        'shares' => [
            'label' => 'Compartir con miembros específicos',
        ],
        'shared_with' => [
            'label' => 'Miembro',
        ],
        'tier' => [
            'label' => 'Nivel de acceso',
        ],
        'tier_requested' => [
            'label' => 'Nivel de acceso solicitado',
        ],
    ],
    'empty' => [
        'heading' => 'No hay correos',
        'description' => 'Este registro no tiene correos, o pueden estar ocultos por los permisos.',
        'compose' => 'Redactar',
    ],
    'protected' => [
        'heading' => 'No hay nada que mostrar aquí',
        'description' => 'Este registro está protegido. Sus correos y reuniones permanecen ocultos aquí.',
    ],
    'blocked' => [
        'heading' => 'No hay nada que mostrar aquí',
        'description' => 'Este registro está bloqueado. Sus correos y reuniones permanecen ocultos aquí.',
    ],

    'notifications' => [
        'sharing_saved' => [
            'title' => 'Configuración de uso compartido guardada.',
        ],
        'pending_request' => [
            'title' => 'Ya tiene una solicitud pendiente para este correo.',
        ],
        'access_request_sent' => [
            'title' => 'Solicitud de acceso enviada.',
        ],
        'access_request_approved' => [
            'title' => 'Solicitud de acceso aprobada.',
        ],
        'access_request_denied' => [
            'title' => 'Solicitud de acceso denegada.',
        ],
    ],
];
