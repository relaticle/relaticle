<?php

declare(strict_types=1);

return [
    // Lowercase to match the en/ convention (Filament title-cases for display contexts).
    'label' => 'entreprise',
    'plural_label' => 'entreprises',
    'navigation_label' => 'Entreprises',

    'fields' => [
        'name' => [
            'label' => 'Entreprise',
        ],
        'account_owner' => [
            'label' => 'Responsable du compte',
        ],
        'account_owner_id' => [
            'label' => 'Responsable du compte',
        ],
        'created_by' => [
            'label' => 'Créé par',
        ],
        'creator' => [
            'label' => 'Créé par',
        ],
        'creation_source' => [
            'label' => 'Source de création',
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

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importer des entreprises',
                ],
                'import_export' => [
                    'label' => 'Importer / Exporter',
                ],
            ],
        ],
        'view' => [
            'actions' => [
                'edit' => [
                    'label' => 'Modifier',
                ],
                'copy_page_url' => [
                    'label' => "Copier l'URL de la page",
                ],
                'copy_record_id' => [
                    'label' => "Copier l'identifiant",
                ],
            ],
            'infolist' => [
                'fields' => [
                    'logo' => [
                        'label' => '',
                    ],
                    'creator' => [
                        'label' => 'Créé par',
                    ],
                    'account_owner' => [
                        'label' => 'Responsable du compte',
                    ],
                    'created_at' => [
                        'label' => 'Date de création',
                    ],
                    'updated_at' => [
                        'label' => 'Dernière mise à jour',
                    ],
                ],
            ],
        ],
    ],

    'relation_managers' => [
        'people' => [
            'model_label' => 'personne',
        ],
        'notes' => [
            'fields' => [
                'people' => [
                    'label' => 'Personnes',
                ],
            ],
        ],
        'tasks' => [
            'fields' => [
                'assignees' => [
                    'label' => 'Responsable',
                ],
                'people' => [
                    'label' => 'Personnes',
                ],
                'created_at' => [
                    'label' => 'Créé le',
                ],
            ],
        ],
    ],
];
