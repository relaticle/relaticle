<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Emails',
    'health' => [
        'nav_badge' => '!',
        'nav_tooltip' => 'One of your mailboxes needs attention',
    ],
    'filters' => [
        'mailbox' => 'Mailbox',
        'from' => 'From',
        'to' => 'To',
        'direction' => 'Direction',
        'has_attachment' => 'Has attachment',
        'sent_at' => 'Date',
        'record' => 'Linked to',
        'category' => 'Category',
        'label' => 'Filters',
        'chip' => ':filter: :value',
        'apply' => 'Apply',
        'remove' => 'Remove the :filter filter',
        'edit' => 'Edit the :filter filter as text',
        'clear_all' => 'Clear all',
        'yes' => 'Yes',
        'no' => 'No',
        'after' => 'after :date',
        'before' => 'before :date',
        'starts_with' => 'starting with :name',
        'count' => '{1}1 selected|[2,*]:count selected',
        'one_record_type' => 'Pick records of one type: companies, people or opportunities.',
        'ignored' => 'The filter in this link was ignored. :reason',
        'fields' => [
            'mailboxes' => 'Mailboxes',
            'after' => 'On or after',
            'before' => 'Before',
            'records' => 'Records',
            'categories' => 'Categories',
        ],
    ],
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
    'categories' => [
        'scheduling' => 'Scheduling',
        'marketing' => 'Marketing',
        'invoice' => 'Invoice',
        'support' => 'Support',
        'sales' => 'Sales',
        'personal' => 'Personal',
        'other' => 'Other',
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
    'automated_reasons' => [
        'list' => 'Mailing list',
        'auto_submitted' => 'Automatic reply',
        'bulk' => 'Bulk mail',
        'role_sender' => 'Sent from a role address like no-reply or marketing',
        'provider_category' => 'Filed under a Gmail category',
    ],
    'views' => [
        'needs_reply' => 'Needs reply',
        'awaiting_reply' => 'Awaiting reply',
        'all' => 'All',
        'unlinked' => 'Unlinked',
        'automated' => 'Automated',
        'newer' => 'Newer',
        'older' => 'Older',
        'no_match' => 'No conversations match.',
        'no_match_hint' => 'Search covers subjects and bodies you can read, and every name and address.',
        'empty' => [
            'needs_reply' => 'Nothing is waiting on you.',
            'awaiting_reply' => 'No one owes you a reply.',
            'all' => 'No conversations yet.',
            'unlinked' => 'Every conversation is linked to a record.',
            'automated' => 'No automated mail.',
        ],
    ],
    'conversation_row' => [
        'me' => 'Me',
        'unread' => 'Unread',
        'unlinked' => 'Unlinked',
        'more_records' => '+:count',
    ],
    'drawer' => [
        'reply' => 'Reply',
        'source' => 'Visible through :name\'s mailbox',
        'previous' => 'Previous conversation',
        'next' => 'Next conversation',
        'resize' => 'Resize the reader',
        'resize_value' => ':width%',
        'not_available' => 'This email is not available to you.',
        'gate' => [
            'metadata_only' => [
                'heading' => 'You can see who and when',
                'description' => ':name shares only who wrote this email and when.',
            ],
            'subject' => [
                'heading' => 'You can see the subject',
                'description' => ':name shares only the subject of this email.',
            ],
            'request' => 'Request access',
            'requested' => 'Requested',
        ],
    ],
];
