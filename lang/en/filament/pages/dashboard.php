<?php

declare(strict_types=1);

return [
    'activation' => [
        'heading' => 'Get started',
        'progress' => ':completed/:total steps completed',
        'dismiss' => 'Dismiss',
        'collapse' => 'Collapse checklist',
        'more_actions' => 'More actions',
        'encouragement' => "Let's go!",
        'invite_members' => 'Invite team members',
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
                'label_empty' => 'Ask Rela how to get started',
                'description' => 'Your assistant can read, draft, and update records for you',
                'prompt' => "What's in my pipeline right now?",
                'prompt_empty' => 'What can you do to help me set up this workspace?',
            ],
        ],
    ],
    'mailbox' => [
        'heading' => 'Emails',
        'view_all' => 'View all',
        'empty' => [
            'title' => 'Keep conversations in one place',
            'description' => 'Connect your mailbox to read and reply next to your records',
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
