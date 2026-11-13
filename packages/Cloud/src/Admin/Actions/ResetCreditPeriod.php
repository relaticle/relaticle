<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Actions;

use App\Actions\Jetstream\CancelInactivityDeletion;
use App\Enums\Plan;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final readonly class ResetCreditPeriod
{
    public function __construct(
        private CreditService $credits,
        private CancelInactivityDeletion $cancelInactivityDeletion,
    ) {}

    /**
     * @param  iterable<Workspace>  $workspaces
     */
    public function execute(SystemAdministrator $admin, ?Plan $plan, iterable $workspaces): void
    {
        abort_unless(Gate::forUser($admin)->allows('update', AiCreditBalance::class), 403);

        $workspaces = collect($workspaces);

        foreach ($workspaces as $workspace) {
            abort_if(
                $plan instanceof Plan && $plan !== $workspace->plan && Gate::forUser($admin)->denies('manageManagedPlan', $workspace),
                403,
            );
        }

        DB::transaction(function () use ($admin, $plan, $workspaces): void {
            foreach ($workspaces as $workspace) {
                $workspace->plan = $plan ?? $workspace->plan;
                $workspace->save();

                if ($workspace->plan !== Plan::default()) {
                    $this->cancelInactivityDeletion->execute($workspace);
                }

                $this->credits->resetPeriod($workspace, (string) $admin->getKey());
            }
        });
    }
}
