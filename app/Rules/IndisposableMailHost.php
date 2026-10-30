<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Email\MailExchangers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Temp-mail services rotate domains faster than any domain list, but every domain
 * they hand out routes to their own mail server.
 */
final readonly class IndisposableMailHost implements ValidationRule
{
    /** @var list<string> */
    private const array DISPOSABLE_HOSTS = [
        'neighbours.sh',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = Str::afterLast(is_string($value) ? $value : '', '@');

        if ($domain === '') {
            return;
        }

        foreach (resolve(MailExchangers::class)->hostsFor($domain) as $host) {
            if ($this->isDisposable($host)) {
                $fail(__('validation.indisposable'));

                return;
            }
        }
    }

    private function isDisposable(string $host): bool
    {
        return array_any(self::DISPOSABLE_HOSTS, fn (string $disposableHost): bool => $host === $disposableHost || str_ends_with($host, ".{$disposableHost}"));
    }
}
