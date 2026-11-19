<?php

declare(strict_types=1);

return [
    // Lowercase singular/plural so Filament's "New :label" button reads naturally.
    'label' => 'verkaufschance',
    'plural_label' => 'verkaufschancen',
    'navigation_label' => 'Verkaufschancen',

    'fields' => [
        'name' => [
            'label' => 'Verkaufschance',
            'placeholder' => 'Geben Sie Verkaufschance-Überschrift ein',
        ],
        'company_id' => [
            'label' => 'Unternehmen',
        ],
        'contact_id' => [
            'label' => 'Kontaktperson',
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
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'company' => [
                        'label' => 'Unternehmen',
                    ],
                    'contact' => [
                        'label' => 'Kontaktperson',
                    ],
                ],
            ],
        ],
    ],
];
