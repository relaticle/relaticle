<?php

declare(strict_types=1);

namespace App\Enums;

enum OnboardingStep: string
{
    case Email = 'email';
    case Sharing = 'sharing';
    case UseCase = 'use_case';
    case Invite = 'invite';
}
