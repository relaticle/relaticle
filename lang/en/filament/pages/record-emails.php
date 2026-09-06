<?php

declare(strict_types=1);

return [
    'title' => 'Emails',
    'breadcrumb' => 'Emails',

    'actions' => [
        'manage_sharing' => [
            'label' => 'Sharing',
            'modal_heading' => 'Sharing settings',
            'submit' => 'Save',
        ],
        'summarize_thread' => [
            'label' => 'Summarize Thread',
            'modal_heading' => 'AI Thread Summary',
        ],
        'request_access' => [
            'label' => 'Request Access',
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
        'description' => 'This record doesn\'t have any emails, or they may be hidden due to permissions.',
        'compose' => 'Compose email',
    ],
    'protected' => [
        'heading' => 'Nothing to show here',
        'description' => 'This record is Protected. Communications stay hidden on this page to protect their privacy.',
    ],
    'blocked' => [
        'heading' => 'Nothing to show here',
        'description' => 'This record is Blocked. Communications stay hidden on this page.',
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
