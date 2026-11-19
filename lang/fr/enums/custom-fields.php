<?php

declare(strict_types=1);

return [
    'company' => [
        'icp' => ['name' => 'ICP'],
        'domains' => ['name' => 'Domaines'],
        'linkedin' => ['name' => 'LinkedIn'],
    ],
    'people' => [
        'emails' => ['name' => 'E-mails'],
        'phone_number' => ['name' => 'Numéro de téléphone'],
        'job_title' => ['name' => 'Poste'],
        'linkedin' => ['name' => 'LinkedIn'],
    ],
    'opportunity' => [
        'amount' => ['name' => 'Montant'],
        'close_date' => ['name' => 'Date de clôture'],
        'stage' => [
            'name' => 'Étape',
            'options' => [
                'prospecting' => 'Prospection',
                'qualification' => 'Qualification',
                'needs_analysis' => 'Analyse des besoins',
                'value_proposition' => 'Proposition de valeur',
                'id_decision_makers' => 'Id. des décideurs',
                'perception_analysis' => 'Analyse de la perception',
                'proposal_price_quote' => 'Proposition/Devis',
                'negotiation_review' => 'Négociation/Révision',
                'closed_won' => 'Gagnée',
                'closed_lost' => 'Perdue',
            ],
        ],
    ],
    'task' => [
        'status' => [
            'name' => 'Statut',
            'options' => [
                'to_do' => 'À faire',
                'in_progress' => 'En cours',
                'done' => 'Terminé',
            ],
        ],
        'priority' => [
            'name' => 'Priorité',
            'options' => [
                'low' => 'Basse',
                'medium' => 'Moyenne',
                'high' => 'Haute',
            ],
        ],
        'description' => ['name' => 'Description'],
        'due_date' => ['name' => 'Échéance'],
    ],
    'note' => [
        'body' => ['name' => 'Contenu'],
    ],
];
