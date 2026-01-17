<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Data;

use Relaticle\ImportWizardNew\Enums\RowMatchAction;
use Spatie\LaravelData\Data;

/**
 * Represents the match result for a relationship during import.
 *
 * Tracks whether a related record exists or needs to be created.
 */
final class RelationshipMatch extends Data
{
    public function __construct(
        public readonly string $relationship,
        public readonly RowMatchAction $action,
        public readonly ?string $id = null,
        public readonly ?string $name = null,
    ) {}

    /**
     * Create a match for an existing related record.
     */
    public static function existing(string $relationship, string $id): self
    {
        return new self(
            relationship: $relationship,
            action: RowMatchAction::Update,
            id: $id,
        );
    }

    /**
     * Create a match indicating a new related record will be created.
     */
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
