<?php

declare(strict_types=1);

return [
    'title' => 'Arbeitsbereichs-Privatsphäre',
    'navigation_label' => 'Arbeitsbereichs-Privatsphäre',
    'tabs' => [
        'aria' => 'Arbeitsbereichs-E-Mail-Einstellungen',
        'visibility' => 'E-Mail-Sichtbarkeit',
        'record_creation' => 'Aufzeichnungs-Erstellung',
    ],
    'actions' => [
        'save' => 'Speichern',
    ],
    'visibility' => [
        'heading' => 'E-Mail-Sichtbarkeit',
        'description' => 'Verbergen Sie E-Mails und Kalenderereignisse, die bestimmte Kontakte betreffen, überall in Relaticle.',
        'add' => 'Kontakte hinzufügen',
        'edit_enforcement' => 'Erzwingungsstufe ändern',
        'search_placeholder' => 'Adressen oder Domains suchen',
        'empty_heading' => 'Noch keine benutzerdefinierten Kontakte',
        'empty_hint' => 'Systemstandardwerte gelten immer. Fügen Sie benutzerdefinierte Adressen oder Domains hinzu, wenn Sie eine umfassendere Abdeckung benötigen.',
        'emails_label' => 'E-Mail-Adressen',
        'emails_placeholder' => 'z.B. legal@acme.com',
        'emails_after_label' => 'Drücken Sie die Eingabetaste, um jede Adresse hinzuzufügen.',
        'domains_label' => 'Domains',
        'domains_placeholder' => 'z.B. acme.com',
        'domains_after_label' => 'Drücken Sie die Eingabetaste, um jede Domain hinzuzufügen.',
        'include_subdomains_label' => 'Unterdomänen einschließen',
        'include_subdomains_hint' => 'Verbergen Sie auch E-Mails von Adressen wie user@mail.example.com, wenn Sie example.com blockieren.',
        'include_subdomains_short' => 'Unterdomänen einschließen',
        'enforcement' => [
            'protected' => [
                'label' => 'Geschützt',
                'description' => 'Nur verborgen, wenn jeder Kontakt in der E-Mail oder dem Ereignis geschützt oder blockiert ist.',
            ],
            'blocked' => [
                'label' => 'Blockiert',
                'description' => 'Immer verborgen, wenn dieser Kontakt in der E-Mail oder dem Ereignis ist.',
            ],
        ],
        'notifications' => [
            'added' => 'E-Mail-Sichtbarkeit aktualisiert.',
            'updated' => 'Erzwingungsstufe aktualisiert.',
            'deleted' => 'Sichtbarkeitseintrag entfernt.',
        ],
        'table' => [
            'address' => 'E-Mail / Domain',
            'subdomains' => 'Unterdomänen',
            'enforcement' => 'Erzwingungsstufe',
            'updated' => 'Letzte Aktualisierung',
            'added_by' => 'Hinzugefügt von',
            'actions' => 'Aktionen',
            'members_row' => "E-Mail-Adressen der Arbeitsbereichsmitglieder",
            'system_default' => 'Systemstandard',
            'unknown_user' => 'Unbekannt',
        ],
    ],
    'blocklist' => [
        'heading' => 'Arbeitsbereichs-Blockliste',
        'description' => 'E-Mails von diesen Adressen und Domains sind für alle verbundenen Posteingänge in diesem Arbeitsbereich verborgen.',
        'add' => 'Zur Blockliste hinzufügen',
        'empty_heading' => 'Noch keine Adressen oder Domains',
        'empty_description' => 'E-Mails von blockierten Domains und Adressen werden in Relaticle für keinen Posteingang in diesem Arbeitsbereich angezeigt.',
        'emails_label' => 'Blockierte Adressen',
        'emails_placeholder' => 'noisy@example.com',
        'domains_label' => 'Blockierte Domains',
        'domains_placeholder' => 'example.com',
        'domains_after_label' => 'Drücken Sie die Eingabetaste, um jede Domain hinzuzufügen.',
        'notifications' => [
            'added' => 'Blockliste aktualisiert.',
            'deleted' => 'Blocklisteneintrag entfernt.',
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
        'heading' => 'Automatische Aufzeichnungs-Erstellung',
        'description' => 'Gilt für alle verbundenen Posteingänge und Kalender im Arbeitsbereich. Adressen von den E-Mail-Domains Ihrer Arbeitsbereichsmitglieder erstellen keine Aufzeichnungen. Die Änderung dieser Einstellung betrifft nur neu synchronisierte E-Mails und Ereignisse.',
        'recommended' => 'Empfohlen',
        'modes' => [
            'all' => [
                'label' => 'Alle Kontakte',
                'description' => 'Aufzeichnungen werden für alle Kontakte erstellt, die in den E-Mails und Kalenderereignissen Ihrer Arbeitsbereichsmitglieder erscheinen.',
            ],
            'selective' => [
                'label' => 'Selektive Kontakt-Erstellung',
                'description' => 'Aufzeichnungen werden nur für Kontakte erstellt, die E-Mails von Ihren Arbeitsbereichsmitgliedern erhalten oder in deren Kalenderereignissen erscheinen.',
            ],
            'none' => [
                'label' => 'Keine',
                'description' => 'Es werden keine Aufzeichnungen automatisch erstellt. E-Mails und Kalenderereignisse werden weiterhin mit manuell erstellten Aufzeichnungen verknüpft.',
            ],
        ],
        'companies' => [
            'label' => 'Unternehmen automatisch erstellen',
            'description' => 'Wenn aktiviert, wird ein Unternehmen aus der E-Mail-Domain einer Person erstellt. Dies folgt der oben genannten Kontakt-Einstellung. Es ist nicht verfügbar, wenn die Aufzeichnungserstellung auf „Keine“ steht.',
        ],
    ],
    'tiers' => [
        'private' => [
            'label' => 'Privat',
            'description' => 'Nichts wird geteilt. Nur Sie können diese E-Mails sehen.',
        ],
        'metadata_only' => [
            'label' => 'Nur Teilnehmer',
            'description' => 'Ihr Arbeitsbereich sieht die Teilnehmer und den Zeitstempel. Betreff und Nachricht bleiben privat.',
        ],
        'subject' => [
            'label' => 'Betreffzeile und Teilnehmer',
            'description' => 'Ihr Arbeitsbereich sieht die Betreffzeile, die Teilnehmer und den Zeitstempel. Die Nachricht bleibt privat.',
        ],
        'full' => [
            'label' => 'Vollzugriff',
            'description' => 'Ihr Arbeitsbereich sieht den Inhalt, die Betreffzeile und die Anhänge.',
        ],
    ],
    'notifications' => [
        'saved' => 'Privatsicherheitseinstellungen gespeichert.',
    ],
];
