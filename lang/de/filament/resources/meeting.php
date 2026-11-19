<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Meetings',
    'view' => [
        'heading' => 'Meeting',
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
            'heading' => 'Verknüpfte Einträge',
            'empty' => [
                'heading' => 'Keine verknüpften Einträge',
                'description' => 'Verknüpfen Sie Personen, Unternehmen oder Verkaufschancen mit diesem Meeting.',
            ],
        ],
        'description' => [
            'heading' => 'Beschreibung',
        ],
    ],
    'attendees' => [
        'host' => 'Gastgeber',
        'guest' => 'Gast',
        'show_more' => 'Mehr anzeigen',
        'show_less' => 'Weniger anzeigen',
    ],
    'actions' => [
        'link_records' => [
            'label' => 'Einträge verknüpfen',
        ],
        'unlink_record' => [
            'label' => ':name trennen',
            'heading' => ':name von diesem Meeting trennen?',
            'submit' => 'Trennen',
            'description' => 'Das Meeting bleibt im Kalender. Es erscheint nicht mehr bei diesem Eintrag.',
        ],
        'join' => [
            'label' => 'Beitreten',
        ],
        'rsvp' => [
            'label' => 'Antworten',
            'accepted' => [
                'label' => 'Zusagen',
            ],
            'tentative' => [
                'label' => 'Vielleicht',
            ],
            'declined' => [
                'label' => 'Absagen',
                'heading' => 'Dieses Meeting absagen?',
                'description' => 'Ihr Kalender wird auf abgesagt gesetzt. Für andere Gäste bleibt das Meeting im Kalender.',
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
            'label' => 'Eintrag',
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
            'label' => 'Meine Antwort',
        ],
    ],

    'filters' => [
        'response_status' => [
            'label' => 'Meine Antwort',
        ],
    ],

    'notifications' => [
        'rsvp' => [
            'accepted' => [
                'title' => 'Einladung angenommen. Ihr Kalender wurde aktualisiert.',
            ],
            'tentative' => [
                'title' => 'Als vielleicht markiert. Ihr Kalender wurde aktualisiert.',
            ],
            'declined' => [
                'title' => 'Einladung abgelehnt. Ihr Kalender wurde aktualisiert.',
            ],
            'failed' => [
                'title' => 'Ihre Antwort konnte nicht aktualisiert werden.',
                'body' => 'Verbinden Sie das Postfach erneut und versuchen Sie es noch einmal.',
            ],
            'not_synced' => [
                'title' => 'Ihre Antwort konnte nicht aktualisiert werden.',
                'body' => 'Dieses Meeting erscheint noch nicht in Ihrem Kalender. Versuchen Sie es gleich noch einmal.',
            ],
        ],
    ],

    'empty_state' => [
        'heading' => 'Noch keine Meetings',
        'description' => 'Meetings aus Ihrem synchronisierten Kalender erscheinen hier.',
    ],
];
