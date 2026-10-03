<?php

declare(strict_types=1);

return [
    'actions' => [
        'manage_sharing' => [
            'label' => 'Sharing',
            'modal_heading' => 'Sharing settings',
            'submit' => 'Save',
        ],
        'summarize_thread' => [
            'label' => 'Summarize thread',
            'modal_heading' => 'AI thread summary',
            'empty' => 'No summary is available for this thread.',
            'generated' => 'Generated :time',
            'copy' => 'Copy',
            'copied' => 'Copied',
        ],
        'request_access' => [
            'label' => 'Request access',
            'modal_heading' => 'Request access',
        ],
        'approve_access_request' => [
            'modal_heading' => 'Approve access request',
        ],
        'deny_access_request' => [
            'modal_heading' => 'Deny access request',
        ],
    ],
    'fields' => [
        'privacy_tier' => [
            'label' => 'Who can see this email?',
        ],
        'shares' => [
            'label' => 'Share with specific teammates',
        ],
        'shared_with' => [
            'label' => 'Teammate',
        ],
        'tier' => [
            'label' => 'Access level',
        ],
        'tier_requested' => [
            'label' => 'Access level requested',
        ],
    ],
    'empty' => [
        'heading' => 'No emails',
        'description' => 'This record doesn\'t have any emails, or they may be hidden due to permissions.',
        'compose' => 'Compose',
    ],
    'protected' => [
        'heading' => 'Nothing to show here',
        'description' => 'This record is protected. Its emails and meetings stay hidden here.',
    ],
    'blocked' => [
        'heading' => 'Nothing to show here',
        'description' => 'This record is blocked. Its emails and meetings stay hidden here.',
    ],

    'notifications' => [
        'sharing_saved' => [
            'title' => 'Sharing settings saved.',
        ],
        'pending_request' => [
            'title' => 'You already have a pending request for this email.',
        ],
        'access_request_sent' => [
            'title' => 'Access request sent.',
        ],
        'access_request_approved' => [
            'title' => 'Access request approved.',
        ],
        'access_request_denied' => [
            'title' => 'Access request denied.',
        ],
    ],
];
