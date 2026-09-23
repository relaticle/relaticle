<?php

declare(strict_types=1);

return [
    'title' => 'Notifications',

    'digest' => [
        'heading' => 'Daily digest',
        'title' => 'Enable daily digest',
        'description' => 'Includes tasks overdue and due today. Sent every morning if any tasks are due or overdue.',
    ],

    'marketing' => [
        'heading' => 'Product updates',
        'title' => 'Email me product updates',
        'description' => 'Occasional news about new features and changes. Unsubscribe any time.',
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
    ],

    'saved' => 'Notification preferences updated.',
];
