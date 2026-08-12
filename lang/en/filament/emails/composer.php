<?php

declare(strict_types=1);

return [
    'title' => 'New email',
    'fields' => [
        'from' => 'From',
        'to' => 'To',
        'cc' => 'CC',
        'bcc' => 'BCC',
        'subject' => 'Subject',
        'message' => 'Message',
        'signature_none' => 'No signature',
        'template_none' => 'No templates yet',
        'subject_placeholder' => 'Add a subject',
        'body_placeholder' => 'Write your message…',
    ],
    'actions' => [
        'send' => 'Send email',
        'attach' => 'Attach files',
        'signature' => 'Signature',
        'template' => 'Use template',
        'variable' => 'Insert variable',
        'remove_recipient' => 'Remove',
        'remove_attachment' => 'Remove attachment',
        'uploading' => 'Uploading…',
        'expand' => 'Fit to screen',
        'shrink' => 'Exit full screen',
        'minimize' => 'Minimize',
        'restore' => 'Restore',
        'close' => 'Close',
    ],
    'notifications' => [
        'queued' => ['title' => 'Email queued for sending'],
        'attachments_not_saved' => [
            'title' => 'Attachments won\'t be saved',
            'body' => 'Drafts don\'t keep attached files yet — closing now will discard them. Send the email to keep the attachments.',
        ],
        'draft_account_disconnected' => [
            'title' => 'Original account no longer connected',
            'body' => 'The account this draft was written from isn\'t connected anymore, so it\'s been switched to your default account. Double-check the sender before sending.',
        ],
    ],
    'validation' => [
        'body_required' => 'Write a message before sending.',
    ],
];
