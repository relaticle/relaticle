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
    ],
    'notifications' => [
        'queued' => ['title' => 'Email queued for sending'],
    ],
];
