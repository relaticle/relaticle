<?php

declare(strict_types=1);

return [
    'approved' => [
        'title' => 'Email access approved',
        'body' => ':owner approved your request.',
        'body_without_owner' => 'Your request was approved.',
    ],

    'denied' => [
        'title' => 'Email access denied',
        'body' => ':owner denied your request.',
        'body_without_owner' => 'Your request was denied.',
    ],

    'expired' => [
        'title' => 'Email access request expired',
        'body' => ':owner did not respond in time.',
        'body_without_owner' => 'Your request expired without a response.',
    ],

    'mail' => [
        'subject_line' => 'Subject: :subject',
        'action_view' => 'View email',
        'action_open' => 'Open Relaticle',
    ],
];
