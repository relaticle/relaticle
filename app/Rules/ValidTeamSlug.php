<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Team;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final readonly class ValidTeamSlug implements ValidationRule
{
    public function __construct(
        private ?string $ignoreValue = null,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;

        if (mb_strlen($value) < 3) {
            $fail('The :attribute must be at least 3 characters.');

            return;
        }

        if (! preg_match(Team::SLUG_REGEX, $value)) {
            $fail('The :attribute may only contain lowercase letters, numbers, and hyphens.');

            return;
        }

        if ($this->ignoreValue !== null && $value === $this->ignoreValue) {
            return;
        }

        if (in_array($value, Team::RESERVED_SLUGS, true)) {
            $fail('The :attribute is reserved and cannot be used.');
        }
    }
}
