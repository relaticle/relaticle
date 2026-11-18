<?php

declare(strict_types=1);

return [
    'title' => 'Datenschutz im Arbeitsbereich',
    'navigation_label' => 'Datenschutz im Arbeitsbereich',
    'tabs' => [
        'aria' => 'E-Mail-Einstellungen des Arbeitsbereichs',
        'visibility' => 'E-Mail-Sichtbarkeit',
        'record_creation' => 'Erstellung von Einträgen',
    ],
    'actions' => [
        'save' => 'Speichern',
    ],
    'visibility' => [
        'heading' => 'E-Mail-Sichtbarkeit',
        'description' => 'Blenden Sie E-Mails und Kalendertermine mit bestimmten Adressen überall in Relaticle aus.',
        'add' => 'Adressen hinzufügen',
        'edit_enforcement' => 'Durchsetzungsstufe ändern',
        'search_placeholder' => 'Adressen oder Domains suchen',
        'empty_heading' => 'Noch keine eigenen Adressen',
        'empty_hint' => 'Die Systemstandards oben gelten immer. Fügen Sie eigene Adressen oder Domains hinzu, wenn Sie mehr abdecken möchten.',
        'emails_label' => 'E-Mail-Adressen',
        'emails_placeholder' => 'z. B. legal@acme.com',
        'emails_after_label' => 'Drücken Sie die Eingabetaste, um jede Adresse hinzuzufügen.',
        'domains_label' => 'Domains',
        'domains_placeholder' => 'z. B. acme.com',
        'domains_after_label' => 'Drücken Sie die Eingabetaste, um jede Domain hinzuzufügen.',
        'include_subdomains_label' => 'Subdomains einschließen',
        'include_subdomains_hint' => 'Blendet beim Hinzufügen von example.com auch E-Mails von Adressen wie user@mail.example.com aus.',
        'include_subdomains_short' => 'Subdomains einschließen',
        'enforcement' => [
            'protected' => [
                'label' => 'Geschützt',
                'description' => 'Nur ausgeblendet, wenn jede Adresse der E-Mail oder des Meetings geschützt oder gesperrt ist.',
            ],
            'blocked' => [
                'label' => 'Gesperrt',
                'description' => 'Immer ausgeblendet, wenn diese Adresse in der E-Mail oder im Meeting vorkommt.',
            ],
        ],
        'notifications' => [
            'added' => 'E-Mail-Sichtbarkeit aktualisiert.',
            'updated' => 'Durchsetzungsstufe aktualisiert.',
            'deleted' => 'Sichtbarkeitseintrag entfernt.',
        ],
        'table' => [
            'address' => 'E-Mail / Domain',
            'subdomains' => 'Subdomains',
            'enforcement' => 'Durchsetzungsstufe',
            'updated' => 'Letzte Änderung',
            'added_by' => 'Hinzugefügt von',
            'actions' => 'Aktionen',
            'members_row' => 'E-Mail-Adressen der Mitglieder des Arbeitsbereichs',
            'system_default' => 'Systemstandard',
            'unknown_user' => 'Unbekannt',
        ],
    ],
    'blocklist' => [
        'heading' => 'Sperrliste des Arbeitsbereichs',
        'description' => 'E-Mails von diesen Adressen und Domains werden in jedem verbundenen Postfach dieses Arbeitsbereichs ausgeblendet.',
        'add' => 'Zur Sperrliste hinzufügen',
        'empty_heading' => 'Noch keine Adressen oder Domains',
        'empty_description' => 'E-Mails von gesperrten Domains und Adressen erscheinen in Relaticle für kein Postfach dieses Arbeitsbereichs.',
        'emails_label' => 'Gesperrte Adressen',
        'emails_placeholder' => 'noisy@example.com',
        'emails_after_label' => 'Drücken Sie die Eingabetaste, um jede Adresse hinzuzufügen.',
        'domains_label' => 'Gesperrte Domains',
        'domains_placeholder' => 'example.com',
        'domains_after_label' => 'Drücken Sie die Eingabetaste, um jede Domain hinzuzufügen.',
        'notifications' => [
            'added' => 'Sperrliste aktualisiert.',
            'deleted' => 'Eintrag der Sperrliste entfernt.',
        ],
        'table' => [
            'address' => 'E-Mail / Domain',
            'type' => 'Typ',
            'added_by' => 'Hinzugefügt von',
            'actions' => 'Aktionen',
            'unknown_user' => 'Unbekannt',
        ],
    ],
    'record_creation' => [
        'heading' => 'Automatische Erstellung von Einträgen',
        'description' => 'Gilt für jedes verbundene Postfach und jeden Kalender im Arbeitsbereich. Adressen aus den E-Mail-Domains Ihrer Mitglieder erstellen nie Einträge. Eine Änderung wirkt sich nur auf neu synchronisierte E-Mails und Meetings aus.',
        'recommended' => 'Empfohlen',
        'modes' => [
            'all' => [
                'label' => 'Alle Personen',
                'description' => 'Einträge werden für alle Personen erstellt, die in den E-Mails und Kalenderterminen Ihrer Mitglieder vorkommen.',
            ],
            'selective' => [
                'label' => 'Gezielte Erstellung von Personen',
                'description' => 'Einträge werden nur für Personen erstellt, die E-Mails von Ihren Mitgliedern erhalten oder in deren Kalenderterminen vorkommen.',
            ],
            'none' => [
                'label' => 'Keine',
                'description' => 'Es werden keine Einträge automatisch erstellt. E-Mails und Kalendertermine werden weiterhin mit manuell erstellten Einträgen verknüpft.',
            ],
        ],
        'companies' => [
            'label' => 'Unternehmenseinträge automatisch erstellen',
            'description' => 'Wenn aktiviert, wird aus der E-Mail-Domain einer Person ein Unternehmen erstellt. Das folgt der Einstellung für Personen oben. Es ist nicht verfügbar, wenn die Erstellung von Einträgen auf „Keine“ steht.',
        ],
    ],
    'tiers' => [
        'private' => [
            'label' => 'Privat',
            'description' => 'Nichts wird geteilt. Nur Sie können diese E-Mails sehen.',
        ],
        'metadata_only' => [
            'label' => 'Nur Teilnehmer',
            'description' => 'Ihr Arbeitsbereich sieht Teilnehmer und Zeitstempel. Betreff und Nachricht bleiben privat.',
        ],
        'subject' => [
            'label' => 'Betreffzeile und Teilnehmer',
            'description' => 'Ihr Arbeitsbereich sieht Betreffzeile, Teilnehmer und Zeitstempel. Die Nachricht bleibt privat.',
        ],
        'full' => [
            'label' => 'Vollzugriff',
            'description' => 'Ihr Arbeitsbereich sieht Inhalt, Betreffzeile und Anhänge.',
        ],
    ],
    'notifications' => [
        'saved' => 'Datenschutzeinstellungen gespeichert.',
    ],
];
