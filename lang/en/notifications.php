<?php

declare(strict_types=1);

return [
    'title' => 'Notifications',
    'subtitle' => 'Customize your notification settings to stay informed without being overwhelmed.',

    'digest' => [
        'heading' => 'Daily digest',
        'title' => 'Enable daily digest',
        'description' => 'Includes tasks overdue and due today. Sent every morning if any tasks are due or overdue.',
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
            'label' => 'Daily digest',
            'description' => 'A morning summary of tasks overdue and due today.',
        ],
    ],

    'saved' => 'Notification preferences updated.',
];
