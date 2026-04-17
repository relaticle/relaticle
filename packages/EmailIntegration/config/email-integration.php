<?php

declare(strict_types=1);

return [
    /*
     * Email domains considered "public" — these are excluded from auto-company matching
     * during email sync to prevent creating garbage companies like "Gmail Inc".
     * Teams can add further domain exclusions via Settings → Public Email Domains.
     */
    'public_domains' => [
        'gmail.com',
        'googlemail.com',
        'yahoo.com',
        'yahoo.co.uk',
        'outlook.com',
        'hotmail.com',
        'hotmail.co.uk',
        'live.com',
        'msn.com',
        'icloud.com',
        'me.com',
        'mac.com',
        'protonmail.com',
        'proton.me',
        'pm.me',
        'aol.com',
        'zohomail.com',
        'yandex.com',
        'yandex.ru',
        'mail.com',
        'inbox.com',
        'gmx.com',
        'gmx.net',
    ],

    /*
     * Sync settings — override via .env
     */
    'sync' => [
        'initial_days' => env('EMAIL_SYNC_INITIAL_DAYS', 90),
        'interval_minutes' => env('EMAIL_SYNC_INTERVAL_MINUTES', 5),
        'batch_size' => env('EMAIL_SYNC_BATCH_SIZE', 50),
    ],

    /*
     * Outbox & deliverability defaults. Per-account values on
     * connected_accounts.{hourly,daily}_send_limit override these.
     */
    'outbox' => [
        'defaults' => [
            'hourly_send_limit' => (int) env('EMAIL_DEFAULT_HOURLY_LIMIT', 12),
            'daily_send_limit' => (int) env('EMAIL_DEFAULT_DAILY_LIMIT', 200),
        ],
        'undo_send_window_seconds' => (int) env('EMAIL_UNDO_SEND_WINDOW', 30),
        'max_queued_per_user' => (int) env('EMAIL_MAX_QUEUED_PER_USER', 100),
    ],
];
