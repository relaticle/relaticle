<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Match types for company resolution during import.
 */
enum MatchType: string implements HasLabel
{
    case Id = 'id';
    case Domain = 'domain';
    case New = 'new';
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::Id => 'Matched by ID',
            self::Domain => 'Matched by Domain',
            self::New => 'New Company',
            self::None => 'No Company',
        };
    }
}
