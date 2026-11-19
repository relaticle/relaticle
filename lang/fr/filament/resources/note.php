<?php

declare(strict_types=1);

return [
    'label' => 'note',
    'plural_label' => 'notes',
    'navigation_label' => 'Notes',

    'fields' => [
        'title' => [
            'label' => 'Titre',
        ],
        'companies' => [
            'label' => 'Entreprises',
        ],
        'people' => [
            'label' => 'Personnes',
        ],
        'opportunities' => [
            'label' => 'Opportunités',
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
            'label' => 'Supprimée le',
        ],
    ],

    'filters' => [
        'creation_source' => [
            'label' => 'Source de création',
        ],
    ],

    'created_periods' => [
        'today' => "Créées aujourd'hui",
        'this_week' => 'Créées cette semaine',
        'this_month' => 'Créées ce mois-ci',
        'this_year' => 'Créées cette année',
        'earlier' => 'Créées plus tôt',
    ],

    'cards' => [
        'untitled' => 'Note sans titre',
        'no_content' => "Cette note n'a pas de contenu.",
        'today' => "Aujourd'hui",
        'yesterday' => 'Hier',
        'deleted' => 'Supprimée',
    ],

    'empty_state' => [
        'description' => 'Les notes que vous ajoutez apparaissent ici.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importer des notes',
                ],
                'import_export' => [
                    'label' => 'Importer / Exporter',
                ],
                'export' => [
                    'label' => 'Exporter des notes',
                    'heading' => 'Exporter des notes',
                ],
            ],
        ],
    ],
];
