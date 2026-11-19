<?php

declare(strict_types=1);

return [
    // Filament title-cases the singular/plural labels for display contexts
    // (navigation menu, page headings) but injects them raw into the
    // "New :label" create button. Keep lowercase here so the button reads
    // "New company" while titles render as "Company"/"Companies".
    'label' => 'unternehmen',
    'plural_label' => 'unternehmen',
    'navigation_label' => 'Unternehmen',

    'fields' => [
        'name' => [
            'label' => 'Unternehmen',
        ],
        'account_owner' => [
            'label' => 'Kontobesitzer',
        ],
        'account_owner_id' => [
            'label' => 'Kontobesitzer',
        ],
        'created_by' => [
            'label' => 'Erstellt von',
        ],
        'creator' => [
            'label' => 'Erstellt von',
        ],
        'creation_source' => [
            'label' => 'Erstellungsquelle',
        ],
        'created_at' => [
            'label' => 'Erstellungsdatum',
        ],
        'updated_at' => [
            'label' => 'Letzte Aktualisierung',
        ],
        'deleted_at' => [
            'label' => 'Gelöscht am',
        ],
    ],

    'empty_state' => [
        'description' => 'Unternehmen, die Sie hinzufügen oder importieren, erscheinen hier.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Unternehmen importieren',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'account_owner' => [
                        'label' => 'Kontobesitzer',
                    ],
                ],
            ],
            'activity_log' => [
                'description' => 'Alle Aktivitäten für dieses Unternehmen, gruppiert nach Woche.',
            ],
        ],
    ],

    'relation_managers' => [
        'people' => [
            'model_label' => 'person',
        ],
    ],
];