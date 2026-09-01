<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Emails are stored and compared in one canonical form: trimmed, lowercase.
 * Postgres compares strings case-sensitively, so any path that skips this
 * splits one mailbox into two accounts or locks a user out of theirs.
 */
final readonly class EmailAddress
{
    public static function canonicalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
