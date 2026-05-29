<?php

declare(strict_types=1);

return [
    'actions' => [
        'share_all' => [
            'label' => 'Share my emails',
            'modal_heading' => 'Share my emails on this record',
        ],
        'view' => [
            'modal_heading' => 'Email details',
        ],
        'summarize_thread' => [
            'label' => 'Summarize Thread',
            'modal_heading' => 'AI Thread Summary',
        ],
        'manage_sharing' => [
            'label' => 'Sharing',
            'modal_heading' => 'Sharing settings',
        ],
        'request_access' => [
            'label' => 'Request Access',
        ],
    ],
    'fields' => [
        'privacy_tier_all' => [
            'label' => 'Who can see these emails?',
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
    'columns' => [
        'subject' => [
            'label' => 'Subject',
        ],
        'from_address' => [
            'label' => 'From',
        ],
        'ai_label' => [
            'label' => 'Label',
        ],
        'direction' => [
            'label' => 'Direction',
        ],
        'sent_at' => [
            'label' => 'Date',
        ],
        'privacy_tier' => [
            'label' => 'Visibility',
        ],
        'is_internal' => [
            'label' => 'Internal',
        ],
    ],
    'notifications' => [
        'sharing_saved_all' => [
            'title' => 'Sharing settings saved for all your emails on this record.',
        ],
        'sharing_saved' => [
            'title' => 'Sharing settings saved.',
        ],
        'pending_request' => [
            'title' => 'You already have a pending request for this email.',
        ],
        'access_request_sent' => [
            'title' => 'Access request sent.',
        ],
    ],
];
