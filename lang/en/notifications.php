<?php

declare(strict_types=1);

return [
    'title' => 'Notifications',

    'digest' => [
        'heading' => 'Daily digest',
        'title' => 'Enable daily digest',
        'description' => 'Includes tasks overdue and due today. Sent every morning if any tasks are due or overdue.',
    ],

    'onboarding' => [
        'heading' => 'Getting started',
        'title' => 'Setup reminders',
        'description' => 'One reminder a couple of days after you create a workspace, if it is still empty.',
        'subject' => 'Your workspace is waiting',
        'mail_heading' => ':name, your workspace is still waiting',
        'mail_button' => 'Continue in Rela',
    ],

    'collaboration' => [
        'heading' => 'Collaboration notifications',
        'notify_me_about' => 'Notify me about',
    ],

    'channels' => [
        'in_app' => 'App',
        'email' => 'Email',
    ],

    'types' => [
        'task_assigned' => [
            'label' => 'Task Assignments',
            'description' => 'Notify me when I\'m assigned a task.',
        ],
        'task_digest' => [
            'label' => 'Daily Digest',
            'description' => 'Notify me every morning about tasks overdue and due today.',
        ],
        'setup_nudge' => [
            'label' => 'Setup Reminder',
            'description' => 'Remind me about workspace setup steps I have not finished.',
        ],
    ],

    'saved' => 'Notification preferences updated.',
];
