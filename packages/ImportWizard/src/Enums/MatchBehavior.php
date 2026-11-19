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
            self::MatchOnly => __('enums/match-behavior.descriptions.match_only'),
            self::MatchOrCreate => __('enums/match-behavior.descriptions.match_or_create'),
            self::Create => __('enums/match-behavior.descriptions.create'),
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
