<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Access requests',
    'tabs' => [
        'aria' => 'Access request tabs',
        'incoming' => 'Incoming',
        'outgoing' => 'Sent',
    ],
    'filters' => [
        'label' => 'Status',
        'all' => 'All',
    ],
    'statuses' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'denied' => 'Denied',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ],
    'columns' => [
        'requested_by' => 'Requested by',
        'sent_to' => 'Sent to',
        'email' => 'Email',
        'access' => 'Access',
        'requested' => 'Requested',
        'all_on_record' => 'All emails on :record',
    ],
    'search' => [
        'placeholder' => 'Search by name or subject…',
    ],
    'request' => [
        'requested_incoming' => 'requested access',
        'requested_outgoing' => 'you requested access',
        'unknown_user' => 'Unknown user',
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
        'open_email' => 'Open email',
        'approve' => [
            'label' => 'Approve',
            'modal_heading' => 'Approve access request',
            'modal_description' => 'Grant :name access to this email?',
            'modal_description_record' => 'Grant :name access to every email of yours on :record? Mail that arrives later stays as it is.',
            'modal_submit_label' => 'Approve',
        ],
        'share_record' => [
            'label' => 'Share all my mail on :record',
            'label_many' => 'Share all my mail on a record',
            'modal_heading' => 'Share all your mail on a record',
            'modal_description' => ':name gets every email of yours on the record you pick, at the access they asked for. Mail that arrives later stays as it is.',
            'modal_submit_label' => 'Share',
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
        'no_longer_pending' => 'This request is no longer pending.',
    ],
];
