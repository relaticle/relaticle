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
     * Sender local-parts treated as automated/no-reply. Mail from these addresses
     * (notice@, no-reply@, bounce@, …) does not auto-create a Company or Person
     * during sync — it's machine-sent, so there is no real contact behind it.
     * Matched as a case-insensitive substring of the local-part (before the @).
     */
    'automated_local_parts' => [
        'no-reply',
        'noreply',
        'no_reply',
        'donotreply',
        'do-not-reply',
        'notice',
        'notification',
        'notifications',
        'newsletter',
        'mailer-daemon',
        'mailer',
        'bounce',
        'postmaster',
        'auto-reply',
        'autoreply',
        'automated',
    ],

    /*
     * Sync settings — override via .env
     */
    'sync' => [
        'initial_days' => (int) env('EMAIL_SYNC_INITIAL_DAYS', 90),
        'batch_size' => (int) env('EMAIL_SYNC_BATCH_SIZE', 50),
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

        /*
         * A SENDING email whose worker died (queue eviction, SIGKILL) before failed()
         * ran would otherwise stay SENDING forever — never sent, not retryable, and
         * permanently consuming the account's in-flight send capacity. The dispatcher
         * reclaims such rows (no provider_message_id yet) back to QUEUED once they have
         * been SENDING longer than this. Must exceed the worst-case job runtime
         * (SendEmailJob tries x (timeout + backoff)).
         */
        'reclaim_sending_after_minutes' => (int) env('EMAIL_RECLAIM_SENDING_AFTER_MINUTES', 15),
    ],
];
