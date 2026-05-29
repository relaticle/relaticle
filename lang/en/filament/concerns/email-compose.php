<?php

declare(strict_types=1);

return [
    'actions' => [
        'compose' => [
            'label' => 'Compose',
        ],
        'undo' => [
            'label' => 'Undo',
        ],
    ],
    'notifications' => [
        'queued' => [
            'title' => 'Email queued',
            'body' => 'Your email is being sent.',
        ],
        'cancelled' => [
            'title' => 'Send cancelled',
        ],
        'too_late' => [
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
        'message' => [
            'label' => 'Message',
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
    ],
];
