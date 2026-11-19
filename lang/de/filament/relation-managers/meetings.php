<?php

declare(strict_types=1);

return [
    'columns' => [
        'starts_at' => [
            'label' => 'Zeit',
        ],
        'attendees_count' => [
            'label' => 'Teilnehmer',
        ],
        'response_status' => [
            'label' => 'Meine Antwort',
        ],
    ],

    'actions' => [
        'link_to_record' => [
            'label' => 'Mit Aufzeichnung verknüpfen',
        ],
        'unlink_from_record' => [
            'label' => 'Von dieser Aufzeichnung lösen',
        ],
    ],

    'notifications' => [
        'linked' => [
            'title' => 'Termin erfolgreich verknüpft.',
        ],
        'unlinked' => [
            'title' => 'Termin erfolgreich gelöst.',
        ],
    ],
];
