<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

/**
 * Validates the record's naming attribute at proposal time, so a missing,
 * blank, or over-length name fails as a tool error the model can correct
 * instead of a database error after the user already approved the card.
 */
trait GuardsRecordNames
{
    /**
     * The attribute that names a record of this entity.
     */
    protected function nameAttribute(): string
    {
        return 'name';
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  bool  $required  Create requires a name; update only validates one when passed.
     */
    protected function nameError(array $record, bool $required = false): ?string
    {
        $attribute = $this->nameAttribute();

        if (! array_key_exists((string) $attribute, $record)) {
            return $required ? "The {$attribute} is required." : null;
        }

        $value = $record[$attribute];

        if (! is_string($value) || trim($value) === '') {
            return $required ? "The {$attribute} is required." : "The {$attribute} cannot be empty.";
        }

        if (mb_strlen($value) > 255) {
            return "The {$attribute} may not be longer than 255 characters.";
        }

        return null;
    }
}
