<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The activation steps registered in App\Onboarding\ActivationSteps.
 *
 * The registry stores the case value as a step attribute; consumers read it
 * back through from(), so a step registered without a matching case fails at
 * the read rather than rendering an untargeted row.
 */
enum ActivationStep: string
{
    case FirstRecord = 'first_record';
    case Import = 'import';
    case Invite = 'invite';
    case AskRela = 'ask_rela';
}
