<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support\Validation;

/**
 * Value object representing a validation error.
 *
 * Handles both simple error messages and per-item errors for multi-value fields.
 * Provides clean serialization/deserialization for database storage.
 */
final readonly class ValidationError
{
    /**
     * @param  string|null  $message  Simple error message
     * @param  array<string, string>  $itemErrors  Per-item errors for multi-value fields
     */
    private function __construct(
        private ?string $message = null,
        private array $itemErrors = [],
    ) {}

    /**
     * Create a simple error message.
     */
    public static function message(string $message): self
    {
        return new self(message: $message);
    }

    /**
     * Create per-item errors for multi-value fields.
     *
     * @param  array<string, string>  $errors  Map of value => error message
     */
    public static function itemErrors(array $errors): self
    {
        return new self(itemErrors: $errors);
    }

    /**
     * Create from database storage format.
     *
     * Handles both JSON (per-item errors) and plain strings (simple message).
     */
    public static function fromStorageFormat(?string $stored): ?self
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $decoded = json_decode($stored, true);

        if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
            return new self(itemErrors: $decoded);
        }

        return new self(message: $stored);
    }

    /**
     * Convert to database storage format.
     *
     * Returns JSON for per-item errors, plain string for simple message.
     */
    public function toStorageFormat(): string
    {
        if ($this->itemErrors !== []) {
            return json_encode($this->itemErrors, JSON_THROW_ON_ERROR);
        }

        return $this->message ?? '';
    }

    public function hasItemErrors(): bool
    {
        return $this->itemErrors !== [];
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @return array<string, string>
     */
    public function getItemErrors(): array
    {
        return $this->itemErrors;
    }
}
