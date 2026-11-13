<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Actions;

use App\Enums\Plan;
use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Services\CreditService;

final readonly class StartProTrial
{
    public function __construct(private CreditService $credits) {}

    /** @throws AuthorizationException */
    public function execute(User $user, Workspace $workspace): bool
    {
        throw_unless(
            $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage),
            AuthorizationException::class,
            'Only the workspace owner can start a trial.',
        );

        $started = DB::transaction(function () use ($workspace): bool {
            // Two workspaces of one owner starting at once would each read the other as untrialed.
            User::query()->whereKey($workspace->user_id)->lockForUpdate()->first();

            /** @var Workspace $lockedWorkspace */
            $lockedWorkspace = Workspace::query()->whereKey($workspace)->lockForUpdate()->firstOrFail();

            if (! $lockedWorkspace->canStartTrial()) {
                return false;
            }

            $lockedWorkspace->forceFill([
                'plan' => Plan::Pro,
                'trial_ends_at' => now()->addDays(Workspace::PRO_TRIAL_DAYS),
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
