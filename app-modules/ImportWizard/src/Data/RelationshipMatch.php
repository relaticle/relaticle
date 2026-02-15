<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Spatie\LaravelData\Data;

final class RelationshipMatch extends Data
{
    public function __construct(
        public readonly string $relationship,
        public readonly RowMatchAction $action,
        public readonly ?string $id = null,
        public readonly ?string $name = null,
        public readonly ?MatchBehavior $behavior = null,
        public readonly ?string $matchField = null,
    ) {}

    public static function existing(string $relationship, string $id, ?MatchBehavior $behavior = null, ?string $matchField = null): self
    {
        return new self(
            relationship: $relationship,
            action: RowMatchAction::Update,
            id: $id,
            behavior: $behavior,
            matchField: $matchField,
        );
    }

    public static function create(string $relationship, string $name, ?MatchBehavior $behavior = null, ?string $matchField = null): self
    {
        return new self(
            relationship: $relationship,
            action: RowMatchAction::Create,
            name: $name,
            behavior: $behavior,
            matchField: $matchField,
        );
    }

    public function isExisting(): bool
    {
        return $this->action === RowMatchAction::Update;
    }

    public function isCreate(): bool
    {
        return $this->action === RowMatchAction::Create;
    }
}
