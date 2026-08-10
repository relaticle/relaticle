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
        'signature_none' => 'No signature',
    ],
    'actions' => [
        'send' => 'Send email',
        'attach' => 'Attach files',
        'signature' => 'Signature',
        'remove_recipient' => 'Remove',
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
