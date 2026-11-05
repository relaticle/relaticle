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
    public const int DAYS = 7;

    public function __construct(private RecordTrialChange $recordTrialChange) {}

    /** @throws AuthorizationException */
    public function execute(User $user, Workspace $workspace): bool
    {
        throw_unless(
            $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage),
            AuthorizationException::class,
            'Only the workspace owner can extend a trial.',
        );

        $extended = DB::transaction(function () use ($user, $workspace): bool {
            /** @var Workspace $lockedWorkspace */
            $lockedWorkspace = Workspace::query()->whereKey($workspace)->lockForUpdate()->firstOrFail();

            if (! $lockedWorkspace->canExtendTrial()) {
                return false;
            }

            $before = $lockedWorkspace->only(['plan', 'trial_ends_at']);
            $endsAt = now()->addDays(self::DAYS);

            $lockedWorkspace->forceFill([
                'plan' => Plan::Pro,
                'trial_ends_at' => $endsAt,
                'trial_extended_at' => now(),
            ])->save();

            // Pinned so chat:reset-credits cannot grant the Pro allowance mid-extension.
            AiCreditBalance::query()
                ->where('workspace_id', $lockedWorkspace->getKey())
                ->update(['period_ends_at' => $endsAt]);

            $this->recordTrialChange->execute($user, $lockedWorkspace, 'trial_self_extended', $before);

            return true;
        });

        if ($extended) {
            $workspace->refresh();
        }

        return $extended;
    }
}
