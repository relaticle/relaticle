<?php

declare(strict_types=1);

return [
    'label' => 'Verkaufschance',
    'plural_label' => 'Verkaufschancen',
    'navigation_label' => 'Verkaufschancen',

    'fields' => [
        'name' => [
            'label' => 'Verkaufschance',
            'placeholder' => 'Titel der Verkaufschance eingeben',
        ],
        'company_id' => [
            'label' => 'Unternehmen',
        ],
        'contact_id' => [
            'label' => 'Ansprechpartner',
        ],
        'creator' => [
            'label' => 'Erstellt von',
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
        'description' => 'Verkaufschancen, die Sie hinzufügen oder importieren, erscheinen hier.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Verkaufschancen importieren',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
                'export' => [
                    'label' => 'Verkaufschancen exportieren',
                    'heading' => 'Verkaufschancen exportieren',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'company' => [
                        'label' => 'Unternehmen',
                    ],
                    'contact' => [
                        'label' => 'Ansprechpartner',
                    ],
                ],
            ],
        ],
    ],
];
