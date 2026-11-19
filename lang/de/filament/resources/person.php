<?php

declare(strict_types=1);

return [
    // Lowercase singular/plural so Filament's "New :label" button reads naturally.
    'label' => 'person',
    'plural_label' => 'personen',
    'navigation_label' => 'Personen',

    'fields' => [
        'name' => [
            'label' => 'Person',
        ],
        'company' => [
            'label' => 'Unternehmen',
        ],
        'company_id' => [
            'label' => 'Unternehmen',
        ],
        'account_owner_id' => [
            'label' => 'Kontobesitzer',
        ],
        'creator' => [
            'label' => 'Erstellt von',
        ],
        'creation_source' => [
            'label' => 'Erstellungsquelle',
        ],
        'created_at' => [
            'label' => 'Erstellt am',
        ],
        'updated_at' => [
            'label' => 'Aktualisiert am',
        ],
        'deleted_at' => [
            'label' => 'Gelöscht am',
        ],
    ],

    'empty_state' => [
        'description' => 'Personen, die Sie hinzufügen oder importieren, erscheinen hier.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Personen importieren',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
                'create_company' => [
                    'label' => 'Unternehmen erstellen',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'company' => [
                        'label' => 'Unternehmen',
                    ],
                ],
            ],
        ],
    ],
];