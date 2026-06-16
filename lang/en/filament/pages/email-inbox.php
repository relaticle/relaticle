<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Emails',
    'compose' => [
        'label' => 'Compose',
        'notifications' => [
            'queued' => [
                'title' => 'Email queued',
                'body' => 'Your email is being sent.',
            ],
        ],
    ],
    'reply_forward' => [
        'modal_headings' => [
            'reply_all' => 'Reply All',
            'forward' => 'Forward',
            'reply' => 'Reply',
        ],
        'notifications' => [
            'queued' => [
                'title' => 'Email queued',
            ],
        ],
    ],
    'sharing' => [
        'label' => 'Sharing',
        'modal_heading' => 'Sharing settings',
        'fields' => [
            'privacy_tier' => [
                'label' => 'Who can see this email?',
            ],
            'shares' => [
                'label' => 'Share with specific teammates',
                'add_action_label' => 'Add teammate',
            ],
            'shared_with' => [
                'label' => 'Teammate',
            ],
            'tier' => [
                'label' => 'Access level',
            ],
        ],
        'notifications' => [
            'saved' => [
                'title' => 'Sharing settings saved.',
            ],
        ],
    ],
    'summarize_thread' => [
        'label' => 'Summarize Thread',
        'modal_heading' => 'AI Thread Summary',
    ],
    'request_access' => [
        'label' => 'Request Access',
        'fields' => [
            'tier_requested' => [
                'label' => 'Access level requested',
            ],
        ],
        'notifications' => [
            'pending' => [
                'title' => 'You already have a pending request for this email.',
            ],
            'sent' => [
                'title' => 'Access request sent.',
            ],
        ],
    ],
    'approve_access_request' => [
        'modal_heading' => 'Approve access request',
        'notifications' => [
            'approved' => [
                'title' => 'Access request approved.',
            ],
        ],
    ],
    'deny_access_request' => [
        'modal_heading' => 'Deny access request',
        'notifications' => [
            'denied' => [
                'title' => 'Access request denied.',
            ],
        ],
    ],
    'compose_form' => [
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
        'privacy' => [
            'label' => 'Who can see this email?',
            'helper_text' => 'Defaults to your team or personal sharing setting.',
        ],
        'signature' => [
            'label' => 'Signature',
            'placeholder' => 'No signature',
        ],
        'settings' => [
            'description' => 'Privacy and signature options for this email.',
        ],
    ],
    'reply_form' => [
        'from' => [
            'label' => 'From',
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
        'message' => [
            'label' => 'Message',
        ],
        'privacy' => [
            'label' => 'Who can see this email?',
            'helper_text' => 'Defaults to your team or personal sharing setting.',
        ],
    ],
];
