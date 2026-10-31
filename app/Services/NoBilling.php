<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Billing;
use App\Models\User;
use App\Models\Workspace;

final readonly class NoBilling implements Billing
{
    public function url(Workspace $workspace): ?string
    {
        return null;
    }

    public function sellsCredits(Workspace $workspace): bool
    {
        return false;
    }

    public function startTrial(User $owner, Workspace $workspace): void {}

    public function cancelForDeletion(Workspace $workspace, bool $immediately): void {}

    public function blocksDeletion(Workspace $workspace): bool
    {
        return false;
    }

    public function deletionNotice(Workspace $workspace): ?string
    {
        return null;
    }
}
