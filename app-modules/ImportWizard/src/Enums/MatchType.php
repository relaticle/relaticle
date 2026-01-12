<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Match types for relationship resolution during import.
 */
enum MatchType: string implements HasLabel
{
    case Id = 'id';
    case Domain = 'domain';
    case Email = 'email';
    case New = 'new';
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::Id => 'Matched by ID',
            self::Domain => 'Matched by Domain',
            self::Email => 'Matched by Email',
            self::New => 'Will Create New',
            self::None => 'Not Mapped',
        };
    }

    /**
     * Returns true only for types that match existing records.
     */
    public function isMatched(): bool
    {
        return in_array($this, [self::Id, self::Domain, self::Email], true);
    }

    /**
     * Returns true for types that create new records.
     */
    public function willCreate(): bool
    {
        return $this === self::New;
    }
}
