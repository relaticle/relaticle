<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a value matches one of the available choice options.
 *
 * Performs case-insensitive matching and supports multi-select fields
 * where values are comma-separated.
 */
final readonly class ImportChoiceRule implements ValidationRule
{
    /**
     * @param  array<int, array{value: string, label: string}>  $options  Available choice options
     * @param  bool  $isMulti  Whether multiple selections are allowed (comma-separated)
     */
    public function __construct(
        private array $options,
        private bool $isMulti = false,
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

        $validValues = array_map(
            fn (array $option): string => mb_strtolower($option['value']),
            $this->options,
        );

        $values = $this->isMulti
            ? array_map(trim(...), explode(',', $value))
            : [$value];

        foreach ($values as $v) {
            if ($v !== '' && ! in_array(mb_strtolower($v), $validValues, true)) {
                $fail(__('import-wizard-new::validation.invalid_choice', [
                    'value' => $v,
                ]));

                return;
            }
        }
    }
}
