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
        throw_unless($user->ownsTeam($team), AuthorizationException::class, 'Only the workspace owner can start a trial.');

        throw_if($user->pro_trial_used_at !== null, AuthorizationException::class, 'This account has already used its Pro trial.');

        throw_if($team->plan !== Plan::Free, AuthorizationException::class, 'Trials are only available on the Free plan.');

        throw_if($team->subscriptions()->exists(), AuthorizationException::class, 'This workspace has already had a subscription.');

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
