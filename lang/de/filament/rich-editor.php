<?php

declare(strict_types=1);

return [
    // `:key` wird durch das Trigger-Zeichen ersetzt, das als Tastenkopf gerendert wird.
    'placeholder' => 'Geben Sie :key ein, um eine Überschrift, eine Liste oder ein Bild einzufügen',

    'limit_reached' => 'Dieses Feld ist voll. Löschen Sie etwas Inhalt, um weiterzuschreiben.',

    'checklist' => [
        'item' => 'Checklistenpunkt: :item',
        'empty_item' => 'Leerer Checklistenpunkt',
    ],

    'selection_toolbar' => [
        'text_style' => 'Textstil',
    ],

    'slash_menu' => [
        'no_results' => 'Keine Blöcke für ":query" gefunden',

        'groups' => [
            'text' => 'Text',
            'lists' => 'Listen',
            'insert' => 'Einfügen',
        ],

        'items' => [
            'h1' => [
                'label' => 'Überschrift 1',
                'description' => 'Große Sektionsüberschrift',
            ],
            'h2' => [
                'label' => 'Überschrift 2',
                'description' => 'Mittlere Sektionsüberschrift',
            ],
            'h3' => [
                'label' => 'Überschrift 3',
                'description' => 'Kleine Sektionsüberschrift',
            ],
            'paragraph' => [
                'label' => 'Text',
                'description' => 'Beginnen Sie einfach mit dem Tippen von einfachem Text',
            ],
            'bulletList' => [
                'label' => 'Aufzählungsliste',
                'description' => 'Erstellen Sie eine einfache Aufzählungsliste',
            ],
            'orderedList' => [
                'label' => 'Nummerierte Liste',
                'description' => 'Erstellen Sie eine Liste mit Nummerierung',
            ],
            'taskList' => [
                'label' => 'Checkliste',
                'description' => 'Verfolgen Sie Schritte mit Kontrollkästchen',
            ],
            'blockquote' => [
                'label' => 'Zitat',
                'description' => 'Heben Sie einen Abschnitt ab',
            ],
            'codeBlock' => [
                'label' => 'Code',
                'description' => 'Erfassen Sie einen Snippet',
            ],
            'table' => [
                'label' => 'Tabelle',
                'description' => 'Legen Sie Daten in Zeilen aus',
            ],
            'details' => [
                'label' => 'Umschalter',
                'description' => 'Verbergen Sie Details hinter einer Zusammenfassung',
            ],
            'horizontalRule' => [
                'label' => 'Trennlinie',
                'description' => 'Trennen Sie Abschnitte mit einer Linie',
            ],
            'attachFiles' => [
                'label' => 'Bild',
                'description' => 'Laden Sie ein Bild hoch',
            ],
        ],
    ],
];
