<?php

declare(strict_types=1);

return [
    'label' => 'Unternehmen',
    'plural_label' => 'Unternehmen',
    'navigation_label' => 'Unternehmen',

    'fields' => [
        'name' => [
            'label' => 'Unternehmen',
        ],
        'account_owner' => [
            'label' => 'Verantwortlicher',
        ],
        'account_owner_id' => [
            'label' => 'Verantwortlicher',
        ],
        'created_by' => [
            'label' => 'Erstellt von',
        ],
        'creator' => [
            'label' => 'Erstellt von',
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
                'export' => [
                    'label' => 'Unternehmen exportieren',
                    'heading' => 'Unternehmen exportieren',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'account_owner' => [
                        'label' => 'Verantwortlicher',
                    ],
                ],
            ],
            'activity_log' => [
                'description' => 'Alle Aktivitäten zu diesem Unternehmen, nach Woche gruppiert.',
            ],
        ],
    ],
];
