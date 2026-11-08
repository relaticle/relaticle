<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceSetupStep: string
{
    case UseCase = 'use_case';
    case Invite = 'invite';

    public function view(): string
    {
        return match ($this) {
            self::UseCase => 'use-case',
            self::Invite => 'invite',
        };
    }
}
