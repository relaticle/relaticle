<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;

final readonly class UpdateTeamContactCreationSettingsAction
{
    public function execute(
        Team $team,
        User $actor,
        ContactCreationMode $contactCreationMode,
        bool $autoCreateCompanies,
    ): void {
        abort_unless(
            $actor->ownsTeam($team) || $actor->hasTeamRole($team, TeamRole::Admin->value),
            403,
        );

        $team->update([
            'contact_creation_mode' => $contactCreationMode,
            'auto_create_companies' => $autoCreateCompanies,
        ]);
    }
}
