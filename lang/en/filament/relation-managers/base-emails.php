<?php

declare(strict_types=1);

return [
    'share_all_on_record' => [
        'label' => 'Share my emails',
        'modal_heading' => 'Share my emails on this record',
        'fields' => [
            'privacy_tier' => [
                'label' => 'Who can see these emails?',
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
                'title' => 'Sharing settings saved for all your emails on this record.',
            ],
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
];
