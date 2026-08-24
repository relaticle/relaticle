<?php

declare(strict_types=1);

return [
    'activation' => [
        'heading' => 'Get started',
        'progress' => ':completed of :total',
        'dismiss' => 'Dismiss',
        'sample_data' => 'This workspace is preloaded with sample records so you can look around. Anything you add sits alongside them.',
        'steps' => [
            'first_record' => [
                'label' => 'Add your first contact',
                'description' => 'Put one real person in the CRM and the rest follows',
            ],
            'import' => [
                'label' => 'Import your existing contacts',
                'description' => 'Bring a CSV from your spreadsheet or old CRM',
            ],
            'invite' => [
                'label' => 'Invite a teammate',
                'description' => 'Shared pipelines beat private ones',
            ],
            'ask_rela' => [
                'label' => 'Ask Rela about your pipeline',
                'description' => 'Your assistant can read, draft, and update records for you',
            ],
        ],
    ],
    'tasks' => [
        'heading' => 'Tasks',
        'view_all' => 'View all',
        'create_action_label' => 'New task',
        'complete' => 'Mark complete',
        'empty' => [
            'title' => 'Stay on top of work',
            'description' => 'Create tasks for yourself or your team to track next steps',
        ],
    ],
];
