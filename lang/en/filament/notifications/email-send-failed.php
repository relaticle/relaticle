<?php

declare(strict_types=1);

return [
    'title' => '{1}Email not sent|[2,*]:count emails not sent',
    'partial_title' => ':sent sent, :failed not sent',
    'retry' => [
        'body_one' => '":subject" was not sent. Review it in the outbox under Failed and try again.',
        'body_many' => 'Review them in the outbox under Failed and try again.',
        'action' => 'View failed emails',
    ],
    'reconnect' => [
        'body_one' => '":subject" was not sent because its mailbox needs reconnecting. Reconnect it, then retry the email from the outbox.',
        'body_many' => 'Their mailbox needs reconnecting. Reconnect it, then retry them from the outbox.',
        'action' => 'Reconnect account',
    ],
    'subscribe' => [
        'body_one' => '":subject" was not sent because :workspace is paused. Once the workspace is reopened, retry the email from the outbox.',
        'body_many' => 'They were not sent because :workspace is paused. Once the workspace is reopened, retry them from the outbox.',
        'action' => 'View billing',
    ],
    'reasons' => [
        'mailbox_needs_reconnect' => 'The mailbox needs reconnecting before this email can be sent.',
        'workspace_paused' => 'The workspace was paused when this email was due, so it was not sent.',
        'agent_connection_revoked' => 'The assistant connection that queued this email was removed before it was due, so it was not sent.',
        'agent_send_not_allowed' => 'Your role in this workspace no longer allows an assistant to send email for you, so it was not sent.',
    ],
];
