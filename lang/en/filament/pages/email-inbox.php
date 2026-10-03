<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Emails',
    'account_filter' => [
        'label' => 'Account',
    ],
    'tabs' => [
        'drafts' => 'Drafts',
        'outbox' => 'Outbox',
        'failed' => 'Failed',
        'templates' => 'Templates',
    ],
    'drafts' => [
        'columns' => [
            'subject' => 'Draft',
            'last_edited' => 'Last edited',
        ],
        'actions' => [
            'open' => 'Continue writing',
            'delete' => 'Delete draft',
            'delete_selected' => 'Delete drafts',
        ],
        'empty' => [
            'heading' => 'No drafts',
            'description' => 'Messages you close before sending are saved here.',
        ],
        'notifications' => [
            'deleted' => 'Draft deleted',
            'bulk_deleted' => '{1}1 draft deleted|[2,*]:count drafts deleted',
        ],
    ],
    'outbox' => [
        'empty' => [
            'heading' => 'No emails in the outbox',
            'description' => 'Queued and scheduled emails appear here until they send.',
        ],
    ],
    'failed' => [
        'empty' => [
            'heading' => 'No failed emails',
            'description' => 'Emails that could not be delivered will appear here.',
        ],
    ],
    'search' => [
        'placeholder' => 'Search emails…',
        'clear' => 'Clear search',
    ],
    'subject' => [
        'none' => '(no subject)',
        'hidden' => '(subject hidden)',
    ],
    'pagination' => [
        'previous' => 'Previous',
        'next' => 'Next',
        'range' => ':first–:last of :total',
    ],
    'list_empty' => [
        'no_results' => 'No results for ":search"',
        'all' => 'No emails',
        'sent' => 'No sent emails',
        'inbox' => 'No received emails',
    ],
    'list_row' => [
        'via' => 'via :name',
        'via_mailboxes' => '{1}via 1 mailbox|[2,*]via :count mailboxes',
        'access_granted_via' => 'Access granted via',
        'timestamp_yesterday' => 'Yesterday, :time',
        'request_access' => 'Request access from :name',
        'requested' => 'Requested',
        'opening' => 'Opening…',
    ],
    'pending_access' => [
        'heading' => '{1}1 pending access request|[2,*]:count pending access requests',
        'unknown_user' => 'Unknown user',
        'approve' => 'Approve',
        'deny' => 'Deny',
    ],
    'compose' => [
        'label' => 'Compose',
        'notifications' => [
            'queued' => [
                'title' => 'Email queued',
                'body' => 'Your email is being sent.',
            ],
        ],
    ],
    'privacy_gate' => [
        'metadata_only' => [
            'heading' => 'Email body and subject are restricted',
            'description' => 'You can see participant and date information. Request access to view the subject and body.',
        ],
        'subject_only' => [
            'heading' => 'Email body is restricted',
            'description' => 'You can see the subject line. The full email body is hidden. Request access to see more.',
        ],
        'private' => [
            'heading' => 'This email is private',
            'description' => 'Only the email owner can view this content.',
        ],
        'request_hint' => 'Select :action above to ask for more access.',
        'request_pending' => 'Your access request is pending.',
    ],
    'reader' => [
        'heading' => 'View email',
        'internal' => 'Internal email. Only workspace members can see it.',
        'unknown_sender' => '(unknown sender)',
        'no_body' => '(no message body)',
        'attachments' => [
            'unnamed' => 'Unnamed file',
            'processing' => 'processing…',
        ],
    ],
    'back_to_list' => 'Back to list',
    'recipients' => [
        'from' => 'From',
        'to' => 'to',
        'to_heading' => 'To',
        'cc' => 'cc',
        'cc_heading' => 'Cc',
        'more' => '{1}and 1 more|[2,*]and :count more',
        'details' => 'Sender and recipients',
    ],
    'row_actions' => [
        'label' => 'Email actions',
    ],
    'mark_all_read' => [
        'label' => 'Mark all read',
    ],
    'reply_forward' => [
        'modal_headings' => [
            'reply_all' => 'Reply all',
            'forward' => 'Forward',
            'reply' => 'Reply',
        ],
        'notifications' => [
            'queued' => [
                'title' => 'Email queued',
            ],
        ],
    ],
    'sharing' => [
        'label' => 'Sharing',
        'modal_heading' => 'Sharing settings',
        'fields' => [
            'privacy_tier' => [
                'label' => 'Who can see this email?',
            ],
            'shares' => [
                'label' => 'Share with specific teammates',
                'description' => 'Give named people more access than the setting above allows.',
                'add_action_label' => 'Add teammate',
                'new_item' => 'New teammate',
            ],
            'shared_with' => [
                'label' => 'Teammate',
                'placeholder' => 'Choose a teammate…',
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
        'label' => 'Request access',
        'fields' => [
            'tier_requested' => [
                'label' => 'Access level requested',
            ],
        ],
        'notifications' => [
            'pending' => [
                'title' => 'You already have a pending request for this email.',
            ],
            'sent' => [
                'title' => 'Access request sent.',
            ],
        ],
    ],
    'compose_form' => [
        'signature' => [
            'label' => 'Signature',
        ],
    ],
];
