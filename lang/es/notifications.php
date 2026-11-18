<?php

declare(strict_types=1);

return [
    'title' => 'Notificaciones',

    'throttled' => [
        'title' => 'Demasiadas solicitudes',
        'body' => 'Espere :seconds segundos antes de intentarlo de nuevo.',
    ],

    'digest' => [
        'heading' => 'Resumen diario',
        'title' => 'Activar resumen diario',
        'description' => 'Incluye las tareas vencidas y las que vencen hoy. Cada espacio de trabajo envía su propio correo electrónico cada mañana cuando tiene tareas por vencer o vencidas.',
    ],

    'collaboration' => [
        'heading' => 'Notificaciones de colaboración',
        'notify_me_about' => 'Notificarme sobre',
    ],

    'channels' => [
        'in_app' => 'Aplicación',
        'email' => 'Correo electrónico',
    ],

    'types' => [
        'task_assigned' => [
            'label' => 'Asignaciones de tareas',
            'description' => 'Notificarme cuando se me asigne una tarea.',
        ],
        'email_access' => [
            'label' => 'Acceso a correos',
            'description' => 'Enviarme un correo electrónico cuando alguien pida leer un correo que me pertenece. Notificarme cuando mi propia solicitud se apruebe, se deniegue o venza.',
        ],
        'email_shared' => [
            'label' => 'Correos compartidos',
            'description' => 'Notificarme cuando alguien comparta un correo conmigo.',
        ],
        'task_digest' => [
            'label' => 'Resumen diario',
            'description' => 'Notificarme cada mañana sobre las tareas vencidas y las que vencen hoy.',
        ],
    ],

    'events' => [
        'task_assigned' => [
            'title' => ':actor le asignó una tarea',
            'title_without_actor' => 'Tiene una nueva tarea',
            'action' => 'Ver tarea',
        ],
    ],

    'panel' => [
        'heading' => 'Bandeja de entrada',
        'open' => 'Abrir bandeja de entrada',
        'open_with_count' => 'Abrir bandeja de entrada, :count elemento requiere su atención|Abrir bandeja de entrada, :count elementos requieren su atención',
        'close' => 'Cerrar bandeja de entrada',
        'settings' => 'Configuración de notificaciones',
        'announce' => ':count notificación nueva|:count notificaciones nuevas',
        'announcements' => [
            'archived' => 'Archivada: :title',
            'approved' => 'Aprobada: :title',
            'denied' => 'Denegada: :title',
            'already_decided' => 'Esta solicitud ya se resolvió: :title',
        ],
        'footer' => 'Se muestran las últimas :count notificaciones.',
        'tabs' => [
            'label' => 'Secciones de la bandeja de entrada',
            'notifications' => 'Notificaciones',
            'requests' => 'Solicitudes',
        ],
        'mark_all_read' => 'Marcar todo como leído',
        'groups' => [
            'today' => 'Hoy',
            'yesterday' => 'Ayer',
            'earlier' => 'Anteriores',
        ],
        'row' => [
            'unread' => 'No leída',
            'mark_read' => 'Marcar como leída',
            'mark_unread' => 'Marcar como no leída',
            'archive' => 'Archivar',
        ],
        'requests' => [
            'view' => 'Ver',
            'approve' => 'Aprobar',
            'deny' => 'Denegar',
            'approved' => 'Aprobada',
            'denied' => 'Denegada',
            'already_decided' => 'Esta solicitud ya se resolvió.',
            'expires' => 'Vence :when',
        ],
        'empty' => [
            'notifications' => [
                'heading' => 'Está al día',
                'description' => 'Aquí llegan las asignaciones de tareas, las importaciones y exportaciones, y otras novedades de este espacio de trabajo.',
            ],
            'requests' => [
                'heading' => 'No hay solicitudes pendientes',
                'description' => 'Cuando un miembro pida algo que le pertenece, usted lo decide aquí.',
            ],
            'settings' => 'Configuración',
        ],
    ],

    'saved' => 'Preferencias de notificaciones actualizadas.',
];
