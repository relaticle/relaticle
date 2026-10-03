<?php

declare(strict_types=1);

return [
    'title' => '{1}Email not sent|[2,*]:count emails not sent',
    'retry' => [
        'body_one' => '":subject" was not sent. Review it on the Failed tab and try again.',
        'body_many' => 'Review them on the Failed tab and try again.',
        'action' => 'View failed emails',
    ],
    'reconnect' => [
        'body_one' => '":subject" was not sent because its mailbox needs reconnecting. Reconnect it, then retry the email from the Failed tab.',
        'body_many' => 'Their mailbox needs reconnecting. Reconnect it, then retry them from the Failed tab.',
        'action' => 'Reconnect account',
    ],
    'reasons' => [
        'mailbox_needs_reconnect' => 'The mailbox needs reconnecting before this email can be sent.',
    ],
];
