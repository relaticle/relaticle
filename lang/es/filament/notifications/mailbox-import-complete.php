<?php

declare(strict_types=1);

return [
    'title' => 'Importación completada',
    'title_with_issues' => 'Importación finalizada con problemas',
    'body' => 'Importación completada: :imported.',
    'body_with_issues' => 'Importación finalizada con problemas: :imported. :failures',
    'imported_emails' => '{0}:count correos|{1}:count correo|[2,*]:count correos',
    'imported_calendar_events' => '{0}:count eventos de calendario|{1}:count evento de calendario|[2,*]:count eventos de calendario',
    'imported_with_calendar' => ':emails y :events',
    'imported_without_calendar' => ':emails',
    'failed_messages' => '{1}No se pudo importar :count mensaje|[2,*]No se pudieron importar :count mensajes',
    'failed_calendar_events' => '{1}No se pudo importar :count evento de calendario|[2,*]No se pudieron importar :count eventos de calendario',
    'calendar_did_not_finish' => 'La importación del calendario no finalizó.',
    'email_history_did_not_finish' => 'No se pudo importar el correo más antiguo.',
    'retry_success' => [
        'title' => 'Reintento de importación completado',
        'body' => 'Los mensajes de :email que faltaban ya están importados. Ahora hay :imported en Relaticle.',
    ],
    'actions' => [
        'retry' => 'Reintentar',
        'open_inbox' => 'Abrir bandeja de entrada',
    ],
    'retry' => [
        'queued' => [
            'title' => 'Reintento en cola.',
            'body' => 'Estamos reintentando lo que no se pudo importar. Le avisaremos cuando termine.',
        ],
        'unavailable' => [
            'title' => 'Reintento no disponible',
            'body' => 'No hay importaciones fallidas que reintentar para esta importación.',
        ],
    ],
    'mail' => [
        'subject' => 'La importación de su buzón se completó',
        'subject_with_issues' => 'La importación de su buzón finalizó con problemas',
        'retry_subject' => 'El reintento de importación de su buzón fue exitoso',
        'greeting' => 'Hola, :name:',
        'line' => 'Importación completada desde :email: :imported. El correo nuevo seguirá sincronizándose automáticamente.',
        'line_with_issues' => 'Importación finalizada con problemas desde :email: :imported. :failures',
        'retry_line' => 'Importamos los mensajes que faltaban de :email. Ahora hay :imported en Relaticle.',
    ],
];
