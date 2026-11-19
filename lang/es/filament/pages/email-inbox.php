<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Correos',
    'account_filter' => [
        'label' => 'Buzón',
    ],
    'tabs' => [
        'drafts' => 'Borradores',
        'outbox' => 'Bandeja de salida',
        'templates' => 'Plantillas',
    ],
    'drafts' => [
        'columns' => [
            'subject' => 'Borrador',
            'last_edited' => 'Última edición',
        ],
        'actions' => [
            'open' => 'Seguir escribiendo',
            'delete' => 'Eliminar borrador',
            'delete_selected' => 'Eliminar borradores',
        ],
        'empty' => [
            'heading' => 'No hay borradores',
            'description' => 'Los mensajes que cierre sin enviar se guardan aquí.',
        ],
        'notifications' => [
            'deleted' => 'Borrador eliminado',
            'bulk_deleted' => '{1}1 borrador eliminado|[2,*]:count borradores eliminados',
        ],
    ],
    'outbox' => [
        'empty' => [
            'heading' => 'No hay correos en la bandeja de salida',
            'description' => 'Los correos en cola y programados aparecen aquí hasta que se envían.',
        ],
    ],
    'search' => [
        'placeholder' => 'Buscar correos…',
        'clear' => 'Borrar búsqueda',
    ],
    'subject' => [
        'none' => '(sin asunto)',
        'hidden' => '(asunto oculto)',
    ],
    'pagination' => [
        'previous' => 'Anterior',
        'next' => 'Siguiente',
        'range' => ':first–:last de :total',
    ],
    'list_empty' => [
        'no_results' => 'No hay resultados para ":search"',
        'all' => 'No hay correos',
        'sent' => 'No hay correos enviados',
        'inbox' => 'No hay correos recibidos',
    ],
    'list_row' => [
        'via' => 'a través de :name',
        'via_mailboxes' => '{1}a través de 1 buzón|[2,*]a través de :count buzones',
        'access_granted_via' => 'Acceso concedido a través de',
        'timestamp_yesterday' => 'Ayer, :time',
        'request_access' => 'Solicitar acceso a :name',
        'requested' => 'Solicitado',
        'opening' => 'Abriendo…',
    ],
    'pending_access' => [
        'heading' => '{1}1 solicitud de acceso pendiente|[2,*]:count solicitudes de acceso pendientes',
        'unknown_user' => 'Persona desconocida',
        'approve' => 'Aprobar',
        'deny' => 'Denegar',
    ],
    'compose' => [
        'label' => 'Redactar',
        'notifications' => [
            'queued' => [
                'title' => 'Correo en cola',
                'body' => 'Su correo se está enviando.',
            ],
        ],
    ],
    'privacy_gate' => [
        'metadata_only' => [
            'heading' => 'El cuerpo y el asunto del correo están restringidos',
            'description' => 'Puede ver los participantes y la fecha. Solicite acceso para ver el asunto y el cuerpo.',
        ],
        'subject_only' => [
            'heading' => 'El cuerpo del correo está restringido',
            'description' => 'Puede ver el asunto. El cuerpo completo del correo está oculto. Solicite acceso para ver más.',
        ],
        'private' => [
            'heading' => 'Este correo es privado',
            'description' => 'Solo el propietario del correo puede ver este contenido.',
        ],
        'request_hint' => 'Seleccione :action arriba para pedir más acceso.',
        'request_pending' => 'Su solicitud de acceso está pendiente.',
    ],
    'reader' => [
        'heading' => 'Ver correo',
        'internal' => 'Correo interno. Solo los miembros del espacio de trabajo pueden verlo.',
        'unknown_sender' => '(remitente desconocido)',
        'no_body' => '(sin cuerpo del mensaje)',
        'attachments' => [
            'unnamed' => 'Archivo sin nombre',
            'processing' => 'procesando…',
        ],
    ],
    'back_to_list' => 'Volver a la lista',
    'recipients' => [
        'from' => 'De',
        'to' => 'para',
        'to_heading' => 'Para',
        'cc' => 'cc',
        'cc_heading' => 'Cc',
        'more' => '{1}y 1 más|[2,*]y :count más',
        'details' => 'Remitente y destinatarios',
    ],
    'row_actions' => [
        'label' => 'Acciones del correo',
    ],
    'mark_all_read' => [
        'label' => 'Marcar todo como leído',
    ],
    'reply_forward' => [
        'modal_headings' => [
            'reply_all' => 'Responder a todos',
            'forward' => 'Reenviar',
            'reply' => 'Responder',
        ],
        'notifications' => [
            'queued' => [
                'title' => 'Correo en cola',
            ],
        ],
    ],
    'sharing' => [
        'label' => 'Uso compartido',
        'modal_heading' => 'Configuración de uso compartido',
        'fields' => [
            'privacy_tier' => [
                'label' => '¿Quién puede ver este correo?',
            ],
            'shares' => [
                'label' => 'Compartir con miembros específicos',
                'description' => 'Dé a miembros concretos más acceso del que permite la opción de arriba.',
                'add_action_label' => 'Agregar miembro',
                'new_item' => 'Nuevo miembro',
            ],
            'shared_with' => [
                'label' => 'Miembro',
                'placeholder' => 'Elija un miembro…',
            ],
            'tier' => [
                'label' => 'Nivel de acceso',
            ],
        ],
        'notifications' => [
            'saved' => [
                'title' => 'Configuración de uso compartido guardada.',
            ],
        ],
    ],
    'request_access' => [
        'label' => 'Solicitar acceso',
        'fields' => [
            'tier_requested' => [
                'label' => 'Nivel de acceso solicitado',
            ],
        ],
        'notifications' => [
            'pending' => [
                'title' => 'Ya tiene una solicitud pendiente para este correo.',
            ],
            'sent' => [
                'title' => 'Solicitud de acceso enviada.',
            ],
        ],
    ],
    'compose_form' => [
        'signature' => [
            'label' => 'Firma',
        ],
    ],
];
