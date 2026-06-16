<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Chat\SeedTeamCreditBalance;
use Laravel\Jetstream\Events\TeamCreated;

final readonly class SeedTeamCreditBalanceListener
{
    public function __construct(private SeedTeamCreditBalance $action) {}

    public function handle(TeamCreated $event): void
    {
        $this->action->execute($event->team);
    }
}
