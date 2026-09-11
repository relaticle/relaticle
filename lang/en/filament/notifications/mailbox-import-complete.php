<?php

declare(strict_types=1);

return [
    'title' => 'Mailbox import complete',
    'body' => ':count emails imported from :email.',
    'mail' => [
        'subject' => 'Your mailbox import is complete',
        'greeting' => 'Hello :name,',
        'line' => 'We finished importing :count emails from :email. New mail will keep syncing automatically.',
    ],
];
