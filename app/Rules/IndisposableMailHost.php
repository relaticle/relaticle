<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Email\MailExchangers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

// Temp-mail services rotate domains faster than any domain list can follow,
// but every domain they hand out routes to their own mail server.
final readonly class IndisposableMailHost implements ValidationRule
{
    /** @var list<string> */
    private const array DISPOSABLE_HOSTS = [
        'neighbours.sh',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $hosts = resolve(MailExchangers::class)->hostsFor(Str::afterLast(is_string($value) ? $value : '', '@'));

        if (array_any($hosts, $this->isDisposable(...))) {
            $fail(__('validation.indisposable'));
        }
    }

    private function isDisposable(string $host): bool
    {
        return array_any(self::DISPOSABLE_HOSTS, fn (string $disposableHost): bool => str_ends_with(".{$host}", ".{$disposableHost}"));
    }
}
