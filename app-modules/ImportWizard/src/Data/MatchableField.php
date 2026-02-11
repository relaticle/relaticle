<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Relaticle\ImportWizard\Enums\MatchBehavior;
use Spatie\LaravelData\Data;

final class MatchableField extends Data
{
    public function __construct(
        public readonly string $field,
        public readonly string $label,
        public readonly int $priority = 0,
        public readonly ?MatchBehavior $behavior = null,
    ) {}

    public static function id(): self
    {
        return new self(
            field: 'id',
            label: 'Record ID',
            priority: 100,
            behavior: MatchBehavior::UpdateOnly,
        );
    }

    public static function email(string $fieldKey = 'custom_fields_emails'): self
    {
        return new self(
            field: $fieldKey,
            label: 'Email',
            priority: 90,
        );
    }

    public static function domain(string $fieldKey = 'custom_fields_domains'): self
    {
        return new self(
            field: $fieldKey,
            label: 'Domain',
            priority: 80,
        );
    }

    public static function phone(string $fieldKey = 'custom_fields_phone_number'): self
    {
        return new self(
            field: $fieldKey,
            label: 'Phone',
            priority: 70,
        );
    }

    public static function name(): self
    {
        return new self(
            field: 'name',
            label: 'Name',
            priority: 10,
            behavior: MatchBehavior::AlwaysCreate,
        );
    }

    public function description(): string
    {
        return match ($this->behavior) {
            MatchBehavior::UpdateOnly => 'Only update existing records. Skip if not found.',
            MatchBehavior::AlwaysCreate => 'Always create a new record (no lookup).',
            default => 'Find existing record or create new if not found.',
        };
    }

    public function isAlwaysCreate(): bool
    {
        return $this->behavior === MatchBehavior::AlwaysCreate;
    }
}
