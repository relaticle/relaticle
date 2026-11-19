<?php

declare(strict_types=1);

return [
    'title' => 'Import abgeschlossen',
    'title_with_issues' => 'Import mit Problemen beendet',
    'body' => 'Import abgeschlossen: :imported.',
    'body_with_issues' => 'Import mit Problemen beendet: :imported. :failures',
    'imported_emails' => '{0}:count E-Mails|{1}:count E-Mail|[2,*]:count E-Mails',
    'imported_calendar_events' => '{0}:count Kalendertermine|{1}:count Kalendertermin|[2,*]:count Kalendertermine',
    'imported_with_calendar' => ':emails und :events',
    'imported_without_calendar' => ':emails',
    'failed_messages' => '{1}:count Nachricht konnte nicht importiert werden|[2,*]:count Nachrichten konnten nicht importiert werden',
    'failed_calendar_events' => '{1}:count Kalendertermin konnte nicht importiert werden|[2,*]:count Kalendertermine konnten nicht importiert werden',
    'calendar_did_not_finish' => 'Der Kalenderimport wurde nicht abgeschlossen.',
    'email_history_did_not_finish' => 'Ältere E-Mails konnten nicht importiert werden.',
    'retry_success' => [
        'title' => 'Erneuter Import abgeschlossen',
        'body' => 'Zuvor fehlende Nachrichten von :email wurden importiert. :imported befinden sich jetzt in Relaticle.',
    ],
    'actions' => [
        'retry' => 'Erneut versuchen',
        'open_inbox' => 'Posteingang öffnen',
    ],
    'retry' => [
        'queued' => [
            'title' => 'Neuer Versuch eingeplant.',
            'body' => 'Wir versuchen erneut, was nicht importiert werden konnte. Sie erhalten eine Benachrichtigung, sobald der Vorgang beendet ist.',
        ],
        'unavailable' => [
            'title' => 'Neuer Versuch nicht möglich',
            'body' => 'Für diesen Import gibt es keine fehlgeschlagenen Importe, die erneut versucht werden könnten.',
        ],
    ],
    'mail' => [
        'subject' => 'Der Import Ihres Postfachs ist abgeschlossen',
        'subject_with_issues' => 'Der Import Ihres Postfachs wurde mit Problemen beendet',
        'retry_subject' => 'Der erneute Import Ihres Postfachs war erfolgreich',
        'greeting' => 'Hallo :name,',
        'line' => 'Import abgeschlossen: :imported aus :email importiert. Neue E-Mails werden weiterhin automatisch synchronisiert.',
        'line_with_issues' => 'Import mit Problemen beendet: :imported aus :email importiert. :failures',
        'retry_line' => 'Wir haben die Nachrichten importiert, die von :email fehlten. :imported befinden sich jetzt in Relaticle.',
    ],
];
