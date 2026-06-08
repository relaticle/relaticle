<?php

declare(strict_types=1);

return [
    'columns' => [
        'id' => 'ID',
        'team' => 'Workspace',
        'account_owner' => 'Account Owner',
        'creator' => 'Created By',
        'creation_source' => 'Creation Source',
        'created_at' => 'Created At',
        'updated_at' => 'Updated At',
        'company_name' => 'Company Name',
        'people_count' => 'Number of People',
        'opportunities_count' => 'Number of Opportunities',
        'opportunity_name' => 'Opportunity Name',
        'company' => 'Company',
        'contact_person' => 'Contact Person',
        'notes_count' => 'Number of Notes',
        'tasks_count' => 'Number of Tasks',
    ],

    'notifications' => [
        'completed' => [
            'company' => [
                'body' => 'Your company export has completed and :rows exported.',
                'failed' => ':rows failed to export.',
            ],
            'note' => [
                'body' => 'Your note export has completed and :rows exported.',
                'failed' => ':rows failed to export.',
            ],
            'opportunity' => [
                'body' => 'Your opportunity export has completed and :rows exported.',
                'failed' => ':rows failed to export.',
            ],
            'people' => [
                'body' => 'Your people export has completed and :rows exported.',
                'failed' => ':rows failed to export.',
            ],
            'task' => [
                'body' => 'Your task export has completed and :rows exported.',
                'failed' => ':rows failed to export.',
            ],
        ],
    ],
];
