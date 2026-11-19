<?php

declare(strict_types=1);

return [
    'activation' => [
        'heading' => 'Erste Schritte',
        'progress' => ':completed/:total Schritte abgeschlossen',
        'dismiss' => 'Ausblenden',
        'collapse' => 'Checkliste einklappen',
        'more_actions' => 'Weitere Aktionen',
        'encouragement' => "Los geht's!",
        'invite_members' => 'Mitglieder einladen',
        'sample_data' => 'Dieser Arbeitsbereich enthält Beispieleinträge, damit Sie sich umsehen können. Alles, was Sie hinzufügen, steht daneben.',
        'remove_sample_data' => 'Beispieldaten entfernen',
        'remove_sample_data_confirm' => 'Alle Beispieleinträge löschen? Ihre eigenen Einträge bleiben erhalten.',
        'steps' => [
            'first_record' => [
                'label' => 'Erste Person hinzufügen',
                'description' => 'Legen Sie eine echte Person im CRM an, der Rest ergibt sich',
            ],
            'sync_email' => [
                'label' => 'Postfach synchronisieren',
                'description' => 'Verbinden Sie Ihr Postfach, um Unterhaltungen und Meetings ins CRM zu holen',
                'syncing' => 'E-Mails werden synchronisiert…',
                'syncing_percent' => 'E-Mails werden synchronisiert (:percent%)',
            ],
            'import' => [
                'label' => 'Bestehende Einträge importieren',
                'description' => 'Bringen Sie eine CSV-Datei aus Ihrer Tabelle oder Ihrem alten CRM mit',
            ],
            'invite' => [
                'label' => 'Mitglied einladen',
                'description' => 'Gemeinsame Pipelines schlagen private',
            ],
            'ask_rela' => [
                'label' => 'Rela nach Ihrer Pipeline fragen',
                'label_empty' => 'Rela fragen, wie Sie anfangen',
                'description' => 'Ihr Assistent kann Einträge für Sie lesen, entwerfen und aktualisieren',
                'prompt' => 'Was steckt gerade in meiner Pipeline?',
                'prompt_empty' => 'Wie können Sie mir helfen, diesen Arbeitsbereich einzurichten?',
            ],
        ],
    ],
    'meetings' => [
        'heading' => 'Meetings',
        'empty' => [
            'title' => 'Keine Meetings',
            'description' => 'Wählen Sie ein anderes Datum, um vorauszuplanen oder frühere Meetings anzusehen.',
            'next_with_meetings' => 'Nächster Tag mit Meetings',
        ],
        'disconnected' => [
            'title' => 'Aus Meetings Verkaufschancen machen',
            'description' => 'Synchronisieren Sie Ihren Kalender für sofortigen Meeting-Kontext',
        ],
        'date' => [
            'today' => 'Heute,',
            'tomorrow' => 'Morgen,',
            'yesterday' => 'Gestern,',
            'other' => ':weekday,',
        ],
        'previous_day' => 'Vorheriger Tag',
        'next_day' => 'Nächster Tag',
        'pick_date' => 'Datum ändern, aktuell :date',
        'more_actions' => 'Weitere Meeting-Aktionen',
        'go_to_today' => 'Zu heute springen',
        'calendar_settings' => 'Kalendereinstellungen',
        'open' => 'Meeting öffnen',
        'open_named' => ':title öffnen',
        'expand_participants' => 'Teilnehmer von :title anzeigen',
        'collapse_participants' => 'Teilnehmer von :title ausblenden',
        'all_day' => 'Ganztägig',
        'more_participants' => '+:count',
        'happening_now' => 'Jetzt',
        'time_range' => ':start bis :end',
        'load_more' => 'Mehr laden',
        'syncing' => [
            'title' => 'Synchronisierung läuft',
            'title_with_percent' => 'Synchronisierung läuft (:percent%)',
            'description_initial' => 'Wir verarbeiten Ihre E-Mails und Kalendereinträge…',
            'description_update' => 'Es wird nach neuen E-Mails und Kalenderänderungen gesucht…',
            'emails_processed' => '{1}:count E-Mail synchronisiert|[2,*]:count E-Mails synchronisiert',
            'meetings_processed' => '{1}:count Meeting synchronisiert|[2,*]:count Meetings synchronisiert',
            'emails_updated' => '{1}:count neue E-Mail|[2,*]:count neue E-Mails',
            'meetings_updated' => '{1}:count Meeting aktualisiert|[2,*]:count Meetings aktualisiert',
        ],
    ],
    'tasks' => [
        'heading' => 'Aufgaben',
        'view_all' => 'Alle anzeigen',
        'create_action_label' => 'Neue Aufgabe',
        'complete' => 'Als erledigt markieren',
        'empty' => [
            'title' => 'Behalten Sie den Überblick',
            'description' => 'Erstellen Sie Aufgaben für sich oder Ihr Team, um nächste Schritte zu verfolgen',
        ],
    ],
];
