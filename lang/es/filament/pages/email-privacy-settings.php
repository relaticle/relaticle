<?php

declare(strict_types=1);

return [
    'title' => 'Privacidad del espacio de trabajo',
    'navigation_label' => 'Privacidad del espacio de trabajo',
    'tabs' => [
        'aria' => 'Configuración de correo del espacio de trabajo',
        'visibility' => 'Visibilidad del correo',
        'record_creation' => 'Creación de registros',
    ],
    'actions' => [
        'save' => 'Guardar',
    ],
    'visibility' => [
        'heading' => 'Visibilidad del correo',
        'description' => 'Oculte en todo Relaticle los correos y eventos del calendario que involucren a ciertos contactos.',
        'add' => 'Agregar contactos',
        'edit_enforcement' => 'Cambiar nivel de aplicación',
        'search_placeholder' => 'Buscar direcciones o dominios',
        'empty_heading' => 'Aún no hay contactos personalizados',
        'empty_hint' => 'Los valores predeterminados del sistema de arriba siempre se aplican. Agregue direcciones o dominios personalizados cuando necesite más cobertura.',
        'emails_label' => 'Direcciones de correo',
        'emails_placeholder' => 'p. ej. legal@acme.com',
        'emails_after_label' => 'Presione Enter para agregar cada dirección.',
        'domains_label' => 'Dominios',
        'domains_placeholder' => 'p. ej. acme.com',
        'domains_after_label' => 'Presione Enter para agregar cada dominio.',
        'include_subdomains_label' => 'Incluir subdominios',
        'include_subdomains_hint' => 'También oculta el correo de direcciones como user@mail.example.com cuando agrega example.com.',
        'include_subdomains_short' => 'Incluir subdominios',
        'enforcement' => [
            'protected' => [
                'label' => 'Protegido',
                'description' => 'Se oculta solo cuando todos los contactos del correo o evento están protegidos o bloqueados.',
            ],
            'blocked' => [
                'label' => 'Bloqueado',
                'description' => 'Se oculta siempre que este contacto figure en el correo o evento.',
            ],
        ],
        'notifications' => [
            'added' => 'Visibilidad del correo actualizada.',
            'updated' => 'Nivel de aplicación actualizado.',
            'deleted' => 'Entrada de visibilidad eliminada.',
        ],
        'table' => [
            'address' => 'Correo / Dominio',
            'subdomains' => 'Subdominios',
            'enforcement' => 'Nivel de aplicación',
            'updated' => 'Última actualización',
            'added_by' => 'Agregado por',
            'actions' => 'Acciones',
            'members_row' => 'Direcciones de correo de los miembros del espacio de trabajo',
            'system_default' => 'Predeterminado del sistema',
            'unknown_user' => 'Desconocido',
        ],
    ],
    'blocklist' => [
        'heading' => 'Lista de bloqueo del espacio de trabajo',
        'description' => 'Los correos de estas direcciones y dominios se ocultan en todos los buzones conectados de este espacio de trabajo.',
        'add' => 'Agregar a la lista de bloqueo',
        'empty_heading' => 'Aún no hay direcciones ni dominios',
        'empty_description' => 'Los correos de los dominios y direcciones en la lista de bloqueo no aparecerán en Relaticle para ningún buzón de este espacio de trabajo.',
        'emails_label' => 'Direcciones bloqueadas',
        'emails_placeholder' => 'noisy@example.com',
        'emails_after_label' => 'Presione Enter para agregar cada dirección.',
        'domains_label' => 'Dominios bloqueados',
        'domains_placeholder' => 'example.com',
        'domains_after_label' => 'Presione Enter para agregar cada dominio.',
        'notifications' => [
            'added' => 'Lista de bloqueo actualizada.',
            'deleted' => 'Entrada de la lista de bloqueo eliminada.',
        ],
        'table' => [
            'address' => 'Correo / Dominio',
            'type' => 'Tipo',
            'added_by' => 'Agregado por',
            'actions' => 'Acciones',
            'unknown_user' => 'Desconocido',
        ],
    ],
    'record_creation' => [
        'heading' => 'Creación automática de registros',
        'description' => 'Se aplica a todos los buzones y calendarios conectados del espacio de trabajo. Las direcciones de los dominios de correo de los miembros del espacio de trabajo nunca crean registros. Cambiar esta opción solo afecta a los correos y eventos que se sincronicen a partir de ahora.',
        'recommended' => 'Recomendado',
        'modes' => [
            'all' => [
                'label' => 'Todos los contactos',
                'description' => 'Se crearán registros para todos los contactos que aparezcan en los correos y eventos del calendario de los miembros del espacio de trabajo.',
            ],
            'selective' => [
                'label' => 'Creación selectiva de contactos',
                'description' => 'Solo se crearán registros para los contactos que reciban correos de los miembros del espacio de trabajo o aparezcan en sus eventos del calendario.',
            ],
            'none' => [
                'label' => 'Ninguno',
                'description' => 'No se creará ningún registro automáticamente. Los correos y eventos del calendario seguirán vinculados con los registros creados manualmente.',
            ],
        ],
        'companies' => [
            'label' => 'Crear registros de empresa automáticamente',
            'description' => 'Si está activado, se crea una empresa a partir del dominio de correo de una persona. Sigue la configuración de contactos de arriba. No está disponible cuando la creación de registros es Ninguno.',
        ],
    ],
    'tiers' => [
        'private' => [
            'label' => 'Privado',
            'description' => 'No se comparte nada. Solo usted puede ver estos correos.',
        ],
        'metadata_only' => [
            'label' => 'Solo participantes',
            'description' => 'Su espacio de trabajo ve los participantes y la fecha y hora. El asunto y el mensaje siguen siendo privados.',
        ],
        'subject' => [
            'label' => 'Asunto y participantes',
            'description' => 'Su espacio de trabajo ve el asunto, los participantes y la fecha y hora. El mensaje sigue siendo privado.',
        ],
        'full' => [
            'label' => 'Acceso completo',
            'description' => 'Su espacio de trabajo ve el cuerpo, el asunto y los archivos adjuntos.',
        ],
    ],
    'notifications' => [
        'saved' => 'Configuración de privacidad guardada.',
    ],
];
