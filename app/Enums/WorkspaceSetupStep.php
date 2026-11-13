<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceSetupStep: string
{
    case Email = 'email';
    case Sharing = 'sharing';
    case UseCase = 'use_case';
    case Invite = 'invite';

    public function view(): string
    {
        return match ($this) {
            self::Email => 'email',
            self::Sharing => 'sharing',
            self::UseCase => 'use-case',
            self::Invite => 'invite',
        };
    }
}
