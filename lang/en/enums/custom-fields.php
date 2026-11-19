<?php

declare(strict_types=1);

return [
    'company' => [
        'icp' => ['name' => 'ICP'],
        'domains' => ['name' => 'Domains'],
        'linkedin' => ['name' => 'LinkedIn'],
    ],
    'people' => [
        'emails' => ['name' => 'Emails'],
        'phone_number' => ['name' => 'Phone Number'],
        'job_title' => ['name' => 'Job Title'],
        'linkedin' => ['name' => 'LinkedIn'],
    ],
    'opportunity' => [
        'amount' => ['name' => 'Amount'],
        'close_date' => ['name' => 'Close Date'],
        'stage' => [
            'name' => 'Stage',
            'options' => [
                'prospecting' => 'Prospecting',
                'qualification' => 'Qualification',
                'needs_analysis' => 'Needs Analysis',
                'value_proposition' => 'Value Proposition',
                'id_decision_makers' => 'Id. Decision Makers',
                'perception_analysis' => 'Perception Analysis',
                'proposal_price_quote' => 'Proposal/Price Quote',
                'negotiation_review' => 'Negotiation/Review',
                'closed_won' => 'Closed Won',
                'closed_lost' => 'Closed Lost',
            ],
        ],
    ],
    'task' => [
        'status' => [
            'name' => 'Status',
            'options' => [
                'to_do' => 'To do',
                'in_progress' => 'In progress',
                'done' => 'Done',
            ],
        ],
        'priority' => [
            'name' => 'Priority',
            'options' => [
                'low' => 'Low',
                'medium' => 'Medium',
                'high' => 'High',
            ],
        ],
        'description' => ['name' => 'Description'],
        'due_date' => ['name' => 'Due Date'],
    ],
    'note' => [
        'body' => ['name' => 'Body'],
    ],
];
