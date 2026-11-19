<?php

declare(strict_types=1);

return [
    'title' => 'Tokens de acceso',

    'sections' => [
        'create' => [
            'title' => 'Crear token de acceso',
            'description' => 'Los tokens de acceso permiten que servicios de terceros y agentes de IA se autentiquen en nuestra aplicación en su nombre.',
        ],
        'manage' => [
            'title' => 'Administrar tokens de acceso',
            'description' => 'Puede eliminar cualquiera de sus tokens existentes si ya no los necesita.',
        ],
    ],

    'form' => [
        'name' => 'Nombre del token',
        'workspace' => 'Espacio de trabajo',
        'expiration' => 'Vencimiento',
        'expiration_placeholder' => 'Seleccione el vencimiento...',
        'permissions' => 'Permisos',
        'token' => 'Token',
    ],

    'table' => [
        'columns' => [
            'name' => 'Nombre',
            'workspace' => 'Espacio de trabajo',
            'abilities' => 'Permisos',
            'expires_at' => 'Vence',
            'last_used_at' => 'Último uso',
            'created_at' => 'Creado',
        ],
        'placeholders' => [
            'no_workspace' => '—',
            'never' => 'Nunca',
        ],
    ],

    'actions' => [
        'create' => 'Crear',
    ],

    'permissions' => [
        'all' => 'Todos',
        'read' => 'Leer',
        'create' => 'Crear',
        'update' => 'Actualizar',
        'delete' => 'Eliminar',
        'email_read' => 'Leer correo',
        'email_draft' => 'Redactar borradores de correo',
        'email_send' => 'Enviar correo',
    ],

    'modals' => [
        'show_token' => [
            'title' => 'Token de acceso',
            'description' => 'Copie su nuevo token de acceso. Por su seguridad, no se volverá a mostrar.',
            'cancel_label' => 'Cerrar',
            'copy_to_clipboard_tooltip' => 'Copiar al portapapeles',
            'copied_tooltip' => '¡Copiado!',
        ],
        'permissions' => [
            'title' => 'Permisos del token de acceso',
            'action_label' => 'Permisos',
            'tooltip' => 'Editar permisos',
            'heading' => 'Permisos: :name',
        ],
        'delete' => [
            'title' => 'Eliminar token de acceso',
            'description' => '¿Está seguro de que desea eliminar este token de acceso?',
        ],
    ],

    'expirations' => [
        '1' => '1 día',
        '7' => '7 días',
        '30' => '30 días',
        '60' => '60 días',
        '90' => '90 días',
        '180' => '180 días',
        '365' => '1 año',
        '0' => 'Sin vencimiento',
    ],

    'notifications' => [
        'workspace_not_member' => 'Usted no pertenece a este espacio de trabajo.',
        'permissions_updated' => 'Permisos del token de acceso actualizados.',
        'deleted' => 'Token de acceso eliminado.',
    ],

    'empty_state' => [
        'heading' => 'No hay tokens de acceso',
        'description' => 'Cree un token arriba para empezar.',
    ],

    'connectors' => [
        'title' => 'Conectores de IA',
        'description' => 'Asistentes y aplicaciones, como Claude, ChatGPT y Maxforms, que conectó mediante la pantalla de consentimiento. Revocar uno invalida su acceso de inmediato.',
        'columns' => [
            'name' => 'Conector',
            'workspace' => 'Espacio de trabajo',
            'active_tokens' => 'Tokens activos',
        ],
        'actions' => [
            'revoke' => 'Revocar',
        ],
        'modals' => [
            'revoke' => [
                'title' => 'Revocar conector',
                'description' => 'El asistente perderá el acceso a este espacio de trabajo de inmediato. Puede conectarlo de nuevo en cualquier momento.',
            ],
        ],
        'notifications' => [
            'revoked' => 'Conector revocado.',
        ],
        'empty_state' => [
            'heading' => 'No hay conectores de IA',
            'description' => 'Conecte Relaticle desde Claude o ChatGPT y el conector aparecerá aquí.',
        ],
    ],

    'integrations' => [
        'heading' => 'Qué hacer a continuación',
        'api_link' => 'API REST',
        'api_description' => 'Administre los datos del CRM mediante programación.',
        'mcp_link' => 'MCP server',
        'mcp_description' => 'Conecte asistentes de IA como Claude.',
        'forms_link' => 'Formularios web',
        'forms_description' => 'Convierta los envíos de formularios en registros con Maxforms.',
    ],

    'user_menu' => 'Tokens de acceso',
];
