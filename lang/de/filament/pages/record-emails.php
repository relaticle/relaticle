<?php

declare(strict_types=1);

return [
    'actions' => [
        'manage_sharing' => [
            'label' => 'Freigabe',
            'modal_heading' => 'Freigabeeinstellungen',
            'submit' => 'Speichern',
        ],
        'summarize_thread' => [
            'label' => 'Unterhaltung zusammenfassen',
            'modal_heading' => 'KI-Zusammenfassung der Unterhaltung',
            'empty' => 'Für diese Unterhaltung ist keine Zusammenfassung verfügbar.',
            'failed' => 'Der KI-Anbieter konnte keine Zusammenfassung erstellen. Versuchen Sie es später erneut.',
            'generated' => 'Erstellt :time',
            'copy' => 'Kopieren',
            'copied' => 'Kopiert',
        ],
        'request_access' => [
            'label' => 'Zugriff anfragen',
            'modal_heading' => 'Zugriff anfragen',
        ],
        'approve_access_request' => [
            'modal_heading' => 'Zugriffsanfrage genehmigen',
        ],
        'deny_access_request' => [
            'modal_heading' => 'Zugriffsanfrage ablehnen',
        ],
    ],
    'fields' => [
        'privacy_tier' => [
            'label' => 'Wer kann diese E-Mail sehen?',
        ],
        'shares' => [
            'label' => 'Mit bestimmten Mitgliedern teilen',
        ],
        'shared_with' => [
            'label' => 'Mitglied',
        ],
        'tier' => [
            'label' => 'Zugriffsstufe',
        ],
        'tier_requested' => [
            'label' => 'Angefragte Zugriffsstufe',
        ],
    ],
    'empty' => [
        'heading' => 'Keine E-Mails',
        'description' => 'Dieser Eintrag hat keine E-Mails, oder sie sind wegen fehlender Berechtigungen ausgeblendet.',
        'compose' => 'Verfassen',
    ],
    'protected' => [
        'heading' => 'Hier gibt es nichts anzuzeigen',
        'description' => 'Dieser Eintrag ist geschützt. Seine E-Mails und Meetings bleiben hier ausgeblendet.',
    ],
    'blocked' => [
        'heading' => 'Hier gibt es nichts anzuzeigen',
        'description' => 'Dieser Eintrag ist gesperrt. Seine E-Mails und Meetings bleiben hier ausgeblendet.',
    ],

    'notifications' => [
        'sharing_saved' => [
            'title' => 'Freigabeeinstellungen gespeichert.',
        ],
        'pending_request' => [
            'title' => 'Für diese E-Mail liegt bereits eine ausstehende Anfrage vor.',
        ],
        'access_request_sent' => [
            'title' => 'Zugriffsanfrage gesendet.',
        ],
        'access_request_approved' => [
            'title' => 'Zugriffsanfrage genehmigt.',
        ],
        'access_request_denied' => [
            'title' => 'Zugriffsanfrage abgelehnt.',
        ],
    ],
];
