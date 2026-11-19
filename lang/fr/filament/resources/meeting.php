<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Réunions',
    'view' => [
        'heading' => 'Réunion',
    ],
    'time' => [
        'all_day' => 'Toute la journée',
    ],
    'sections' => [
        'participants' => [
            'heading' => 'Participants',
            'empty' => 'Aucun participant',
        ],
        'linked_records' => [
            'heading' => 'Enregistrements liés',
            'empty' => [
                'heading' => 'Aucun enregistrement lié',
                'description' => 'Liez des personnes, des entreprises ou des opportunités à cette réunion.',
            ],
        ],
        'description' => [
            'heading' => 'Description',
        ],
    ],
    'attendees' => [
        'host' => 'Organisateur',
        'guest' => 'Invité',
        'show_more' => 'Voir plus',
        'show_less' => 'Voir moins',
    ],
    'actions' => [
        'link_records' => [
            'label' => 'Lier des enregistrements',
        ],
        'unlink_record' => [
            'label' => 'Dissocier :name',
            'heading' => 'Dissocier :name de cette réunion ?',
            'submit' => 'Dissocier',
            'description' => "La réunion reste dans l'agenda. Elle n'apparaît plus sur cet enregistrement.",
        ],
        'join' => [
            'label' => 'Rejoindre',
        ],
        'rsvp' => [
            'label' => 'Répondre',
            'accepted' => [
                'label' => 'Accepter',
            ],
            'tentative' => [
                'label' => 'Peut-être',
            ],
            'declined' => [
                'label' => 'Refuser',
                'heading' => 'Refuser cette réunion ?',
                'description' => "Votre agenda est mis à jour avec la réponse « refusée ». La réunion reste dans l'agenda des autres invités.",
            ],
        ],
    ],
    'linked_record_types' => [
        'people' => 'Personne',
        'companies' => 'Entreprise',
        'opportunities' => 'Opportunité',
    ],
    'fields' => [
        'record_type' => [
            'label' => 'Type',
        ],
        'record' => [
            'label' => 'Enregistrement',
        ],
        'organizer' => [
            'label' => 'Organisateur',
        ],
        'email_address' => [
            'label' => 'E-mail',
        ],
        'html_link' => [
            'label' => "Ouvrir dans l'agenda",
        ],
    ],

    'columns' => [
        'starts_at' => [
            'label' => 'Heure',
        ],
        'organizer_name' => [
            'label' => 'Organisateur',
        ],
        'attendees_count' => [
            'label' => 'Participants',
        ],
        'people_count' => [
            'label' => 'Personnes',
        ],
        'companies_count' => [
            'label' => 'Entreprises',
        ],
        'opportunities_count' => [
            'label' => 'Opportunités',
        ],
        'response_status' => [
            'label' => 'Ma réponse',
        ],
    ],

    'filters' => [
        'response_status' => [
            'label' => 'Ma réponse',
        ],
    ],

    'notifications' => [
        'rsvp' => [
            'accepted' => [
                'title' => 'Invitation acceptée. Votre agenda est mis à jour.',
            ],
            'tentative' => [
                'title' => 'Marquée comme « peut-être ». Votre agenda est mis à jour.',
            ],
            'declined' => [
                'title' => 'Invitation refusée. Votre agenda est mis à jour.',
            ],
            'failed' => [
                'title' => 'Impossible de mettre à jour votre réponse.',
                'body' => 'Reconnectez la boîte mail et réessayez.',
            ],
            'not_synced' => [
                'title' => 'Impossible de mettre à jour votre réponse.',
                'body' => "Cette réunion n'apparaît pas encore dans votre agenda. Réessayez dans un instant.",
            ],
        ],
    ],

    'empty_state' => [
        'heading' => 'Aucune réunion pour le moment',
        'description' => 'Les réunions de votre agenda synchronisé apparaissent ici.',
    ],
];
