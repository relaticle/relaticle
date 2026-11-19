<?php

declare(strict_types=1);

return [
    'actions' => [
        'manage_sharing' => [
            'label' => 'Freigabe verwalten',
            'modal_heading' => 'Freigabeeinstellungen',
            'submit' => 'Speichern',
        ],
        'summarize_thread' => [
            'label' => 'Thread zusammenfassen',
            'modal_heading' => 'KI-Thread-Zusammenfassung',
            'empty' => 'Keine Zusammenfassung für diesen Thread verfügbar.',
            'failed' => 'Der KI-Anbieter konnte keine Zusammenfassung erstellen. Versuchen Sie es später erneut.',
            'generated' => 'Generiert vor :time',
            'copy' => 'Kopieren',
            'copied' => 'Kopiert',
        ],
        'request_access' => [
            'label' => 'Zugriff anfordern',
            'modal_heading' => 'Zugriffsanfrage',
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
            'label' => 'Mit bestimmten Teammitgliedern teilen',
        ],
        'shared_with' => [
            'label' => 'Teammitglied',
        ],
        'tier' => [
            'label' => 'Zugriffsstufe',
        ],
        'tier_requested' => [
            'label' => 'Angeforderte Zugriffsstufe',
        ],
    ],
    'empty' => [
        'heading' => 'Keine E-Mails',
        'description' => 'Diese Aufzeichnung hat keine E-Mails oder sie sind aufgrund von Berechtigungen verborgen.',
        'compose' => 'Verfassen',
    ],
    'protected' => [
        'heading' => 'Nichts anzuzeigen',
        'description' => 'Diese Aufzeichnung ist geschützt. Ihre E-Mails und Termine bleiben hier verborgen.',
    ],
    'blocked' => [
        'heading' => 'Nichts anzuzeigen',
        'description' => 'Diese Aufzeichnung ist blockiert. Ihre E-Mails und Termine bleiben hier verborgen.',
    ],

    'notifications' => [
        'sharing_saved' => [
            'title' => 'Freigabeeinstellungen gespeichert.',
        ],
        'pending_request' => [
            'title' => 'Sie haben bereits eine ausstehende Anfrage für diese E-Mail.',
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
