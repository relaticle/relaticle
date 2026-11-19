<?php

declare(strict_types=1);

return [
    'label' => 'Notiz',
    'plural_label' => 'Notizen',
    'navigation_label' => 'Notizen',

    'fields' => [
        'title' => [
            'label' => 'Titel',
        ],
        'companies' => [
            'label' => 'Unternehmen',
        ],
        'people' => [
            'label' => 'Personen',
        ],
        'opportunities' => [
            'label' => 'Verkaufschancen',
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

    'filters' => [
        'creation_source' => [
            'label' => 'Erstellungsquelle',
        ],
    ],

    'created_periods' => [
        'today' => 'Heute erstellt',
        'this_week' => 'Diese Woche erstellt',
        'this_month' => 'Diesen Monat erstellt',
        'this_year' => 'Dieses Jahr erstellt',
        'earlier' => 'Früher erstellt',
    ],

    'cards' => [
        'untitled' => 'Unbenannte Notiz',
        'no_content' => 'Diese Notiz hat keinen Inhalt.',
        'today' => 'Heute',
        'yesterday' => 'Gestern',
        'deleted' => 'Gelöscht',
    ],

    'empty_state' => [
        'description' => 'Notizen, die Sie hinzufügen, erscheinen hier.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Notizen importieren',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
                'export' => [
                    'label' => 'Notizen exportieren',
                    'heading' => 'Notizen exportieren',
                ],
            ],
        ],
    ],
];
