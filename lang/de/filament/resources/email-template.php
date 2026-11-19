<?php

declare(strict_types=1);

return [
    'fields' => [
        'name' => [
            'label' => 'Vorlage-Name',
        ],
        'subject' => [
            'label' => 'Betreff',
        ],
        'body_html' => [
            'label' => 'Inhalt',
        ],
        'is_shared' => [
            'label' => 'Mit Arbeitsbereich teilen',
            'helper_text' => 'Jeder in Ihrem Arbeitsbereich kann diese Vorlage verwenden.',
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
        'description' => 'Speichern Sie eine Nachricht, die Sie oft senden, damit Sie sie wiederverwenden können.',
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