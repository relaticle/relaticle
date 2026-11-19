<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Referral\Actions;

use App\Models\User;
use Relaticle\Cloud\Referral\Enums\ReferralPromptOutcome;
use Relaticle\Cloud\Referral\Models\ReferralPrompt;

final readonly class EndReferralPrompt
{
    public function execute(User $user, ReferralPromptOutcome $outcome): void
    {
        ReferralPrompt::query()->firstOrCreate(['user_id' => $user->getKey()], [
            'outcome' => $outcome,
            'ended_at' => now(),
        ]);
    }
}
