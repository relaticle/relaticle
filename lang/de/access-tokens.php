<?php

declare(strict_types=1);

return [
    'title' => 'Zugriffstoken',

    'sections' => [
        'create' => [
            'title' => 'Zugriffstoken erstellen',
            'description' => 'Mit Zugriffstoken können Drittdienste und KI-Agenten sich in Ihrem Namen bei unserer Anwendung authentifizieren.',
        ],
        'manage' => [
            'title' => 'Zugriffstoken verwalten',
            'description' => 'Sie können jedes vorhandene Token löschen, wenn Sie es nicht mehr benötigen.',
        ],
    ],

    'form' => [
        'name' => 'Tokenname',
        'workspace' => 'Arbeitsbereich',
        'expiration' => 'Ablauf',
        'expiration_placeholder' => 'Ablauf auswählen…',
        'permissions' => 'Berechtigungen',
        'token' => 'Token',
    ],

    'table' => [
        'columns' => [
            'name' => 'Name',
            'workspace' => 'Arbeitsbereich',
            'abilities' => 'Berechtigungen',
            'expires_at' => 'Läuft ab',
            'last_used_at' => 'Zuletzt verwendet',
            'created_at' => 'Erstellt',
        ],
        'placeholders' => [
            'no_workspace' => '—',
            'never' => 'Nie',
        ],
    ],

    'actions' => [
        'create' => 'Erstellen',
    ],

    'permissions' => [
        'all' => 'Alle',
        'read' => 'Lesen',
        'create' => 'Erstellen',
        'update' => 'Aktualisieren',
        'delete' => 'Löschen',
        'email_read' => 'E-Mails lesen',
        'email_draft' => 'E-Mails entwerfen',
        'email_send' => 'E-Mails senden',
    ],

    'modals' => [
        'show_token' => [
            'title' => 'Zugriffstoken',
            'description' => 'Bitte kopieren Sie Ihr neues Zugriffstoken. Aus Sicherheitsgründen wird es nicht erneut angezeigt.',
            'cancel_label' => 'Schließen',
            'copy_to_clipboard_tooltip' => 'In die Zwischenablage kopieren',
            'copied_tooltip' => 'Kopiert!',
        ],
        'permissions' => [
            'title' => 'Berechtigungen des Zugriffstokens',
            'action_label' => 'Berechtigungen',
            'tooltip' => 'Berechtigungen bearbeiten',
            'heading' => 'Berechtigungen: :name',
        ],
        'delete' => [
            'title' => 'Zugriffstoken löschen',
            'description' => 'Möchten Sie dieses Zugriffstoken wirklich löschen?',
        ],
    ],

    'expirations' => [
        '1' => '1 Tag',
        '7' => '7 Tage',
        '30' => '30 Tage',
        '60' => '60 Tage',
        '90' => '90 Tage',
        '180' => '180 Tage',
        '365' => '1 Jahr',
        '0' => 'Kein Ablauf',
    ],

    'notifications' => [
        'workspace_not_member' => 'Sie gehören diesem Arbeitsbereich nicht an.',
        'permissions_updated' => 'Berechtigungen des Zugriffstokens aktualisiert.',
        'deleted' => 'Zugriffstoken gelöscht.',
    ],

    'empty_state' => [
        'heading' => 'Keine Zugriffstoken',
        'description' => 'Erstellen Sie oben ein Token, um loszulegen.',
    ],

    'connectors' => [
        'title' => 'KI-Konnektoren',
        'description' => 'Assistenten und Apps wie Claude, ChatGPT und Maxforms, die Sie über den Zustimmungsbildschirm verbunden haben. Wenn Sie einen widerrufen, wird sein Zugriff sofort ungültig.',
        'columns' => [
            'name' => 'Konnektor',
            'workspace' => 'Arbeitsbereich',
            'active_tokens' => 'Aktive Token',
        ],
        'actions' => [
            'revoke' => 'Widerrufen',
        ],
        'modals' => [
            'revoke' => [
                'title' => 'Konnektor widerrufen',
                'description' => 'Der Assistent verliert sofort den Zugriff auf diesen Arbeitsbereich. Sie können ihn jederzeit erneut verbinden.',
            ],
        ],
        'notifications' => [
            'revoked' => 'Konnektor widerrufen.',
        ],
        'empty_state' => [
            'heading' => 'Keine KI-Konnektoren',
            'description' => 'Verbinden Sie Relaticle über Claude oder ChatGPT. Der Konnektor erscheint dann hier.',
        ],
    ],

    'integrations' => [
        'heading' => 'Nächste Schritte',
        'api_link' => 'REST API',
        'api_description' => 'CRM-Daten programmatisch verwalten.',
        'mcp_link' => 'MCP-Server',
        'mcp_description' => 'KI-Assistenten wie Claude verbinden.',
        'forms_link' => 'Webformulare',
        'forms_description' => 'Formulareinsendungen mit Maxforms in Einträge verwandeln.',
    ],

    'user_menu' => 'Zugriffstoken',
];
