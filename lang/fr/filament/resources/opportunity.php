<?php

declare(strict_types=1);

return [
    'label' => 'opportunité',
    'plural_label' => 'opportunités',
    'navigation_label' => 'Opportunités',

    'fields' => [
        'name' => [
            'label' => 'Opportunité',
            'placeholder' => "Saisissez le titre de l'opportunité",
        ],
        'company_id' => [
            'label' => 'Entreprise',
        ],
        'contact_id' => [
            'label' => 'Interlocuteur',
        ],
        'creator' => [
            'label' => 'Créé par',
        ],
        'created_at' => [
            'label' => 'Créé le',
        ],
        'updated_at' => [
            'label' => 'Mis à jour le',
        ],
        'deleted_at' => [
            'label' => 'Supprimé le',
        ],
    ],

    'empty_state' => [
        'description' => 'Les opportunités que vous ajoutez ou importez apparaissent ici.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importer des opportunités',
                ],
                'import_export' => [
                    'label' => 'Importer / Exporter',
                ],
                'export' => [
                    'label' => 'Exporter des opportunités',
                    'heading' => 'Exporter des opportunités',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'company' => [
                        'label' => 'Entreprise',
                    ],
                    'contact' => [
                        'label' => 'Interlocuteur',
                    ],
                ],
            ],
        ],
    ],
];
