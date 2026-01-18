<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Data;

use Spatie\LaravelData\Data;

/**
 * Result of data type inference for a CSV column.
 */
final class InferenceResult extends Data
{
    /**
     * @param  string|null  $type  Detected type: email, phone, date, number, url, currency, or null
     * @param  float  $confidence  Confidence level 0.0 - 1.0
     * @param  array<string>  $suggestedFields  Field keys that match this type
     */
    public function __construct(
        public readonly ?string $type,
        public readonly float $confidence,
        public readonly array $suggestedFields = [],
    ) {}
}
