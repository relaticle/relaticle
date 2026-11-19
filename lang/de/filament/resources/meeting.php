<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Termine',
    'view' => [
        'heading' => 'Termin',
    ],
    'time' => [
        'all_day' => 'Ganztägig',
    ],
    'sections' => [
        'participants' => [
            'heading' => 'Teilnehmer',
            'empty' => 'Keine Teilnehmer',
        ],
        'linked_records' => [
            'heading' => 'Verknüpfte Datensätze',
            'empty' => [
                'heading' => 'Keine verknüpften Datensätze',
                'description' => 'Verbinden Sie Personen, Unternehmen oder Verkaufschancen mit diesem Termin.',
            ],
        ],
        'description' => [
            'heading' => 'Beschreibung',
        ],
    ],
    'attendees' => [
        'host' => 'Wirt',
        'guest' => 'Gast',
        'show_more' => 'Mehr anzeigen',
        'show_less' => 'Weniger anzeigen',
    ],
    'actions' => [
        'link_records' => [
            'label' => 'Aufzeichnungen verknüpfen',
        ],
        'unlink_record' => [
            'label' => ':name entknüpfen',
            'heading' => ':name von diesem Termin entknüpfen?',
            'submit' => 'Entknüpfen',
            'description' => 'Der Termin bleibt im Kalender. Er zeigt nicht mehr auf dieser Aufzeichnung an.',
        ],
        'join' => [
            'label' => 'Beitreten',
        ],
        'rsvp' => [
            'label' => 'RSVP',
            'accepted' => [
                'label' => 'Akzeptiert',
            ],
            'tentative' => [
                'label' => 'Vielleicht',
            ],
            'declined' => [
                'label' => 'Ablehnen',
                'heading' => 'Diesen Termin ablehnen?',
                'description' => 'Ihr Kalender wird auf abgelehnt aktualisiert. Der Termin bleibt im Kalender für andere Gäste.',
            ],
        ],
    ],
    'linked_record_types' => [
        'people' => 'Person',
        'companies' => 'Unternehmen',
        'opportunities' => 'Verkaufschance',
    ],
    'fields' => [
        'record_type' => [
            'label' => 'Typ',
        ],
        'record' => [
            'label' => 'Datensatz',
        ],
        'organizer' => [
            'label' => 'Organisator',
        ],
        'email_address' => [
            'label' => 'E-Mail',
        ],
        'html_link' => [
            'label' => 'Im Kalender öffnen',
        ],
    ],

    'columns' => [
        'starts_at' => [
            'label' => 'Zeit',
        ],
        'organizer_name' => [
            'label' => 'Organisator',
        ],
        'attendees_count' => [
            'label' => 'Teilnehmer',
        ],
        'people_count' => [
            'label' => 'Personen',
        ],
        'companies_count' => [
            'label' => 'Unternehmen',
        ],
        'opportunities_count' => [
            'label' => 'Verkaufschancen',
        ],
        'response_status' => [
            'label' => 'Meine RSVP',
        ],
    ],

    'filters' => [
        'response_status' => [
            'label' => 'Meine RSVP',
        ],
    ],

    'notifications' => [
        'rsvp' => [
            'accepted' => [
                'title' => 'Einladung akzeptiert. Ihr Kalender wird aktualisiert.',
            ],
            'tentative' => [
                'title' => 'Als vielleicht markiert. Ihr Kalender wird aktualisiert.',
            ],
            'declined' => [
                'title' => 'Einladung abgelehnt. Ihr Kalender wird aktualisiert.',
            ],
            'failed' => [
                'title' => 'Ihre RSVP konnte nicht aktualisiert werden.',
                'body' => 'Verbinden Sie den Posteingang erneut und versuchen Sie es erneut.',
            ],
            'not_synced' => [
                'title' => 'Ihre RSVP konnte nicht aktualisiert werden.',
                'body' => 'Dieser Termin ist noch nicht auf Ihrem Kalender erschienen. Versuchen Sie es in einem Moment.',
            ],
        ],
    ],

    'empty_state' => [
        'heading' => 'Noch keine Termine',
        'description' => 'Termine von Ihrem synchronisierten Kalender erscheinen hier.',
    ],
];
