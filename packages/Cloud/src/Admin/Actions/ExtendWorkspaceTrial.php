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
    public const int MAX_DAYS_AHEAD = 60;

    public function __construct(
        private CreditService $credits,
        private RecordTrialChange $recordTrialChange,
    ) {}

    public function execute(SystemAdministrator $admin, Workspace $workspace, CarbonImmutable $endsAt): void
    {
        if (! $endsAt->isFuture()) {
            throw ValidationException::withMessages(['trial_ends_at' => __('cloud::admin.trials.must_end_in_future')]);
        }

        if ($endsAt->isAfter(now()->addDays(self::MAX_DAYS_AHEAD))) {
            throw ValidationException::withMessages(['trial_ends_at' => __('cloud::admin.trials.latest_end', ['days' => self::MAX_DAYS_AHEAD])]);
        }

        DB::transaction(function () use ($admin, $workspace, $endsAt): void {
            /** @var Workspace $locked */
            $locked = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();

            abort_unless(Gate::forUser($admin)->allows('extendTrial', $locked), 403);

            $restarting = $locked->billingStatus() === BillingStatus::TrialEnded;
            $before = $locked->only(['plan', 'trial_ends_at']);
            $previousEnd = $locked->trial_ends_at;

            $locked->forceFill([
                'plan' => Plan::Pro,
                'trial_ends_at' => $endsAt,
                'pro_trial_used_at' => $locked->pro_trial_used_at ?? now(),
            ])->save();

            // A running trial keeps its balance: chat:reset-credits refills it when the stored period ends.
            if ($restarting) {
                $this->credits->resetPeriod($locked, (string) $admin->getKey());
            }

            $this->recordTrialChange->execute($admin, $locked, $this->event($restarting, $previousEnd, $endsAt), $before);
        });

        $workspace->refresh();
    }

    private function event(bool $restarting, ?CarbonImmutable $previousEnd, CarbonImmutable $endsAt): string
    {
        return match (true) {
            $restarting => 'trial_restarted',
            $previousEnd?->isAfter($endsAt) === true => 'trial_shortened',
            default => 'trial_extended',
        };
    }
}
