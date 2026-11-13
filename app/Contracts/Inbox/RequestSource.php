<?php

declare(strict_types=1);

namespace App\Contracts\Inbox;

use App\Data\InboxRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

interface RequestSource
{
    public function kind(): string;

    /** @return Collection<int, InboxRequest> */
    public function pendingFor(User $user, Workspace $workspace): Collection;

    public function find(User $user, Workspace $workspace, string $requestId): ?InboxRequest;

    public function countPendingFor(User $user, Workspace $workspace): int;

    public function approve(User $user, Workspace $workspace, string $requestId): bool;

    public function deny(User $user, Workspace $workspace, string $requestId): bool;
}
