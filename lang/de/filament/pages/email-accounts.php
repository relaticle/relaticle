<?php

declare(strict_types=1);

return [
    'title' => 'E-Mail- und Kalender-Postfächer',
    'navigation_label' => 'Postfächer',
    'subheading' => 'Verwalten und synchronisieren Sie Ihre E-Mail- und Kalender-Postfächer, um den Überblick zu behalten.',
    'actions' => [
        'connect_gmail' => 'Mit Google verbinden',
        'connect_azure' => 'Mit Microsoft verbinden',
        'manage' => 'Verwalten',
        'reconnect' => 'Neu verbinden',
        'set_default' => 'Als Standard festlegen',
        'retry_sync' => 'Synchronisierung wiederholen',
        'disconnect' => 'Postfach trennen',
        'sync_calendar' => [
            'enable_label' => 'Kalender synchronisieren',
            'disable_label' => 'Kalendersynchronisierung deaktivieren',
            'enable_heading' => 'Kalendersynchronisierung aktivieren',
            'disable_heading' => 'Kalendersynchronisierung deaktivieren',
            'disable_description' => 'Dadurch werden Kalendertermine für dieses Postfach nicht mehr synchronisiert.',
            'enable_description' => 'Sie werden zu :provider weitergeleitet, um den Kalenderzugriff zu erteilen.',
            'fallback_provider' => 'dem Anbieter',
        ],
        'sync_calendar_now' => 'Jetzt synchronisieren',
        'reimport_history' => [
            'label' => 'Verlauf erneut importieren',
            'heading' => 'Postfachverlauf erneut importieren?',
            'description' => 'Bereits synchronisierte E-Mails und Meetings bleiben in Relaticle. Wir legen fehlende Personen und Unternehmen gemäß der Einstellung Ihres Arbeitsbereichs zur Erstellung von Einträgen an und importieren alle noch nicht gespeicherten Nachrichten. Bei einem großen Postfach kann das dauern.',
        ],
        'retry_failed_import' => [
            'label' => 'Erneut versuchen',
        ],
    ],
    'settings' => [
        'sync_inbox' => [
            'label' => 'Posteingang synchronisieren',
            'helper_text' => 'Empfangene E-Mails in Relaticle übernehmen.',
        ],
        'sync_sent' => [
            'label' => 'Gesendete synchronisieren',
            'helper_text' => 'E-Mails, die Sie aus diesem Postfach senden, in Relaticle übernehmen.',
        ],
        'hourly_send_limit' => [
            'label' => 'Sendelimit pro Stunde',
            'placeholder' => 'Standard: :default',
            'helper_text' => 'Leer lassen, um den Standard des Arbeitsbereichs zu verwenden.',
        ],
        'daily_send_limit' => [
            'label' => 'Sendelimit pro Tag',
            'placeholder' => 'Standard: :default',
            'helper_text' => 'Leer lassen, um den Standard des Arbeitsbereichs zu verwenden.',
        ],
        'modal_heading' => 'Postfacheinstellungen',
        'submit_label' => 'Speichern',
    ],
    'notifications' => [
        'connected' => [
            'title' => 'Postfach verbunden.',
            'body' => 'E-Mails und Meetings erscheinen, während der Import läuft.',
        ],
        'cancelled' => [
            'title' => 'Es wurde nichts verbunden. Sie können jederzeit ein Postfach verbinden.',
        ],
        'limit_reached' => [
            'title' => '{1}Postfachlimit erreicht. Dieser Arbeitsbereich kann 1 Postfach verbinden. Upgraden Sie, um mehr zu verbinden.|[2,*]Postfachlimit erreicht. Dieser Arbeitsbereich kann :count Postfächer verbinden. Upgraden Sie, um mehr zu verbinden.',
            'trial' => 'Postfachlimit der kostenlosen Testphase erreicht. Upgraden Sie jetzt, um mehr Postfächer zu synchronisieren.',
            'upgrade' => 'Upgraden',
        ],
        'calendar_sync_queued' => [
            'title' => 'Kalendersynchronisierung gestartet.',
            'body' => 'Ihre Meetings werden auf dieser Seite aktualisiert, sobald die Synchronisierung abgeschlossen ist.',
        ],
        'disconnected' => [
            'title' => 'Postfach getrennt.',
            'body' => 'Das Postfach und seine Signaturen wurden entfernt.',
        ],
        'default_set' => [
            'title' => 'Standardpostfach aktualisiert.',
            'body' => ':email ist jetzt Ihr Standardpostfach zum Senden.',
        ],
        'reimport_queued' => [
            'title' => 'Verlaufsimport eingeplant.',
            'body' => 'Personen und Unternehmen erscheinen, während der Import läuft. Sie können Relaticle weiter nutzen.',
        ],
        'sync_retry_queued' => [
            'title' => 'Neuer Synchronisierungsversuch eingeplant.',
            'body' => 'Wir rufen ab, was nicht gespeichert werden konnte. Diese Seite wird aktualisiert, sobald der Vorgang abgeschlossen ist.',
        ],
        'retry_failed_import_queued' => [
            'title' => 'Neuer Versuch eingeplant.',
            'body' => 'Wir versuchen erneut, Nachrichten zu importieren, die nicht importiert werden konnten. Diese Seite wird aktualisiert, sobald der Vorgang abgeschlossen ist.',
        ],
        'retry_failed_import_unavailable' => [
            'title' => 'Neuer Versuch nicht möglich',
            'body' => 'Für diesen Import gibt es keine fehlgeschlagenen Importe, die erneut versucht werden könnten.',
        ],
    ],
    'default_badge' => 'Standard',
    'synced_at' => 'Synchronisiert :time',
    'in_sync' => 'Synchron',
    'sync_error' => [
        'badge' => 'Sync-Problem',
        'heading' => 'Einige Elemente konnten nicht synchronisiert werden',
        'reasons' => [
            'google_mail_disabled' => 'Gmail ist für dieses Google-Workspace-Konto nicht aktiviert. Bitten Sie Ihren Google-Administrator, es zu aktivieren, und verbinden Sie das Postfach dann neu.',
        ],
    ],
    'importing' => 'Synchronisierung läuft',
    'importing_percent' => ':percent%',
    'importing_count' => '{1}:count E-Mail|[2,*]:count E-Mails',
    'statuses' => [
        'active' => 'Aktiv',
        'error' => 'Sync-Problem',
        'disconnected' => 'Getrennt',
        'reauth_required' => 'Neu verbinden erforderlich',
    ],
    'history_import' => [
        'processed' => ':processed von :total verarbeitet',
        'successful_jobs' => ':count erfolgreich importiert',
    ],
    'history_import_failure' => [
        'max_attempts' => 'Diese Nachricht konnte nach mehreren Versuchen nicht gespeichert werden. Nutzen Sie oben „Erneut versuchen“. Schlägt es weiter fehl, warten Sie einige Minuten und versuchen Sie es erneut.',
    ],
    'capabilities' => [
        'email' => 'E-Mail',
        'calendar' => 'Kalender',
    ],
    'not_connected' => [
        'inbox' => [
            'heading' => 'E-Mails in Relaticle senden',
            'description' => 'Verbinden Sie Ihr Postfach, um E-Mails zu lesen und zu beantworten, ohne Relaticle zu verlassen. Dazu kommen Massenversand, Vorlagen und Anhänge.',
        ],
        'record' => [
            'heading' => 'E-Mails beim Eintrag behalten',
            'description' => 'Verbinden Sie Ihr Postfach, um jede Unterhaltung mit diesem Eintrag zu sehen und mit einem Klick zu antworten.',
        ],
        'meetings' => [
            'heading' => 'Ihre Meetings in Relaticle sehen',
            'description' => 'Verbinden Sie Ihr Postfach, um Meetings neben Ihren CRM-Einträgen zu verfolgen.',
        ],
    ],
];
