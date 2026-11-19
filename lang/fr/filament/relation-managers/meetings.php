<?php

declare(strict_types=1);

return [
    'title' => 'Réunions',
    'label' => 'réunion',
    'plural_label' => 'réunions',

    'columns' => [
        'title' => [
            'label' => 'Titre',
        ],
        'starts_at' => [
            'label' => 'Heure',
        ],
        'attendees_count' => [
            'label' => 'Participants',
        ],
        'response_status' => [
            'label' => 'Ma réponse',
        ],
    ],

    'actions' => [
        'link_to_record' => [
            'label' => "Lier à l'enregistrement",
        ],
        'unlink_from_record' => [
            'label' => 'Dissocier de cet enregistrement',
        ],
    ],

    'notifications' => [
        'linked' => [
            'title' => 'Réunion liée avec succès.',
        ],
        'unlinked' => [
            'title' => 'Réunion dissociée avec succès.',
        ],
    ],
];
