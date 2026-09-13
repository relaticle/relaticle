<?php

declare(strict_types=1);

return [
    'title' => 'Tokens de acceso',

    'sections' => [
        'create' => [
            'title' => 'Crear token de acceso',
            'description' => 'Los tokens de acceso permiten que servicios externos y agentes de IA se autentiquen en la aplicación en tu nombre.',
        ],
        'manage' => [
            'title' => 'Gestionar tokens de acceso',
            'description' => 'Puedes eliminar cualquiera de tus tokens si ya no lo necesitas.',
        ],
    ],

    'form' => [
        'name' => 'Nombre del token',
        'team' => 'Espacio de trabajo',
        'expiration' => 'Caducidad',
        'expiration_placeholder' => 'Selecciona la caducidad...',
        'permissions' => 'Permisos',
        'token' => 'Token',
    ],

    'table' => [
        'columns' => [
            'name' => 'Nombre',
            'team' => 'Espacio de trabajo',
            'abilities' => 'Permisos',
            'expires_at' => 'Caduca',
            'last_used_at' => 'Último uso',
            'created_at' => 'Creado',
        ],
        'placeholders' => [
            'no_team' => '—',
            'never' => 'Nunca',
        ],
    ],

    'actions' => [
        'create' => 'Crear',
    ],

    'permissions' => [
        'all' => 'Todos',
    ],

    'modals' => [
        'show_token' => [
            'title' => 'Token de acceso',
            'description' => 'Copia tu nuevo token de acceso. Por seguridad, no se volverá a mostrar.',
            'cancel_label' => 'Cerrar',
            'copy_to_clipboard_tooltip' => 'Copiar al portapapeles',
            'copied_tooltip' => '¡Copiado!',
        ],
        'permissions' => [
            'title' => 'Permisos del token de acceso',
            'action_label' => 'Permisos',
        ],
        'delete' => [
            'title' => 'Eliminar token de acceso',
            'description' => '¿Seguro que quieres eliminar este token de acceso?',
        ],
    ],

    'notifications' => [
        'permissions_updated' => 'Permisos del token de acceso actualizados.',
        'deleted' => 'Token de acceso eliminado.',
    ],

    'empty_state' => [
        'heading' => 'No hay tokens de acceso',
        'description' => 'Crea un token arriba para empezar.',
    ],

    'connectors' => [
        'title' => 'Conectores de IA',
        'description' => 'Asistentes como Claude o ChatGPT que has conectado desde la pantalla de autorización. Al revocar uno, pierde el acceso de inmediato.',
        'columns' => [
            'name' => 'Conector',
            'team' => 'Espacio de trabajo',
            'active_tokens' => 'Tokens activos',
        ],
        'actions' => [
            'revoke' => 'Revocar',
        ],
        'modals' => [
            'revoke' => [
                'title' => 'Revocar conector',
                'description' => 'El asistente perderá el acceso a este espacio de trabajo de inmediato. Puedes volver a conectarlo cuando quieras.',
            ],
        ],
        'notifications' => [
            'revoked' => 'Conector revocado.',
        ],
        'empty_state' => [
            'heading' => 'No hay conectores de IA',
            'description' => 'Conecta Crabdev CRM desde Claude o ChatGPT y el conector aparecerá aquí.',
        ],
    ],

    'integrations' => [
        'heading' => 'Siguientes pasos',
        'api_link' => 'API REST',
        'api_description' => 'Gestiona los datos del CRM desde código.',
        'mcp_link' => 'Servidor MCP',
        'mcp_description' => 'Conecta asistentes de IA como Claude.',
    ],

    'user_menu' => 'Tokens de acceso',
];
