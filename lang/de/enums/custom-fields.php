<?php

declare(strict_types=1);

return [
    'company' => [
        'icp' => ['name' => 'ICP'],
        'domains' => ['name' => 'Domains'],
        'linkedin' => ['name' => 'LinkedIn'],
    ],
    'people' => [
        'emails' => ['name' => 'E-Mail-Adressen'],
        'phone_number' => ['name' => 'Telefonnummer'],
        'job_title' => ['name' => 'Position'],
        'linkedin' => ['name' => 'LinkedIn'],
    ],
    'opportunity' => [
        'amount' => ['name' => 'Betrag'],
        'close_date' => ['name' => 'Abschlussdatum'],
        'stage' => [
            'name' => 'Phase',
            'options' => [
                'prospecting' => 'Akquise',
                'qualification' => 'Qualifizierung',
                'needs_analysis' => 'Bedarfsanalyse',
                'value_proposition' => 'Nutzenversprechen',
                'id_decision_makers' => 'Entscheider identifizieren',
                'perception_analysis' => 'Wahrnehmungsanalyse',
                'proposal_price_quote' => 'Angebot/Preisangebot',
                'negotiation_review' => 'Verhandlung/Prüfung',
                'closed_won' => 'Gewonnen',
                'closed_lost' => 'Verloren',
            ],
        ],
    ],
    'task' => [
        'status' => [
            'name' => 'Status',
            'options' => [
                'to_do' => 'Zu erledigen',
                'in_progress' => 'In Bearbeitung',
                'done' => 'Erledigt',
            ],
        ],
        'priority' => [
            'name' => 'Priorität',
            'options' => [
                'low' => 'Niedrig',
                'medium' => 'Mittel',
                'high' => 'Hoch',
            ],
        ],
        'description' => ['name' => 'Beschreibung'],
        'due_date' => ['name' => 'Fälligkeitsdatum'],
    ],
    'note' => [
        'body' => ['name' => 'Inhalt'],
    ],
];
