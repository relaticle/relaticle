<?php

declare(strict_types=1);

return [
    'compose' => [
        'label' => 'Compose',
    ],

    'reply_forward' => [
        'modal_heading' => [
            'reply' => 'Reply',
            'reply_all' => 'Reply All',
            'forward' => 'Forward',
        ],
    ],

    'notifications' => [
        'queued' => [
            'title' => 'Email queued',
        ],
        'undo_action' => [
            'label' => 'Undo',
        ],
        'send_cancelled' => [
            'title' => 'Send cancelled',
        ],
        'send_too_late' => [
            'title' => 'Too late — email already sent',
        ],
    ],

    'fields' => [
        'from' => [
            'label' => 'From',
        ],
        'template' => [
            'label' => 'Template',
            'placeholder' => 'Apply a template…',
        ],
        'to' => [
            'label' => 'To',
            'placeholder' => 'email@example.com',
        ],
        'cc' => [
            'label' => 'CC',
            'placeholder' => 'email@example.com',
        ],
        'bcc' => [
            'label' => 'BCC',
            'placeholder' => 'email@example.com',
        ],
        'body' => [
            'label' => 'Body',
        ],
        'privacy_tier' => [
            'label' => 'Who can see this email?',
            'helper_text' => 'Defaults to your team or personal sharing setting.',
        ],
        'scheduled_for' => [
            'label' => 'Send at',
            'helper_text' => 'Leave blank to send with a 30-second undo window.',
        ],
        'signature' => [
            'placeholder' => 'No signature',
        ],
        'message' => [
            'label' => 'Message',
        ],
    ],
];
