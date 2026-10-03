<?php

declare(strict_types=1);

return [
    'actions' => [
        'compose' => [
            'label' => 'Compose',
            'tooltip' => 'Keyboard shortcut: C',
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
            'title' => 'Too late, the email has already been sent',
        ],
    ],
    'fields' => [
        'template' => [
            'label' => 'Template',
            'placeholder' => 'Apply a template…',
        ],
        'body' => [
            'label' => 'Body',
        ],
        'scheduled_for' => [
            'label' => 'Send at',
            'helper_text' => 'Leave blank to send with a 5-second undo window.',
        ],
        'signature' => [
            'label' => 'Signature',
            'placeholder' => 'No signature',
        ],
    ],
    'sections' => [
        'settings' => [
            'description' => 'Privacy, scheduling, and signature options for this email.',
        ],
    ],
];
