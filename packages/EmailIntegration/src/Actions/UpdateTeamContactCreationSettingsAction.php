<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;

final readonly class UpdateTeamContactCreationSettingsAction
{
    public function execute(
        Workspace $team,
        User $actor,
        ContactCreationMode $contactCreationMode,
        bool $autoCreateCompanies,
    ): void {
        abort_unless(
            $actor->ownsWorkspace($team) || $actor->hasWorkspaceRole($team, WorkspaceRole::Admin->value),
            403,
        );

        $team->update([
            'contact_creation_mode' => $contactCreationMode,
            'auto_create_companies' => $contactCreationMode === ContactCreationMode::None
                ? false
                : $autoCreateCompanies,
        ]);
    }
}
