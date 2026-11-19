<?php

declare(strict_types=1);

return [
    // Lowercase singular/plural so Filament's "New :label" button reads naturally.
    'label' => 'aufgabe',
    'plural_label' => 'aufgaben',
    'navigation_label' => 'Aufgaben',

    'fields' => [
        'assignees' => [
            'label' => 'Zugewiesen an',
            'placeholder' => 'Ein Mitglied zugewiesen',
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

    'actions' => [
        'copy_url' => 'Aufgaben-URL kopieren',
        'copy_id' => 'Aufgaben-ID kopieren',
    ],

    'filters' => [
        'assigned_to_me' => [
            'label' => 'Mir zugewiesen',
        ],
        'creation_source' => [
            'label' => 'Erstellungsquelle',
        ],
    ],

    'empty_state' => [
        'description' => 'Aufgaben, die Sie hinzufügen, erscheinen hier.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Aufgaben importieren',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
            ],
        ],
    ],
];