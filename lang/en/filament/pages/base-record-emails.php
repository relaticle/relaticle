<?php

declare(strict_types=1);

return [
    'manage_sharing' => [
        'label' => 'Sharing',
        'modal_heading' => 'Sharing settings',
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
            'already_pending' => [
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
];
