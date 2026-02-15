<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Relaticle\ImportWizard\Enums\NumberFormat;

/**
 * Validates that a value can be parsed as a number in the specified format.
 */
final readonly class ImportNumberRule implements ValidationRule
{
    public function __construct(
        private NumberFormat $format,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if ($this->format->parse($value) === null) {
            $fail(__('import-wizard-new::validation.invalid_number', [
                'format' => $this->format->getLabel(),
            ]));
        }
    }
}
