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
        public readonly MatchBehavior $behavior = MatchBehavior::MatchOrCreate,
        public readonly bool $multiValue = false,
    ) {}

    public static function id(): self
    {
        return new self(
            field: 'id',
            label: 'Record ID',
            priority: 100,
            behavior: MatchBehavior::MatchOnly,
        );
    }

    public static function email(
        string $fieldKey = 'custom_fields_emails',
        MatchBehavior $behavior = MatchBehavior::MatchOrCreate,
    ): self {
        return new self(
            field: $fieldKey,
            label: 'Email',
            priority: 90,
            behavior: $behavior,
            multiValue: true,
        );
    }

    public static function domain(
        string $fieldKey = 'custom_fields_domains',
        MatchBehavior $behavior = MatchBehavior::MatchOrCreate,
    ): self {
        return new self(
            field: $fieldKey,
            label: 'Domain',
            priority: 80,
            behavior: $behavior,
            multiValue: true,
        );
    }

    public static function phone(
        string $fieldKey = 'custom_fields_phone_number',
        MatchBehavior $behavior = MatchBehavior::MatchOrCreate,
    ): self {
        return new self(
            field: $fieldKey,
            label: 'Phone',
            priority: 70,
            behavior: $behavior,
            multiValue: true,
        );
    }

    public static function name(): self
    {
        return new self(
            field: 'name',
            label: 'Name',
            priority: 10,
            behavior: MatchBehavior::Create,
        );
    }

    public function description(): string
    {
        return $this->behavior->description();
    }

    public function isCreate(): bool
    {
        return $this->behavior === MatchBehavior::Create;
    }
}
