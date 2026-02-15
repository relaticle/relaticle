<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

enum MatchBehavior: string
{
    case MatchOnly = 'match_only';
    case MatchOrCreate = 'match_or_create';
    case Create = 'create';

    public function description(): string
    {
        return match ($this) {
            self::MatchOnly => 'Only update existing records. Skip if not found.',
            self::MatchOrCreate => 'Find existing record or create new if not found.',
            self::Create => 'Always create a new record (no lookup).',
        };
    }

    public function performsLookup(): bool
    {
        return $this !== self::Create;
    }

    public function createsOnNoMatch(): bool
    {
        return $this === self::MatchOrCreate || $this === self::Create;
    }
}
