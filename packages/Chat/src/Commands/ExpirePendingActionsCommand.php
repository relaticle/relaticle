<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\Chat\Services\PendingActionService;

#[Description('Mark expired pending chat actions as expired')]
#[Signature('chat:expire-pending-actions')]
final class ExpirePendingActionsCommand extends Command
{
    public function handle(PendingActionService $service): int
    {
        $count = $service->expireStale();

        $this->comment("Expired {$count} pending action(s).");

        return self::SUCCESS;
    }
}
