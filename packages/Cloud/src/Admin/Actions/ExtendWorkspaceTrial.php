<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Actions;

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Services\CreditService;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final readonly class ExtendWorkspaceTrial
{
    public function __construct(private CreditService $credits) {}

    public function execute(SystemAdministrator $admin, Workspace $workspace, CarbonImmutable $endsAt): void
    {
        if (! $endsAt->isFuture()) {
            throw ValidationException::withMessages(['trial_ends_at' => 'The trial must end in the future.']);
        }

        DB::transaction(function () use ($admin, $workspace, $endsAt): void {
            /** @var Workspace $locked */
            $locked = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();

            abort_unless(Gate::forUser($admin)->allows('extendTrial', $locked), 403);

            $restarting = $locked->billingStatus() === BillingStatus::TrialEnded;

            $locked->forceFill([
                'plan' => Plan::Pro,
                'trial_ends_at' => $endsAt,
                'pro_trial_used_at' => $locked->pro_trial_used_at ?? now(),
            ])->save();

            // A running trial keeps its balance: chat:reset-credits refills it when the stored period ends.
            if ($restarting) {
                $this->credits->resetPeriod($locked, (string) $admin->getKey());
            }
        });

        $workspace->refresh();
    }
}
