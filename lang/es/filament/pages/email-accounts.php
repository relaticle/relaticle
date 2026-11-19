<?php

declare(strict_types=1);

return [
    'title' => 'Buzones y calendarios',
    'navigation_label' => 'Buzones',
    'subheading' => 'Administre y sincronice sus buzones y calendarios para mantener todo organizado.',
    'actions' => [
        'connect_gmail' => 'Conectar cuenta de Google',
        'connect_azure' => 'Conectar cuenta de Microsoft',
        'manage' => 'Administrar',
        'reconnect' => 'Reconectar',
        'set_default' => 'Establecer como predeterminado',
        'retry_sync' => 'Reintentar sincronización',
        'disconnect' => 'Desconectar buzón',
        'sync_calendar' => [
            'enable_label' => 'Sincronizar calendario',
            'disable_label' => 'Desactivar sincronización del calendario',
            'enable_heading' => 'Activar sincronización del calendario',
            'disable_heading' => 'Desactivar sincronización del calendario',
            'disable_description' => 'Se dejarán de sincronizar los eventos del calendario de este buzón.',
            'enable_description' => 'Será redirigido a :provider para conceder acceso al calendario.',
            'fallback_provider' => 'el proveedor',
        ],
        'sync_calendar_now' => 'Sincronizar ahora',
        'reimport_history' => [
            'label' => 'Volver a importar el historial',
            'heading' => '¿Volver a importar el historial del buzón?',
            'description' => 'Los correos y eventos ya sincronizados permanecen en Relaticle. Crearemos las personas y empresas que falten según la configuración de creación de registros del espacio de trabajo e importaremos los mensajes que aún no estén guardados. Esto puede tardar en un buzón grande.',
        ],
        'retry_failed_import' => [
            'label' => 'Reintentar',
        ],
    ],
    'settings' => [
        'sync_inbox' => [
            'label' => 'Sincronizar bandeja de entrada',
            'helper_text' => 'Traiga a Relaticle los correos que recibe.',
        ],
        'sync_sent' => [
            'label' => 'Sincronizar enviados',
            'helper_text' => 'Traiga a Relaticle los correos que envía desde este buzón.',
        ],
        'hourly_send_limit' => [
            'label' => 'Límite de envíos por hora',
            'placeholder' => 'Predeterminado: :default',
            'helper_text' => 'Déjelo en blanco para usar el valor predeterminado del espacio de trabajo.',
        ],
        'daily_send_limit' => [
            'label' => 'Límite de envíos por día',
            'placeholder' => 'Predeterminado: :default',
            'helper_text' => 'Déjelo en blanco para usar el valor predeterminado del espacio de trabajo.',
        ],
        'modal_heading' => 'Configuración del buzón',
        'submit_label' => 'Guardar',
    ],
    'notifications' => [
        'connected' => [
            'title' => 'Buzón conectado.',
            'body' => 'Los correos y las reuniones aparecerán a medida que avance la importación.',
        ],
        'cancelled' => [
            'title' => 'No se conectó nada. Puede conectar un buzón cuando quiera.',
        ],
        'limit_reached' => [
            'title' => '{1}Se alcanzó el límite de buzones. Este espacio de trabajo puede conectar 1 buzón. Mejore su plan para conectar más.|[2,*]Se alcanzó el límite de buzones. Este espacio de trabajo puede conectar :count buzones. Mejore su plan para conectar más.',
            'trial' => 'Se alcanzó el límite de buzones de la prueba gratuita. Mejore su plan ahora para sincronizar más buzones.',
            'upgrade' => 'Mejorar plan',
        ],
        'calendar_sync_queued' => [
            'title' => 'Se inició la sincronización del calendario.',
            'body' => 'Sus reuniones se actualizarán en esta página cuando termine la sincronización.',
        ],
        'disconnected' => [
            'title' => 'Buzón desconectado.',
            'body' => 'Se eliminaron el buzón y sus firmas.',
        ],
        'default_set' => [
            'title' => 'Buzón predeterminado actualizado.',
            'body' => ':email es ahora su buzón de envío predeterminado.',
        ],
        'reimport_queued' => [
            'title' => 'Importación del historial en cola.',
            'body' => 'Las personas y empresas aparecerán a medida que avance la importación. Puede seguir usando Relaticle.',
        ],
        'sync_retry_queued' => [
            'title' => 'Reintento de sincronización en cola.',
            'body' => 'Estamos obteniendo lo que no se pudo guardar. Esta página se actualiza al terminar.',
        ],
        'retry_failed_import_queued' => [
            'title' => 'Reintento en cola.',
            'body' => 'Estamos reintentando los mensajes que no se pudieron importar. Esta página se actualiza al terminar.',
        ],
        'retry_failed_import_unavailable' => [
            'title' => 'Reintento no disponible',
            'body' => 'No hay importaciones fallidas que reintentar en esta importación.',
        ],
    ],
    'default_badge' => 'Predeterminado',
    'synced_at' => 'Sincronizado :time',
    'in_sync' => 'Sincronizado',
    'sync_error' => [
        'badge' => 'Problema de sincronización',
        'heading' => 'No se pudieron sincronizar algunos elementos',
        'reasons' => [
            'google_mail_disabled' => 'Gmail no está activado para esta cuenta de Google Workspace. Pida a su administrador de Google que lo active y vuelva a conectar.',
        ],
    ],
    'importing' => 'Sincronizando',
    'importing_percent' => ':percent%',
    'importing_count' => '{1}:count correo|[2,*]:count correos',
    'statuses' => [
        'active' => 'Activo',
        'error' => 'Problema de sincronización',
        'disconnected' => 'Desconectado',
        'reauth_required' => 'Debe reconectar',
    ],
    'history_import' => [
        'processed' => ':processed de :total procesados',
        'successful_jobs' => ':count importados correctamente',
    ],
    'history_import_failure' => [
        'max_attempts' => 'No se pudo guardar este mensaje después de varios intentos. Use Reintentar arriba. Si sigue fallando, espere unos minutos e inténtelo de nuevo.',
    ],
    'capabilities' => [
        'email' => 'Correo electrónico',
        'calendar' => 'Calendario',
    ],
    'not_connected' => [
        'inbox' => [
            'heading' => 'Envíe correos desde Relaticle',
            'description' => 'Conecte su buzón para leer y responder sin salir de Relaticle. También obtiene envíos masivos, plantillas y archivos adjuntos.',
        ],
        'record' => [
            'heading' => 'Mantenga los correos en el registro',
            'description' => 'Conecte su buzón para ver cada conversación con este registro y responder con un clic.',
        ],
        'meetings' => [
            'heading' => 'Vea sus reuniones en Relaticle',
            'description' => 'Conecte su buzón para seguir las reuniones junto a sus registros del CRM.',
        ],
    ],
];
