<?php

declare(strict_types=1);

return [
    'columns' => [
        'id' => 'ID',
        'workspace' => 'Arbeitsbereich',
        'account_owner' => 'Kontoinhaber',
        'creator' => 'Erstellt von',
        'creation_source' => 'Erstellungsquelle',
        'created_at' => 'Erstellt am',
        'updated_at' => 'Zuletzt aktualisiert',
        'deleted_at' => 'Gelöscht am',
        'company_name' => 'Unternehmensname',
        'people_count' => 'Anzahl der Personen',
        'opportunities_count' => 'Anzahl der Verkaufschancen',
        'opportunity_name' => 'Name der Verkaufschance',
        'company' => 'Unternehmen',
        'contact_person' => 'Ansprechpartner',
        'notes_count' => 'Anzahl der Notizen',
        'tasks_count' => 'Anzahl der Aufgaben',
    ],

    'notifications' => [
        'completed' => [
            'company' => [
                'body' => 'Ihr Unternehmensexport wurde abgeschlossen und :rows wurden exportiert.',
                'failed' => ':rows konnten nicht exportiert werden.',
            ],
            'note' => [
                'body' => 'Ihr Notizexport wurde abgeschlossen und :rows wurden exportiert.',
                'failed' => ':rows konnten nicht exportiert werden.',
            ],
            'opportunity' => [
                'body' => 'Ihr Verkaufschancenexport wurde abgeschlossen und :rows wurden exportiert.',
                'failed' => ':rows konnten nicht exportiert werden.',
            ],
            'people' => [
                'body' => 'Ihr Personenexport wurde abgeschlossen und :rows wurden exportiert.',
                'failed' => ':rows konnten nicht exportiert werden.',
            ],
            'task' => [
                'body' => 'Ihr Aufgabenexport wurde abgeschlossen und :rows wurden exportiert.',
                'failed' => ':rows konnten nicht exportiert werden.',
            ],
        ],
    ],
];
