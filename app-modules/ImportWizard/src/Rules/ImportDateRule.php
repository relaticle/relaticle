<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Relaticle\ImportWizard\Enums\DateFormat;

/**
 * Validates that a value can be parsed as a date using the specified format.
 *
 * Uses DateFormat::parse() which handles ambiguous formats (European vs American),
 * 2-digit years, and multiple format variations - something Laravel's built-in
 * date validation cannot do correctly for imports.
 */
final readonly class ImportDateRule implements ValidationRule
{
    public function __construct(
        private DateFormat $format,
    ) {}

    /**
     * Run the validation rule.
     *
     * ValueValidator guarantees non-empty string; keep type check only.
     *
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! $this->format->parse($value) instanceof \Carbon\Carbon) {
            $fail(__('import-wizard-new::validation.invalid_date', [
                'format' => $this->format->getLabel(),
            ]));
        }
    }
}
