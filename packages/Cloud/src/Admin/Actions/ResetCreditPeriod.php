<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Actions;

use App\Enums\Plan;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final readonly class ResetCreditPeriod
{
    public function __construct(private CreditService $credits) {}

    /**
     * @param  iterable<Workspace>  $workspaces
     */
    public function execute(SystemAdministrator $admin, Plan $plan, iterable $workspaces): void
    {
        abort_unless(Gate::forUser($admin)->allows('update', AiCreditBalance::class), 403);

        DB::transaction(function () use ($admin, $plan, $workspaces): void {
            foreach ($workspaces as $workspace) {
                $workspace->plan = $plan;
                $workspace->save();

                $this->credits->resetPeriod($workspace, (string) $admin->getKey());
            }
        });
    }
}
