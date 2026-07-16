<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Access Requests',
    'tabs' => [
        'aria' => 'Access request tabs',
        'incoming' => 'Incoming',
        'outgoing' => 'Sent',
    ],
    'filters' => [
        'all' => 'All',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'denied' => 'Denied',
    ],
    'search' => [
        'placeholder' => 'Search by name or subject…',
    ],
    'request' => [
        'requested_incoming' => 'requested access',
        'requested_outgoing' => 'you requested access',
        'unknown_user' => 'Unknown user',
        'no_subject' => '(No subject)',
        'email_unavailable' => 'The associated email is no longer available.',
    ],
    'empty' => [
        'filtered_heading' => 'No :status requests',
        'filtered_description' => 'Try a different filter or clear the active one.',
        'show_all' => 'Show all',
        'incoming_heading' => 'No incoming requests',
        'outgoing_heading' => 'No sent requests',
        'incoming_description' => 'When someone asks for access to one of your private emails, it will show up here.',
        'outgoing_description' => "You haven't asked for access to any emails yet.",
    ],
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
