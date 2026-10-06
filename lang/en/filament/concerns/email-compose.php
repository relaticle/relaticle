<?php

declare(strict_types=1);

return [
    'actions' => [
        'compose' => [
            'label' => 'Compose',
            'tooltip' => 'Keyboard shortcut: C',
        ],
        'compose_email' => [
            'label' => 'Compose email',
        ],
        'undo' => [
            'label' => 'Undo',
        ],
        'cancel_send' => [
            'label' => 'Cancel send',
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
        'held' => [
            'title' => ':via queued an email',
            'body' => '":subject" goes to :recipients in :minutes. Workspace: :workspace. Cancel it if you did not ask for it.',
            'minutes' => ':count minute|:count minutes',
        ],
        'too_late' => [
            'title' => 'Too late, the email has already been sent',
        ],
        'not_found' => [
            'title' => 'That email could not be found',
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
