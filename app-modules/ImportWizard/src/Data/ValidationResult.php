<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Carbon\Carbon;
use Spatie\LaravelData\Data;

/**
 * Represents the result of validating a value.
 *
 * Unified response type for all validation operations.
 */
final class ValidationResult extends Data
{
    public function __construct(
        public readonly bool $isValid,
        public readonly ?Carbon $parsed = null,
        public readonly ?ValueIssue $issue = null,
        public readonly bool $isAmbiguous = false,
    ) {}

    /**
     * Create a valid result with no issues.
     */
    public static function valid(?Carbon $parsed = null): self
    {
        return new self(
            isValid: true,
            parsed: $parsed,
            issue: null,
            isAmbiguous: false,
        );
    }

    /**
     * Create a valid result that is ambiguous (warning).
     */
    public static function ambiguous(Carbon $parsed, ValueIssue $issue): self
    {
        return new self(
            isValid: true,
            parsed: $parsed,
            issue: $issue,
            isAmbiguous: true,
        );
    }

    /**
     * Create an invalid result with an error.
     */
    public static function invalid(ValueIssue $issue): self
    {
        return new self(
            isValid: false,
            parsed: null,
            issue: $issue,
            isAmbiguous: false,
        );
    }

    /**
     * Check if this result has any issue (error or warning).
     */
    public function hasIssue(): bool
    {
        return $this->issue instanceof \Relaticle\ImportWizard\Data\ValueIssue;
    }

    /**
     * Check if this result has an error (not just a warning).
     */
    public function hasError(): bool
    {
        return ! $this->isValid && $this->issue instanceof \Relaticle\ImportWizard\Data\ValueIssue;
    }

    /**
     * Check if this result has a warning (but is still valid).
     */
    public function hasWarning(): bool
    {
        return $this->isValid && $this->issue instanceof \Relaticle\ImportWizard\Data\ValueIssue;
    }

    /**
     * Get the issue as an array for JSON responses.
     *
     * @return array<string, mixed>|null
     */
    public function issueToArray(): ?array
    {
        return $this->issue?->toArray();
    }
}
