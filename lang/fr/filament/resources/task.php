<?php

declare(strict_types=1);

return [
    'label' => 'tâche',
    'plural_label' => 'tâches',
    'navigation_label' => 'Tâches',

    'fields' => [
        'title' => [
            'label' => 'Titre',
        ],
        'assignees' => [
            'label' => 'Assignés',
            'placeholder' => 'Assigner un membre',
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
            'label' => 'Supprimé le',
        ],
    ],

    'actions' => [
        'copy_url' => "Copier l'URL de la tâche",
        'copy_id' => "Copier l'ID de la tâche",
    ],

    'empty_state' => [
        'description' => 'Les tâches que vous ajoutez apparaissent ici.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importer des tâches',
                ],
                'import_export' => [
                    'label' => 'Importer / Exporter',
                ],
                'export' => [
                    'label' => 'Exporter des tâches',
                    'heading' => 'Exporter des tâches',
                ],
            ],
        ],
    ],
];
