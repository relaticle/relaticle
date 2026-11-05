<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Actions;

use App\Enums\Plan;
use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Cloud\Admin\Actions\RecordTrialChange;

final readonly class ExtendEndedTrial
{
    public function __construct(
        private RecordTrialChange $recordTrialChange,
        private CancelInactivityDeletion $cancelInactivityDeletion,
    ) {}

    /** @throws AuthorizationException */
    public function execute(User $user, Workspace $workspace): bool
    {
        throw_unless(
            $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage),
            AuthorizationException::class,
            __('cloud::billing.trial.extend_owner_only'),
        );

        $extended = DB::transaction(function () use ($user, $workspace): bool {
            /** @var Workspace $lockedWorkspace */
            $lockedWorkspace = Workspace::query()->whereKey($workspace)->lockForUpdate()->firstOrFail();

            if (! $lockedWorkspace->canExtendTrial()) {
                return false;
            }

            $before = $lockedWorkspace->only(['plan', 'trial_ends_at']);
            $now = now();
            $endsAt = $now->addDays(Workspace::TRIAL_EXTENSION_DAYS);

            $lockedWorkspace->forceFill([
                'plan' => Plan::Pro,
                'trial_ends_at' => $endsAt,
                'trial_extended_at' => $now,
            ])->save();

            // Pinned so a month-boundary chat:reset-credits cannot refill the balance mid-extension.
            AiCreditBalance::query()
                ->where('workspace_id', $lockedWorkspace->getKey())
                ->update(['period_ends_at' => $endsAt]);

            $this->recordTrialChange->execute($user, $lockedWorkspace, 'trial_self_extended', $before);
            $this->cancelInactivityDeletion->execute($lockedWorkspace);

            return true;
        });

        if ($extended) {
            $workspace->refresh();
        }

        return $extended;
    }
}
