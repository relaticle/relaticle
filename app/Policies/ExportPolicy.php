<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Export;
use App\Models\User;

final readonly class ExportPolicy
{
    public function view(User $user, Export $export): bool
    {
        if ($export->user_id !== $user->getKey()) {
            return false;
        }

        return $export->workspace_id === null || $user->belongsToWorkspaceId($export->workspace_id);
    }
}
