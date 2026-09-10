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
    'meetings' => [
        'heading' => 'Meetings',
        'empty' => [
            'title' => 'No meetings',
            'description' => 'Pick a different date to plan ahead or review past meetings.',
            'next_with_meetings' => 'Next day with meetings',
        ],
        'disconnected' => [
            'title' => 'Turn meetings into opportunities',
            'description' => 'Sync your calendar to get instant meeting context',
        ],
        'date' => [
            'today' => 'Today,',
            'tomorrow' => 'Tomorrow,',
            'yesterday' => 'Yesterday,',
            'other' => ':weekday,',
        ],
        'previous_day' => 'Previous day',
        'next_day' => 'Next day',
        'pick_date' => 'Change date, currently :date',
        'more_actions' => 'More meeting actions',
        'go_to_today' => 'Jump to today',
        'calendar_settings' => 'Calendar settings',
        'open' => 'Open meeting',
        'open_named' => 'Open :title',
        'expand_participants' => 'Show participants for :title',
        'collapse_participants' => 'Hide participants for :title',
        'all_day' => 'All day',
        'more_participants' => '+:count',
        'happening_now' => 'Now',
        'time_range' => ':start to :end',
        'load_more' => 'Load more',
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
