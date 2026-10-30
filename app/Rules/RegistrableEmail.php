<?php

declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The canonical validation rule set for any caller-supplied email address that
 * can lead to an account.
 *
 * The DNS check is opt-out because it performs a live lookup: registration can
 * afford it, but the invitation and social paths sit in request paths where a
 * lookup buys nothing. Every disposable provider publishes valid MX records.
 */
final readonly class RegistrableEmail
{
    /**
     * @return list<string|ValidationRule>
     */
    public static function rules(bool $checkDns = true): array
    {
        if (! $checkDns) {
            return ['email:rfc', 'indisposable'];
        }

        return ['email:rfc,dns', 'indisposable', new IndisposableMailHost];
    }
}
