<?php

declare(strict_types=1);

return [
    'label' => 'personne',
    'plural_label' => 'personnes',
    'navigation_label' => 'Personnes',

    'fields' => [
        'name' => [
            'label' => 'Personne',
        ],
        'company' => [
            'label' => 'Entreprise',
        ],
        'company_id' => [
            'label' => 'Entreprise',
        ],
        'account_owner_id' => [
            'label' => 'Responsable',
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
        'description' => 'Les personnes que vous ajoutez ou importez apparaissent ici.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importer des personnes',
                ],
                'import_export' => [
                    'label' => 'Importer / Exporter',
                ],
                'export' => [
                    'label' => 'Exporter des personnes',
                    'heading' => 'Exporter des personnes',
                ],
                'create_company' => [
                    'label' => 'Créer une entreprise',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'company' => [
                        'label' => 'Entreprise',
                    ],
                ],
            ],
        ],
    ],
];
