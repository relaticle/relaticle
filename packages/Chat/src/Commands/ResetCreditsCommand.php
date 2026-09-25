<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

#[Description('Reset AI credits for workspaces whose billing period has ended')]
#[Signature('chat:reset-credits')]
final class ResetCreditsCommand extends Command
{
    public function handle(CreditService $service): int
    {
        $expired = AiCreditBalance::query()
            ->where('period_ends_at', '<', now())
            ->get();

        foreach ($expired as $balance) {
            /** @var Workspace|null $workspace */
            $workspace = Workspace::query()->find($balance->workspace_id);

            if ($workspace === null) {
                continue;
            }

            $service->resetPeriod($workspace);
        }

        $this->comment("Reset credits for {$expired->count()} workspace(s).");

        return self::SUCCESS;
    }
}
