<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Services\CreditService;

final readonly class StartProTrial
{
    public const int TRIAL_DAYS = 14;

    public function __construct(private CreditService $credits) {}

    /** @throws AuthorizationException */
    public function execute(User $user, Workspace $workspace): bool
    {
        throw_unless($user->ownsWorkspace($workspace), AuthorizationException::class, 'Only the workspace owner can start a trial.');

        $started = DB::transaction(function () use ($workspace): bool {
            /** @var Workspace $lockedWorkspace */
            $lockedWorkspace = Workspace::query()->whereKey($workspace)->lockForUpdate()->firstOrFail();

            if ($lockedWorkspace->pro_trial_used_at !== null
                || $lockedWorkspace->plan !== Plan::Free
                || $lockedWorkspace->subscriptions()->exists()) {
                return false;
            }

            $lockedWorkspace->forceFill([
                'plan' => Plan::Pro,
                'trial_ends_at' => now()->addDays(self::TRIAL_DAYS),
                'pro_trial_used_at' => now(),
            ])->save();

            $this->credits->resetPeriod($lockedWorkspace);

            return true;
        });

        if ($started) {
            $workspace->refresh();
        }

        return $started;
    }
}
