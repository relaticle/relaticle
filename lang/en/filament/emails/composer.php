<?php

declare(strict_types=1);

return [
    'title' => 'New email',
    'draft' => 'Draft',
    'quoted' => [
        'hidden' => 'The original message is not shared with you.',
    ],
    'fields' => [
        'from' => 'From',
        'to' => 'To',
        'cc' => 'CC',
        'bcc' => 'BCC',
        'subject' => 'Subject',
        'message' => 'Message',
        'signature_none' => 'No signature',
        'signature_name' => 'Signature name',
        'signature_content' => 'Signature',
        'signature_default' => 'Use as my default signature',
        'template_none' => 'No templates yet',
        'template_name' => 'Template name',
        'template_shared' => 'Share with my team',
        'subject_placeholder' => 'Add a subject',
        'body_placeholder' => 'Write your message…',
        'company_team' => 'Company team',
        'company_team_count' => 'Team',
    ],
    'toolbar' => [
        'paragraph' => 'Paragraph',
        'alignment' => 'Alignment',
    ],

    'mass_send' => [
        'summary' => 'Sending individual emails to :count recipients',
        'toggle' => 'Mass sending',
        'send_button' => 'Send emails (:count)',
        'add_recipients' => 'Add recipients',
        'outbox_hint' => 'Delivery time will depend on items in your outbox.',
        'view_outbox' => 'View outbox',
        'no_recipients' => 'Add at least one recipient before sending.',
    ],
    'actions' => [
        'send' => 'Send email',
        'attach' => 'Attach files',
        'signature' => 'Signature',
        'template' => 'Use template',
        'create_signature' => 'New signature',
        'create_template' => 'Save as template',
        'variable' => 'Insert variable',
        'remove_recipient' => 'Remove',
        'download_attachment' => 'Download attachment',
        'remove_attachment' => 'Remove attachment',
        'uploading' => 'Uploading…',
        'expand' => 'Fit to screen',
        'shrink' => 'Exit full screen',
        'minimize' => 'Minimize',
        'restore' => 'Restore',
        'close' => 'Close',
        'discard' => 'Discard draft',
        'grant_send' => [
            'label' => 'Grant permission',
        ],
    ],
    'grant_send' => [
        'heading' => '":email" does not have permission to send emails.',
        'heading_generic' => 'This mailbox does not have permission to send emails.',
        'description' => 'Please grant permission to enable email sending.',
    ],
    'notifications' => [
        'mass_queued' => [
            'title' => 'Mass email queued',
            'body' => 'Sending to :count recipient(s).',
        ],
        'queued' => ['title' => 'Email queued for sending'],
        'signature_created' => ['title' => 'Signature created'],
        'template_created' => ['title' => 'Template saved'],
        'attachment_too_large' => [
            'title' => 'Some files were too large',
            'body' => 'Not attached: :files. Each file must be under :max, and all attachments together under :total.',
        ],
        'attachment_unavailable' => [
            'title' => 'Some attachments could not be included',
            'body' => 'Not attached: :files. Download them from the original email and add them here if you still need them.',
        ],
        'send_attachment_unavailable' => [
            'title' => 'Could not include some attachments',
            'body' => 'The email was not sent. These files could not be downloaded: :files. Remove them, or try sending again.',
        ],
        'draft_account_disconnected' => [
            'title' => 'Original account no longer connected',
            'body' => 'The account this draft was written from isn\'t connected anymore, so it\'s been switched to your default account. Double-check the sender before sending.',
        ],
        'draft_saved' => [
            'title' => 'Draft saved',
        ],
    ],
    'validation' => [
        'body_required' => 'Write a message before sending.',
        'recipient_not_allowed' => 'Choose a recipient from your workspace records or suggested addresses.',
    ],
];
