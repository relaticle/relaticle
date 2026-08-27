<?php

declare(strict_types=1);

namespace App\Rules;

/**
 * The canonical validation rule set for any caller-supplied email address that
 * can lead to an account.
 *
 * The DNS check is opt-out because it performs a live lookup: registration can
 * afford it, but the invitation and social paths sit in request paths where a
 * lookup buys nothing — every disposable provider publishes valid MX records.
 */
final readonly class RegistrableEmail
{
    /**
     * @return list<string>
     */
    public static function rules(bool $checkDns = true): array
    {
        return [
            $checkDns ? 'email:rfc,dns' : 'email:rfc',
            'indisposable',
        ];
    }
}
