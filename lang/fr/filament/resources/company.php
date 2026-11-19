<?php

declare(strict_types=1);

return [
    'label' => 'entreprise',
    'plural_label' => 'entreprises',
    'navigation_label' => 'Entreprises',

    'fields' => [
        'name' => [
            'label' => 'Entreprise',
        ],
        'account_owner' => [
            'label' => 'Responsable',
        ],
        'account_owner_id' => [
            'label' => 'Responsable',
        ],
        'created_by' => [
            'label' => 'Créé par',
        ],
        'creator' => [
            'label' => 'Créé par',
        ],
        'created_at' => [
            'label' => 'Date de création',
        ],
        'updated_at' => [
            'label' => 'Dernière mise à jour',
        ],
        'deleted_at' => [
            'label' => 'Supprimé le',
        ],
    ],

    'empty_state' => [
        'description' => 'Les entreprises que vous ajoutez ou importez apparaissent ici.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importer des entreprises',
                ],
                'import_export' => [
                    'label' => 'Importer / Exporter',
                ],
                'export' => [
                    'label' => 'Exporter des entreprises',
                    'heading' => 'Exporter des entreprises',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'account_owner' => [
                        'label' => 'Responsable',
                    ],
                ],
            ],
            'activity_log' => [
                'description' => 'Toute l\'activité de cette entreprise, regroupée par semaine.',
            ],
        ],
    ],
];
