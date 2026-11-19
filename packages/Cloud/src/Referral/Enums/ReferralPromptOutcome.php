<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Referral\Enums;

enum ReferralPromptOutcome: string
{
    case Copied = 'copied';
    case Dismissed = 'dismissed';
}
