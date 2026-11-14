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
            'failed' => 'The AI provider could not generate a summary. Try again later.',
            'generated' => 'Generated :time',
            'copy' => 'Copy',
            'copied' => 'Copied',
        ],
        'request_access' => [
            'label' => 'Request access',
            'modal_heading' => 'Request access',
        ],
        'request_record_access' => [
            'label' => "Request :name's :count emails on :record",
            'pending' => 'Requested from :name',
            'more' => '+:count more',
            'modal_heading' => "Request :name's emails on :record",
        ],
        'approve_access_request' => [
            'modal_heading' => 'Approve access request',
        ],
        'deny_access_request' => [
            'modal_heading' => 'Deny access request',
        ],
    ],
    'fields' => [
        'access_note' => [
            'label' => 'Note (optional)',
            'placeholder' => 'Say why you need it, for example the opportunity you are working on.',
        ],
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
        'pending_record_request' => [
            'title' => 'You already have a pending request for this mail.',
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
    'automated_toggle' => '{1}Show 1 automated|[2,*]Show :count automated',
    'automated_toggle_hide' => 'Hide automated',
];
