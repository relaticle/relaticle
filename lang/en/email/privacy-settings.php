<?php

declare(strict_types=1);

return [
    'sharing_confirmation' => [
        'heading' => 'Update sharing for existing emails?',
        'description' => 'This updates every synced email that still follows your sharing default. Emails you changed individually are left as they are. New tier: :tier.',
        'full_access_description' => 'Full access shares the body, subject line, and attachments with everyone in your workspace. This applies to every synced email that still follows your sharing default. Emails you changed individually are left as they are.',
        'mailbox_description' => 'This updates every email already synced from this mailbox. Emails you changed individually are left as they are. New tier: :tier.',
        'mailbox_full_access_description' => 'Full access shares the body, subject line, and attachments with everyone in your workspace. This applies to every email already synced from this mailbox. Emails you changed individually are left as they are.',
        'full_access_label' => 'Type ":phrase" to confirm',
        'phrase' => 'I understand',
        'phrase_mismatch' => 'Type ":phrase" exactly to confirm.',
    ],
];
