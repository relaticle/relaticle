<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\WorkspaceInvitation;

final readonly class RevokeWorkspaceInvitation
{
    public function revoke(WorkspaceInvitation $invitation): void
    {
        $invitation->delete();
    }
}
