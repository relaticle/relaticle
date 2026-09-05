<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Emails',
    'account_filter' => [
        'label' => 'Account',
        'all' => 'All accounts',
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
            'no_subject' => 'No subject',
            'recipients' => 'To',
            'no_recipients' => 'No recipients',
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
    'folders' => [
        'all' => 'All',
        'inbox' => 'Inbox',
        'sent' => 'Sent',
    ],
    'search' => [
        'placeholder' => 'Search emails…',
    ],
    'unread_label' => ':count unread',
    'pagination' => [
        'previous' => 'Prev',
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
        'timestamp_yesterday' => 'Yesterday, :time',
        'request_access' => 'Request access from :name',
        'requested' => 'Requested',
    ],
    'detail_empty' => [
        'heading' => 'Select an email to read',
        'description' => 'Choose a message from the list on the left',
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
        'request_hint' => 'Select :action on the email list to ask for expanded access.',
        'request_pending' => 'Your access request is pending.',
    ],
    'reader' => [
        'heading' => 'View email',
        'attachments' => [
            'unnamed' => 'Unnamed file',
            'processing' => 'processing…',
        ],
    ],
    'back_to_list' => 'Back to list',
    'recipients' => [
        'to' => 'to',
        'cc' => 'cc',
        'more' => '{1}and 1 more|[2,*]and :count more',
    ],
    'row_actions' => [
        'label' => 'Email actions',
    ],
    'mark_all_read' => [
        'label' => 'Mark all read',
        'notification' => '{0}No unread emails to mark|{1}1 email marked as read|[2,*]:count emails marked as read',
    ],
    'reply_forward' => [
        'modal_headings' => [
            'reply_all' => 'Reply All',
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
            'pending' => [
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
    'compose_form' => [
        'from' => [
            'label' => 'From',
        ],
        'template' => [
            'label' => 'Template',
            'placeholder' => 'Apply a template…',
        ],
        'to' => [
            'label' => 'To',
            'placeholder' => 'email@example.com',
        ],
        'cc' => [
            'label' => 'CC',
            'placeholder' => 'email@example.com',
        ],
        'bcc' => [
            'label' => 'BCC',
            'placeholder' => 'email@example.com',
        ],
        'body' => [
            'label' => 'Body',
        ],
        'privacy' => [
            'label' => 'Who can see this email?',
            'helper_text' => 'Defaults to your team or personal sharing setting.',
        ],
        'signature' => [
            'label' => 'Signature',
            'placeholder' => 'No signature',
        ],
        'settings' => [
            'description' => 'Privacy and signature options for this email.',
        ],
    ],
    'reply_form' => [
        'from' => [
            'label' => 'From',
        ],
        'to' => [
            'label' => 'To',
            'placeholder' => 'email@example.com',
        ],
        'cc' => [
            'label' => 'CC',
            'placeholder' => 'email@example.com',
        ],
        'bcc' => [
            'label' => 'BCC',
            'placeholder' => 'email@example.com',
        ],
        'message' => [
            'label' => 'Message',
        ],
        'privacy' => [
            'label' => 'Who can see this email?',
            'helper_text' => 'Defaults to your team or personal sharing setting.',
        ],
    ],
];
