<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

#[Description('Reset AI credits for teams whose billing period has ended')]
#[Signature('chat:reset-credits')]
final class ResetCreditsCommand extends Command
{
    public function handle(CreditService $service): int
    {
        $expired = AiCreditBalance::query()
            ->where('period_ends_at', '<', now())
            ->get();

        foreach ($expired as $balance) {
            /** @var Team|null $team */
            $team = Team::query()->find($balance->team_id);

            if ($team === null) {
                continue;
            }

            $service->resetPeriod($team);
        }

        $this->comment("Reset credits for {$expired->count()} team(s).");

        return self::SUCCESS;
    }
}
