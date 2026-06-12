<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Services\CreditService;

final readonly class StartProTrial
{
    public const int TRIAL_DAYS = 14;

    public function __construct(private CreditService $credits) {}

    /** @throws AuthorizationException */
    public function handle(User $user, Team $team): void
    {
        if (! $user->ownsTeam($team)) {
            throw new AuthorizationException('Only the workspace owner can start a trial.');
        }

        if ($user->pro_trial_used_at !== null) {
            throw new AuthorizationException('This account has already used its Pro trial.');
        }

        if ($team->plan !== Plan::Free) {
            throw new AuthorizationException('Trials are only available on the Free plan.');
        }

        if ($team->subscriptions()->exists()) {
            throw new AuthorizationException('This workspace has already had a subscription.');
        }

        DB::transaction(function () use ($user, $team): void {
            $team->forceFill([
                'plan' => Plan::Pro,
                'trial_ends_at' => now()->addDays(self::TRIAL_DAYS),
            ])->save();

            $user->forceFill(['pro_trial_used_at' => now()])->save();

            $this->credits->resetPeriod($team);
        });
    }
}
