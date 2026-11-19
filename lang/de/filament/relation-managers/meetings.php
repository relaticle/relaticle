<?php

declare(strict_types=1);

return [
    'title' => 'Meetings',
    'label' => 'Meeting',
    'plural_label' => 'Meetings',

    'columns' => [
        'title' => [
            'label' => 'Titel',
        ],
        'starts_at' => [
            'label' => 'Zeit',
        ],
        'attendees_count' => [
            'label' => 'Teilnehmer',
        ],
        'response_status' => [
            'label' => 'Meine Zusage',
        ],
    ],

    'actions' => [
        'link_to_record' => [
            'label' => 'Mit Eintrag verknüpfen',
        ],
        'unlink_from_record' => [
            'label' => 'Von diesem Eintrag lösen',
        ],
    ],

    'notifications' => [
        'linked' => [
            'title' => 'Meeting erfolgreich verknüpft.',
        ],
        'unlinked' => [
            'title' => 'Meeting erfolgreich gelöst.',
        ],
    ],
];
