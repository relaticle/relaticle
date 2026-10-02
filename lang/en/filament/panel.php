<?php

declare(strict_types=1);

return [
    'actions' => [
        'delete_record' => 'Delete record',
    ],

    'user_menu' => [
        'profile' => 'Profile',
        'settings' => 'Settings',
    ],

    'settings_layout' => [
        'back_to_app' => 'Back to app',
    ],

    'impersonation' => [
        'banner' => 'Signed in as :name (:email) for support.',
        'stop' => 'Stop',
    ],

    'navigation_groups' => [
        'tasks' => 'Tasks',
    ],

    'sidebar' => [
        'resize' => 'Resize sidebar',
    ],

    'payload_too_large' => 'That change is too large to save. Shorten the content and try again.',

    'selects' => [
        'member_self' => ':name (You)',
    ],

    'restore_blocked' => [
        'title' => ':record can\'t be restored',
        'conflict' => ':value in :field now belongs to :holder.',
        'fix' => ':conflict Change or remove it there, then restore.',
        'bulk_title' => '{1} :count record wasn\'t restored|[2,*] :count records weren\'t restored',
        'bulk_line' => ':record: :conflict',
        'conflict_with_unknown_holder' => ':value in :field now belongs to another record.',
    ],
];
