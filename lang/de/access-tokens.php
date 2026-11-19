<?php

declare(strict_types=1);

return [
    'title' => 'Zugangstoken',

    'sections' => [
        'create' => [
            'title' => 'Zugangstoken erstellen',
            'description' => 'Zugangstoken erlauben es Drittanbietern und KI-Agenten, auf unserer Anwendung im Namen Ihres Kunden zu authentifizieren.',
        ],
        'manage' => [
            'title' => 'Zugangstoken verwalten',
            'description' => 'Sie können jeden Ihrer bestehenden Token löschen, wenn er nicht mehr benötigt wird.',
        ],
    ],

    'form' => [
        'name' => 'Token-Name',
        'workspace' => 'Arbeitsbereich',
        'expiration' => 'Ablaufdatum',
        'expiration_placeholder' => 'Ablaufdatum auswählen...',
        'permissions' => 'Berechtigungen',
        'token' => 'Token',
    ],

    'table' => [
        'columns' => [
            'name' => 'Name',
            'workspace' => 'Arbeitsbereich',
            'abilities' => 'Berechtigungen',
            'expires_at' => 'Abläuft am',
            'last_used_at' => 'Letzter Gebrauch',
            'created_at' => 'Erstellt am',
        ],
        'placeholders' => [
            'no_workspace' => '—',
            'never' => 'Niemals',
        ],
    },

    'actions' => [
        'create' => 'Erstellen',
    ],

    'permissions' => [
        'all' => 'Alle',
        'email_read' => 'E-Mail lesen',
        'email_draft' => 'E-Mail-Entwürfe erstellen',
        'email_send' => 'Als Sie E-Mails senden',
    ],

    'modals' => [
        'show_token' => [
            'title' => 'Zugangstoken',
            'description' => 'Bitte kopieren Sie Ihren neuen Zugangstoken. Aus Sicherheitsgründen wird er nicht erneut angezeigt.',
            'cancel_label' => 'Schließen',
            'copy_to_clipboard_tooltip' => 'In die Zwischenablage kopieren',
            'copied_tooltip' => 'Kopiert!',
        ],
        'permissions' => [
            'title' => 'Berechtigungen für den Zugangstoken',
            'action_label' => 'Berechtigungen',
        ],
        'delete' => [
            'title' => 'Zugangstoken löschen',
            'description' => 'Möchten Sie diesen Zugangstoken wirklich löschen?',
        ],
    ],

    'notifications' => [
        'permissions_updated' => 'Berechtigungen für den Zugangstoken aktualisiert.',
        'deleted' => 'Zugangstoken gelöscht.',
    ],

    'empty_state' => [
        'heading' => 'Keine Zugangstoken',
        'description' => 'Erstellen Sie oben einen Token, um zu starten.',
    ],

    'connectors' => [
        'title' => 'KI-Verbindungen',
        'description' => 'Assistenten und Apps wie Claude, ChatGPT und Maxforms, die Sie über den Einwilligungsbildschirm verbunden haben. Die Revokation einer ungültigt sofort ihren Zugang.',
        'columns' => [
            'name' => 'Verbindung',
            'workspace' => 'Arbeitsbereich',
            'active_tokens' => 'Aktive Token',
        ],
        'actions' => [
            'revoke' => 'Revokieren',
        ],
        'modals' => [
            'revoke' => [
                'title' => 'Verbindung revokieren',
                'description' => 'Der Assistent verliert sofort den Zugang zu diesem Arbeitsbereich. Sie können ihn jederzeit erneut verbinden.',
            ],
        ],
    ],
