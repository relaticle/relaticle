<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Access Requests',
    'actions' => [
        'open_email' => 'Open in inbox',
        'approve' => [
            'label' => 'Approve',
            'modal_heading' => 'Approve access request',
            'modal_description' => 'Grant :name access to this email?',
            'modal_submit_label' => 'Approve',
        ],
        'deny' => [
            'label' => 'Deny',
            'modal_heading' => 'Deny access request',
            'modal_description' => "Deny :name's access request?",
            'modal_submit_label' => 'Deny',
        ],
        'cancel' => [
            'label' => 'Cancel request',
            'modal_heading' => 'Cancel access request',
            'modal_description' => "Withdraw your request for access to :name's email?",
            'modal_submit_label' => 'Cancel request',
        ],
        'fallback_user' => 'this user',
    ],
    'notifications' => [
        'approved' => 'Access request approved.',
        'denied' => 'Access request denied.',
        'cancelled' => 'Access request cancelled.',
    ],
];
