<?php

declare(strict_types=1);

return [

    'empty' => [
        'description' => 'Erstellen Sie ein(e) :model, um zu beginnen.',
    ],

    'actions' => [

        'move_record_down' => [
            'label' => ':label nach unten verschieben',
        ],

        'move_record_up' => [
            'label' => ':label nach oben verschieben',
        ],

    ],

    'column_manager' => [

        'actions' => [

            'move_down' => [
                'label' => ':column nach unten verschieben',
            ],

            'move_up' => [
                'label' => ':column nach oben verschieben',
            ],

        ],

        'position' => 'Position :position von :count.',

        'position_in_group' => 'Position :position von :count in :group.',

        'reordered' => ':column auf Position :position von :count verschoben.',

        'reordered_deferred' => ':column auf Position :position von :count verschoben. Wenden Sie die Spalten an, um Ihre Änderungen zu speichern.',

        'reordered_in_group' => ':column auf Position :position von :count in :group verschoben.',

        'reordered_in_group_deferred' => ':column auf Position :position von :count in :group verschoben. Wenden Sie die Spalten an, um Ihre Änderungen zu speichern.',

    ],

    'columns' => [

        'editable' => [
            'failure' => 'Das Speichern konnte nicht bestätigt werden. Laden Sie die Seite neu, bevor Sie es erneut versuchen.',
            'saved' => 'Änderung gespeichert.',
            'saving' => 'Änderung wird gespeichert...',
            'unavailable' => 'Dieser Eintrag kann nicht mehr bearbeitet werden.',
        ],

    ],

    'reordering' => [
        'moved' => ':label auf Position :position von :count verschoben.',
        'position' => ':label, Position :position von :count.',
    ],

    'selection_indicator' => [

        'selected_count' => '1 Eintrag ausgewählt|:count Einträge ausgewählt',

        'actions' => [

            'select_all' => [
                'label' => 'Alle :count Einträge auswählen',
            ],

        ],

    ],

    'default_model_label' => 'Eintrag',

];
