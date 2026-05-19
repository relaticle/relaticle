<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use App\Models\Team;
use Illuminate\Console\Command;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

final class ResetCreditsCommand extends Command
{
    protected $signature = 'chat:reset-credits';

    protected $description = 'Reset AI credits for teams whose billing period has ended';

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
