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
        'scheduled' => [
            'title' => 'Email scheduled',
            'body' => 'Scheduled for :time (your time).',
        ],
        'cancelled' => [
            'title' => 'Send undone. Your draft is open.',
        ],
        'send_cancelled' => [
            'title' => 'Send cancelled',
        ],
        'held' => [
            'title' => ':via queued an email',
            'rescheduled_title' => ':via rescheduled an email',
            'body' => '":subject" goes to :recipients in :minutes. Workspace: :workspace. Cancel it if you did not ask for it.',
            'scheduled_body' => '":subject" goes to :recipients on :date. Workspace: :workspace. Cancel it if you did not ask for it.',
            'sent_body' => '":subject" was sent to :recipients. Workspace: :workspace.',
            'cancelled_body' => '":subject" to :recipients was cancelled and not sent. Workspace: :workspace.',
            'failed_body' => '":subject" to :recipients could not be sent. Workspace: :workspace.',
            'sending_body' => '":subject" is being sent to :recipients. Workspace: :workspace.',
            'waiting_body' => '":subject" to :recipients is waiting to send. Workspace: :workspace. Cancel it if you did not ask for it.',
            'minutes' => ':count minute|:count minutes',
        ],
        'too_late' => [
            'title' => 'Too late to undo. The send window has closed.',
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
