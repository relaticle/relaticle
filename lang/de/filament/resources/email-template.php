<?php

declare(strict_types=1);

return [
    'fields' => [
        'name' => [
            'label' => 'Vorlagenname',
        ],
        'subject' => [
            'label' => 'Betreff',
        ],
        'body_html' => [
            'label' => 'Inhalt',
        ],
        'is_shared' => [
            'label' => 'Mit Arbeitsbereich teilen',
            'helper_text' => 'Alle Mitglieder Ihres Arbeitsbereichs können diese Vorlage verwenden.',
        ],
    ],

    'columns' => [
        'subject' => [
            'placeholder' => '—',
        ],
        'is_shared' => [
            'label' => 'Geteilt',
        ],
        'creator' => [
            'label' => 'Erstellt von',
            'placeholder' => '—',
        ],
        'created_at' => [
            'label' => 'Erstellt',
        ],
    ],

    'empty' => [
        'heading' => 'Keine Vorlagen',
        'description' => 'Speichern Sie eine häufig versendete Nachricht, um sie wiederzuverwenden.',
    ],

    'actions' => [
        'create' => [
            'label' => 'Neue Vorlage',
        ],
        'edit' => [
            'label' => 'Vorlage bearbeiten',
        ],
        'delete' => [
            'label' => 'Löschen',
        ],
    ],
];
