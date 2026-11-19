<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Zugriffsanfragen',
    'tabs' => [
        'aria' => 'Zugriffsanfrage-Tabs',
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
        'requested_by' => 'Angefordert von',
        'sent_to' => 'Gesendet an',
        'email' => 'E-Mail',
        'access' => 'Zugriff',
        'requested' => 'Angefordert',
    ],
    'search' => [
        'placeholder' => 'Nach Name oder Betreff suchen…',
    ],
    'request' => [
        'requested_incoming' => 'geforderter Zugriff',
        'requested_outgoing' => 'Sie haben Zugriff angefordert',
        'unknown_user' => 'Unbekannter Benutzer',
        'email_unavailable' => 'Die verknüpfte E-Mail ist nicht mehr verfügbar.',
    ],
    'empty' => [
        'filtered_heading' => 'Keine :status-Anfragen',
        'filtered_description' => 'Versuchen Sie einen anderen Filter oder löschen Sie den aktiven.',
        'show_all' => 'Alle anzeigen',
        'incoming_heading' => 'Keine eingehenden Anfragen',
        'outgoing_heading' => 'Keine gesendeten Anfragen',
        'incoming_description' => 'Wenn jemand um Zugriff auf eine Ihrer privaten E-Mails bittet, erscheint sie hier.',
        'outgoing_description' => 'Sie haben noch keine Zugriffsanfragen gesendet.',
    ],
    'actions' => [
        'open_email' => 'E-Mail öffnen',
        'approve' => [
            'label' => 'Genehmigen',
            'modal_heading' => 'Zugriffsanfrage genehmigen',
            'modal_description' => 'Gewähren Sie :name Zugriff auf diese E-Mail?',
            'modal_submit_label' => 'Genehmigen',
        ],
        'deny' => [
            'label' => 'Ablehnen',
            'modal_heading' => 'Zugriffsanfrage ablehnen',
            'modal_description' => 'Lehnen Sie die Zugriffsanfrage von :name ab?',
            'modal_submit_label' => 'Ablehnen',
        ],
        'cancel' => [
            'label' => 'Anfrage abbrechen',
            'modal_heading' => 'Zugriffsanfrage abbrechen',
            'modal_description' => 'Ziehen Sie Ihre Anfrage für Zugriff auf die E-Mail von :name zurück?',
            'modal_submit_label' => 'Anfrage abbrechen',
        ],
        'fallback_user' => 'dieser Benutzer',
    ],
    'notifications' => [
        'approved' => 'Zugriffsanfrage genehmigt.',
        'denied' => 'Zugriffsanfrage abgelehnt.',
        'cancelled' => 'Zugriffsanfrage abgebrochen.',
        'no_longer_pending' => 'Diese Anfrage ist nicht mehr ausstehend.',
    ],
];
