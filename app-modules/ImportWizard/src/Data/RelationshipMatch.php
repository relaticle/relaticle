<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Relaticle\ImportWizard\Enums\RowMatchAction;
use Spatie\LaravelData\Data;

final class RelationshipMatch extends Data
{
    public function __construct(
        public readonly string $relationship,
        public readonly RowMatchAction $action,
        public readonly ?string $id = null,
        public readonly ?string $name = null,
    ) {}

    public static function existing(string $relationship, string $id): self
    {
        return new self(
            relationship: $relationship,
            action: RowMatchAction::Update,
            id: $id,
        );
    }

    public static function create(string $relationship, string $name): self
    {
        return new self(
            relationship: $relationship,
            action: RowMatchAction::Create,
            name: $name,
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
