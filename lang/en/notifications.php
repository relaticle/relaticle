<?php

declare(strict_types=1);

return [
    'title' => 'Notifications',

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
        'email_access' => [
            'label' => 'Email Access',
            'description' => 'Email me when someone asks to read an email I own. Notify me when my own request is approved, denied or expires.',
        ],
        'email_shared' => [
            'label' => 'Shared Emails',
            'description' => 'Notify me when someone shares an email with me.',
        ],
        'task_digest' => [
            'label' => 'Daily Digest',
            'description' => 'Notify me every morning about tasks overdue and due today.',
        ],
    ],

    'events' => [
        'task_assigned' => [
            'title' => ':actor assigned you a task',
            'title_without_actor' => 'You have a new task',
            'action' => 'View task',
        ],
        'table_layout_changed' => [
            'deleted_title' => ':actor deleted your layout ":name"',
            'deleted_title_without_actor' => 'Your layout ":name" was deleted',
            'deleted_body' => 'It was on the :table table. Members who used it keep what their table shows.',
            'unshared_title' => ':actor stopped sharing your layout ":name"',
            'unshared_title_without_actor' => 'Your layout ":name" is no longer shared',
            'unshared_body' => 'It is private to you again on the :table table.',
            'action' => 'Open :table',
        ],
    ],

    'panel' => [
        'heading' => 'Inbox',
        'open' => 'Open inbox',
        'open_with_count' => 'Open inbox, :count item needs your attention|Open inbox, :count items need your attention',
        'close' => 'Close inbox',
        'settings' => 'Notification settings',
        'announce' => ':count new notification|:count new notifications',
        'announcements' => [
            'archived' => 'Archived: :title',
            'approved' => 'Approved: :title',
            'denied' => 'Denied: :title',
            'already_decided' => 'This request was already decided: :title',
        ],
        'footer' => 'Showing the latest :count notifications.',
        'tabs' => [
            'label' => 'Inbox sections',
            'notifications' => 'Notifications',
            'requests' => 'Requests',
        ],
        'mark_all_read' => 'Mark all as read',
        'groups' => [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'earlier' => 'Earlier',
        ],
        'row' => [
            'unread' => 'Unread',
            'mark_read' => 'Mark as read',
            'mark_unread' => 'Mark as unread',
            'archive' => 'Archive',
        ],
        'requests' => [
            'view' => 'View',
            'approve' => 'Approve',
            'deny' => 'Deny',
            'approved' => 'Approved',
            'denied' => 'Denied',
            'already_decided' => 'This request was already decided.',
            'expires' => 'Expires :when',
        ],
        'empty' => [
            'notifications' => [
                'heading' => 'You are all caught up',
                'description' => 'Task assignments, imports and exports, and other updates in this workspace land here.',
            ],
            'requests' => [
                'heading' => 'No requests waiting',
                'description' => 'When a teammate asks for something you own, you decide here.',
            ],
            'settings' => 'Settings',
        ],
    ],

    'saved' => 'Notification preferences updated.',
];
