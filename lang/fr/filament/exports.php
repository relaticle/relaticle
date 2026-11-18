<?php

declare(strict_types=1);

return [
    'columns' => [
        'id' => 'ID',
        'workspace' => 'Espace de travail',
        'account_owner' => 'Responsable',
        'creator' => 'Créé par',
        'creation_source' => 'Source de création',
        'created_at' => 'Créé le',
        'updated_at' => 'Mis à jour le',
        'deleted_at' => 'Supprimé le',
        'company_name' => "Nom de l'entreprise",
        'people_count' => 'Nombre de personnes',
        'opportunities_count' => "Nombre d'opportunités",
        'opportunity_name' => "Nom de l'opportunité",
        'company' => 'Entreprise',
        'contact_person' => 'Interlocuteur',
        'notes_count' => 'Nombre de notes',
        'tasks_count' => 'Nombre de tâches',
    ],

    'notifications' => [
        'completed' => [
            'company' => [
                'body' => "L'export de vos entreprises est terminé (:rows).",
                'failed' => 'Échec pour :rows.',
            ],
            'note' => [
                'body' => "L'export de vos notes est terminé (:rows).",
                'failed' => 'Échec pour :rows.',
            ],
            'opportunity' => [
                'body' => "L'export de vos opportunités est terminé (:rows).",
                'failed' => 'Échec pour :rows.',
            ],
            'people' => [
                'body' => "L'export des personnes est terminé (:rows).",
                'failed' => 'Échec pour :rows.',
            ],
            'task' => [
                'body' => "L'export de vos tâches est terminé (:rows).",
                'failed' => 'Échec pour :rows.',
            ],
        ],
    ],
];
