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
    public function execute(User $user, Team $team): bool
    {
        throw_unless($user->ownsTeam($team), AuthorizationException::class, 'Only the workspace owner can start a trial.');

        $started = DB::transaction(function () use ($user, $team): bool {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user)->lockForUpdate()->firstOrFail();

            /** @var Team $lockedTeam */
            $lockedTeam = Team::query()->whereKey($team)->lockForUpdate()->firstOrFail();

            if ($lockedUser->pro_trial_used_at !== null
                || $lockedTeam->plan !== Plan::Free
                || $lockedTeam->subscriptions()->exists()) {
                return false;
            }

            $lockedTeam->forceFill([
                'plan' => Plan::Pro,
                'trial_ends_at' => now()->addDays(self::TRIAL_DAYS),
            ])->save();

            $lockedUser->forceFill(['pro_trial_used_at' => now()])->save();

            $this->credits->resetPeriod($lockedTeam);

            return true;
        });

        if ($started) {
            $user->refresh();
            $team->refresh();
        }

        return $started;
    }
}
