<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Access Requests',
        'group' => 'Emails',
    ],

    'open_email' => [
        'label' => 'Open in inbox',
    ],

    'approve_access_request' => [
        'label' => 'Approve',
        'modal_heading' => 'Approve access request',
        'notifications' => [
            'approved' => [
                'title' => 'Access request approved.',
            ],
        ],
    ],

    'deny_access_request' => [
        'label' => 'Deny',
        'modal_heading' => 'Deny access request',
        'notifications' => [
            'denied' => [
                'title' => 'Access request denied.',
            ],
        ],
    ],

    'cancel_access_request' => [
        'label' => 'Cancel request',
        'modal_heading' => 'Cancel access request',
        'notifications' => [
            'cancelled' => [
                'title' => 'Access request cancelled.',
            ],
        ],
    ],
];
