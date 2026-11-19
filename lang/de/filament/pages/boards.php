<?php

declare(strict_types=1);

return [
    'view_switcher' => [
        'label' => 'Ansicht wechseln',
        'list' => 'Liste',
        'board' => 'Board',
        'cards' => 'Karten',
    ],

    'move_denied' => 'Sie haben keine Berechtigung, diese Karte zu verschieben.',

    'move_to' => [
        'label' => 'Verschieben zu',
        'moved' => 'In :column verschoben',
    ],

    'filters_button' => [
        'label' => 'Filter',
        'clear' => 'Filter löschen',
    ],

    'opportunities' => [
        'title' => 'Verkaufschancen',
        'actions' => [
            'add' => 'Verkaufschance hinzufügen',
            'edit' => 'Bearbeiten',
            'delete' => 'Löschen',
        ],
        'filters' => [
            'company' => 'Unternehmen',
            'contact' => 'Ansprechpartner',
        ],
        'form' => [
            'name_placeholder' => 'Titel der Verkaufschance eingeben',
        ],
        'groupings' => [
            'company' => 'Unternehmen',
            'no_company' => 'Kein Unternehmen',
        ],
    ],

    'tasks' => [
        'title' => 'Aufgaben',
        'actions' => [
            'add' => 'Aufgabe hinzufügen',
            'edit' => 'Bearbeiten',
            'delete' => 'Löschen',
        ],
        'filters' => [
            'assignee' => 'Zuweisung',
        ],
        'groupings' => [
            'assignee' => 'Zuweisung',
            'unassigned' => 'Nicht zugewiesen',
        ],
        'checklist_progress' => 'Fortschritt der Checkliste',
        'checklist_progress_tooltip' => '{1} :checked von :total Element erledigt|[2,*] :checked von :total Elemente erledigt',
    ],
];
