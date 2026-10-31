<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use App\Models\Workspace;

interface Billing
{
    public function url(Workspace $workspace): ?string;

    public function sellsCredits(Workspace $workspace): bool;

    public function startTrial(User $owner, Workspace $workspace): void;

    public function cancelForDeletion(Workspace $workspace, bool $immediately): void;

    public function blocksDeletion(Workspace $workspace): bool;

    public function deletionNotice(Workspace $workspace): ?string;
}
