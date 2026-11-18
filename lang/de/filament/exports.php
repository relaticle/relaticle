<?php

declare(strict_types=1);

return [
    'columns' => [
        'id' => 'ID',
        'workspace' => 'Arbeitsbereich',
        'account_owner' => 'Verantwortlicher',
        'creator' => 'Erstellt von',
        'creation_source' => 'Erstellungsquelle',
        'created_at' => 'Erstellt am',
        'updated_at' => 'Aktualisiert am',
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
                'body' => 'Der Export Ihrer Unternehmen ist abgeschlossen. :rows exportiert.',
                'failed' => 'Nicht exportiert: :rows.',
            ],
            'note' => [
                'body' => 'Der Export Ihrer Notizen ist abgeschlossen. :rows exportiert.',
                'failed' => 'Nicht exportiert: :rows.',
            ],
            'opportunity' => [
                'body' => 'Der Export Ihrer Verkaufschancen ist abgeschlossen. :rows exportiert.',
                'failed' => 'Nicht exportiert: :rows.',
            ],
            'people' => [
                'body' => 'Der Export Ihrer Personen ist abgeschlossen. :rows exportiert.',
                'failed' => 'Nicht exportiert: :rows.',
            ],
            'task' => [
                'body' => 'Der Export Ihrer Aufgaben ist abgeschlossen. :rows exportiert.',
                'failed' => 'Nicht exportiert: :rows.',
            ],
        ],
    ],
];
