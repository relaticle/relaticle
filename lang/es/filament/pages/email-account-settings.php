<?php

declare(strict_types=1);

return [
    'subheading' => 'Actualice los permisos y la configuración de su buzón.',
    'tabs' => [
        'general' => 'General',
        'blocklist' => 'Lista de bloqueo solo de este buzón',
        'signatures' => 'Firmas',
    ],
    'general' => [
        'sync_heading' => 'Sincronización y envío',
    ],
    'sharing' => [
        'label' => 'Uso compartido del correo',
        'hint' => 'Lo que su espacio de trabajo ve de este buzón. Los demás buzones conservan su propio nivel.',
    ],
    'blocklist' => [
        'label' => 'Direcciones y dominios bloqueados',
        'emails_label' => 'Direcciones bloqueadas',
        'emails_placeholder' => 'noisy@example.com',
        'emails_after_label' => 'Presione Enter para agregar cada dirección.',
        'domains_label' => 'Dominios bloqueados',
        'domains_placeholder' => 'example.com',
        'domains_after_label' => 'Presione Enter para agregar cada dominio.',
        'include_subdomains_label' => 'Incluir subdominios',
        'include_subdomains_hint' => 'También oculta el correo de direcciones como user@mail.example.com cuando bloquea example.com.',
        'include_subdomains_column' => 'Subdominios',
        'include_subdomains_short' => 'Incluir subdominios',
        'hint' => 'Se oculta solo en este buzón. Los demás buzones conectados no se ven afectados.',
        'add' => 'Agregar a la lista de bloqueo',
        'empty_heading' => 'Aún no hay direcciones ni dominios',
        'empty_description' => 'Los correos de los dominios y direcciones en la lista de bloqueo no aparecerán en este buzón.',
        'notifications' => [
            'added' => 'Lista de bloqueo actualizada.',
            'deleted' => 'Entrada de la lista de bloqueo eliminada.',
        ],
    ],
    'signatures' => [
        'label' => 'Firmas',
        'hint' => 'Se usan al redactar desde este buzón.',
        'add' => 'Agregar firma',
        'empty_heading' => 'Aún no hay firmas',
        'empty_description' => 'Cree una firma para agregarla a los correos que envíe desde este buzón.',
    ],
    'actions' => [
        'save' => 'Guardar cambios',
    ],
    'notifications' => [
        'saved' => 'Configuración del buzón guardada.',
    ],
];
