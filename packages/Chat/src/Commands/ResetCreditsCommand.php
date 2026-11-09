<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
            DB::transaction(function () use ($balance, $service): void {
                $stillExpired = AiCreditBalance::query()
                    ->whereKey($balance->getKey())
                    ->where('period_ends_at', '<', now())
                    ->lockForUpdate()
                    ->exists();

                if (! $stillExpired) {
                    return;
                }

                /** @var Workspace|null $workspace */
                $workspace = Workspace::query()->find($balance->workspace_id);

                if ($workspace === null) {
                    return;
                }

                $service->resetPeriod($workspace);
            });
        }

        $this->comment("Reset credits for {$expired->count()} workspace(s).");

        return self::SUCCESS;
    }
}
