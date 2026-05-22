<?php

declare(strict_types=1);

return [
    'title' => 'Tokens de acceso',

    'sections' => [
        'create' => [
            'title' => 'Crear token de acceso',
            'description' => 'Los tokens de acceso permiten que servicios de terceros y agentes de IA se autentiquen en la aplicación en su nombre.',
        ],
        'manage' => [
            'title' => 'Gestionar tokens de acceso',
            'description' => 'Puede eliminar cualquiera de sus tokens existentes si ya no los necesita.',
        ],
    ],

    'form' => [
        'name' => 'Nombre del token',
        'team' => 'Equipo',
        'expiration' => 'Caducidad',
        'expiration_placeholder' => 'Seleccionar caducidad...',
        'permissions' => 'Permisos',
        'token' => 'Token',
    ],

    'table' => [
        'columns' => [
            'name' => 'Nombre',
            'team' => 'Equipo',
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
            'description' => 'Por favor, copie su nuevo token de acceso. Por seguridad, no se mostrará de nuevo.',
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
            'description' => '¿Está seguro de que desea eliminar este token de acceso?',
        ],
    ],

    'notifications' => [
        'permissions_updated' => 'Permisos del token de acceso actualizados.',
        'deleted' => 'Token de acceso eliminado.',
    ],

    'empty_state' => [
        'heading' => 'Sin tokens de acceso',
        'description' => 'Cree un token arriba para empezar.',
    ],

    'integrations' => [
        'heading' => 'Qué hacer a continuación',
        'api_link' => 'API REST',
        'api_description' => 'Gestione datos del CRM mediante programación.',
        'mcp_link' => 'Servidor MCP',
        'mcp_description' => 'Conecte asistentes de IA como Claude.',
    ],

    'user_menu' => 'Tokens de acceso',
];
