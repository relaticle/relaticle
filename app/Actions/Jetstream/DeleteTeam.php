<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Actions\Billing\CancelTeamSubscription;
use App\Models\Team;
use Laravel\Jetstream\Contracts\DeletesTeams;

final readonly class DeleteTeam implements DeletesTeams
{
    public function __construct(private CancelTeamSubscription $cancelSubscription) {}

    /**
     * Delete the given team.
     */
    public function delete(Team $team): void
    {
        $this->cancelSubscription->execute($team, immediately: true);

        $team->purge();
    }
}
