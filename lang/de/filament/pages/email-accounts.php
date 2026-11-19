<?php

declare(strict_types=1);

return [
    'title' => 'E-Mail- und Kalenderkonten',
    'navigation_label' => 'Konten',
    'subheading' => 'Verwalten und synchronisieren Sie Ihre E-Mail- und Kalenderkonten, um organisiert zu bleiben.',
    'actions' => [
        'connect_gmail' => 'Google-Konto verbinden',
        'connect_azure' => 'Microsoft-Konto verbinden',
        'manage' => 'Verwalten',
        'reconnect' => 'Erneut verbinden',
        'set_default' => 'Als Standard festlegen',
        'retry_sync' => 'Synchronisierung erneut versuchen',
        'disconnect' => 'Konto trennen',
        'sync_calendar' => [
            'enable_label' => 'Kalendersynchronisierung aktivieren',
            'disable_label' => 'Kalendersynchronisierung deaktivieren',
            'enable_heading' => 'Kalendersynchronisierung aktivieren',
            'disable_heading' => 'Kalendersynchronisierung deaktivieren',
            'disable_description' => 'Dies stoppt die Synchronisierung von Kalenderereignissen für dieses Konto.',
            'enable_description' => 'Sie werden zu :provider weitergeleitet, um den Kalenderzugriff zu gewähren.',
            'fallback_provider' => 'den Anbieter',
        ],
        'sync_calendar_now' => 'Jetzt synchronisieren',
        'reimport_history' => [
            'label' => 'Verlauf neu importieren',
            'heading' => 'Kontoverlauf neu importieren?',
            'description' => 'Bereits synchronisierte E-Mails und Termine bleiben in Relaticle. Wir erstellen fehlende Personen und Unternehmen unter Verwendung der aktuellen Workspace-Aufzeichnungserstellung und importieren alle noch nicht gespeicherten Nachrichten. Dies kann bei einem großen Posteingang eine Weile dauern.',
        ],
        'retry_failed_import' => [
            'label' => 'Erneut versuchen',
        ],
    ],
    'settings' => [
        'sync_inbox' => [
            'label' => 'Posteingang synchronisieren',
            'helper_text' => 'Bringen Sie E-Mails, die Sie empfangen, in Relaticle.',
        ],
        'sync_sent' => [
            'label' => 'Gesendete synchronisieren',
            'helper_text' => 'Bringen Sie E-Mails, die Sie von diesem Konto senden, in Relaticle.',
        ],
        'hourly_send_limit' => [
            'label' => 'Stündiges SendeLimit',
            'placeholder' => 'Standard: :default',
            'helper_text' => 'Lassen Sie es leer, um das Standardlimit des Arbeitsbereichs zu verwenden.',
        ],
        'daily_send_limit' => [
            'label' => 'Tägliches SendeLimit',
            'placeholder' => 'Standard: :default',
            'helper_text' => 'Lassen Sie es leer, um das Standardlimit des Arbeitsbereichs zu verwenden.',
        ],
        'modal_heading' => 'Kontoeinstellungen',
        'submit_label' => 'Speichern',
    ],
    'notifications' => [
        'connected' => [
            'title' => 'Konto verbunden.',
            'body' => 'E-Mails und Termine werden angezeigt, während der Import läuft.',
        ],
        'cancelled' => [
            'title' => 'Nichts wurde verbunden. Sie können jederzeit eine Mailbox verbinden.',
        ],
        'limit_reached' => [
            'title' => '{1}Posteingangsgrenze erreicht. Dieser Arbeitsbereich kann 1 Mailbox verbinden. Upgrade, um mehr zu verbinden.',
            'trial' => 'Kostenloses Testkonto-Limit erreicht. Upgrade jetzt, um mehr Konten zu synchronisieren.',
            'upgrade' => 'Upgrade',
        ],
        'calendar_sync_queued' => [
            'title' => 'Kalendersynchronisierung gestartet.',
            'body' => 'Ihre Termine werden auf dieser Seite aktualisiert, während die Synchronisierung läuft.',
        ],
        'disconnected' => [
            'title' => 'Konto getrennt.',
            'body' => 'Das Konto und seine Signaturen wurden entfernt.',
        ],
        'default_set' => [
            'title' => 'Standardkonto aktualisiert.',
            'body' => ':email ist nun Ihr Standard-Versandkonto.',
        ],
        'reimport_queued' => [
            'title' => 'Verlaufsimport in die Warteschlange gestellt.',
            'body' => 'Personen und Unternehmen werden angezeigt, während der Import läuft. Sie können Relaticle weiterhin nutzen.',
        ],
        'retry_failed_import_queued' => [
            'title' => 'Wiederholung in die Warteschlange gestellt.',
            'body' => 'Wir versuchen, Nachrichten zu holen, die nicht gespeichert werden konnten. Diese Seite wird aktualisiert, sobald sie fertig sind.',
        ],
        'retry_failed_import_unavailable' => [
            'title' => 'Wiederholung nicht verfügbar',
            'body' => 'Es gibt keine fehlgeschlagenen Importe, die wiederholt werden können.',
        ],
    ],
    'default_badge' => 'Standard',
<EOF>