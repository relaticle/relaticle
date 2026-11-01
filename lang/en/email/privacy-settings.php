<?php

declare(strict_types=1);

return [
    'sharing_confirmation' => [
        'heading' => 'Update sharing for existing emails?',
        'description' => 'Mail synced from now on uses this tier in every mailbox that follows the workspace default. A more private tier also updates emails already synced, except emails a member changed individually. New tier: :tier.',
        'mailbox_description' => 'This updates every email already synced from this mailbox. Emails you changed individually are left as they are. New tier: :tier.',
        'mailbox_full_access_description' => 'Full access shares the body, subject line, and attachments with everyone in your workspace. This applies to every email already synced from this mailbox. Emails you changed individually are left as they are.',
        'full_access_label' => 'Type ":phrase" to confirm',
        'phrase' => 'I understand',
        'phrase_mismatch' => 'Type ":phrase" exactly to confirm.',
    ],
];
