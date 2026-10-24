<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Workspace;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final readonly class ValidWorkspaceSlug implements ValidationRule
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
            $fail(__('workspaces.validation.slug_too_short'));

            return;
        }

        if (! preg_match(Workspace::SLUG_REGEX, $value)) {
            $fail(__('workspaces.validation.slug_format'));

            return;
        }

        if ($this->ignoreValue !== null && $value === $this->ignoreValue) {
            return;
        }

        if (in_array($value, Workspace::RESERVED_SLUGS, true)) {
            $fail(__('workspaces.validation.slug_reserved'));
        }
    }
}
