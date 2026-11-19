<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Zugriffsanfragen',
    'tabs' => [
        'aria' => 'Tabs für Zugriffsanfragen',
        'incoming' => 'Eingehend',
        'outgoing' => 'Gesendet',
    ],
    'filters' => [
        'label' => 'Status',
        'all' => 'Alle',
    ],
    'statuses' => [
        'pending' => 'Ausstehend',
        'approved' => 'Genehmigt',
        'denied' => 'Abgelehnt',
        'cancelled' => 'Abgebrochen',
        'expired' => 'Abgelaufen',
    ],
    'columns' => [
        'requested_by' => 'Angefragt von',
        'sent_to' => 'Gesendet an',
        'email' => 'E-Mail',
        'access' => 'Zugriff',
        'requested' => 'Angefragt',
    ],
    'search' => [
        'placeholder' => 'Nach Name oder Betreff suchen…',
    ],
    'request' => [
        'requested_incoming' => 'hat Zugriff angefragt',
        'requested_outgoing' => 'Sie haben Zugriff angefragt',
        'unknown_user' => 'Unbekannte Person',
        'email_unavailable' => 'Die zugehörige E-Mail ist nicht mehr verfügbar.',
    ],
    'empty' => [
        'filtered_heading' => [
            'pending' => 'Keine ausstehenden Anfragen',
            'approved' => 'Keine genehmigten Anfragen',
            'denied' => 'Keine abgelehnten Anfragen',
            'cancelled' => 'Keine abgebrochenen Anfragen',
            'expired' => 'Keine abgelaufenen Anfragen',
        ],
        'filtered_description' => 'Versuchen Sie einen anderen Filter oder entfernen Sie den aktiven.',
        'show_all' => 'Alle anzeigen',
        'incoming_heading' => 'Keine eingehenden Anfragen',
        'outgoing_heading' => 'Keine gesendeten Anfragen',
        'incoming_description' => 'Wenn jemand Zugriff auf eine Ihrer privaten E-Mails anfragt, erscheint die Anfrage hier.',
        'outgoing_description' => 'Sie haben noch keinen Zugriff auf E-Mails angefragt.',
    ],
    'actions' => [
        'open_email' => 'E-Mail öffnen',
        'approve' => [
            'label' => 'Genehmigen',
            'modal_heading' => 'Zugriffsanfrage genehmigen',
            'modal_description' => ':name Zugriff auf diese E-Mail gewähren?',
            'modal_submit_label' => 'Genehmigen',
        ],
        'deny' => [
            'label' => 'Ablehnen',
            'modal_heading' => 'Zugriffsanfrage ablehnen',
            'modal_description' => 'Zugriffsanfrage von :name ablehnen?',
            'modal_submit_label' => 'Ablehnen',
        ],
        'cancel' => [
            'label' => 'Anfrage abbrechen',
            'modal_heading' => 'Zugriffsanfrage abbrechen',
            'modal_description' => 'Ihre Anfrage für den Zugriff auf die E-Mail von :name zurückziehen?',
            'modal_submit_label' => 'Anfrage abbrechen',
        ],
        'fallback_user' => 'dieser Person',
    ],
    'notifications' => [
        'approved' => 'Zugriffsanfrage genehmigt.',
        'denied' => 'Zugriffsanfrage abgelehnt.',
        'cancelled' => 'Zugriffsanfrage abgebrochen.',
        'no_longer_pending' => 'Diese Anfrage ist nicht mehr ausstehend.',
    ],
];
