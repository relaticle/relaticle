<?php

declare(strict_types=1);

namespace App\Support\Inbox;

use App\Contracts\Inbox\RequestSource;
use App\Data\InboxRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Collection;

final readonly class InboxRequests
{
    /** @param  iterable<int, RequestSource>  $sources */
    public function __construct(#[Tag(RequestSource::class)] private iterable $sources) {}

    /** @return Collection<int, InboxRequest> */
    public function for(User $user, Workspace $workspace): Collection
    {
        return collect($this->sources)
            ->flatMap(fn (RequestSource $source): Collection => $source->pendingFor($user, $workspace))
            ->sortByDesc(fn (InboxRequest $request): int => $request->createdAt->getTimestamp())
            ->values();
    }

    public function find(User $user, Workspace $workspace, string $kind, string $id): ?InboxRequest
    {
        return $this->source($kind)->find($user, $workspace, $id);
    }

    public function countFor(User $user, Workspace $workspace): int
    {
        return collect($this->sources)
            ->sum(fn (RequestSource $source): int => $source->countPendingFor($user, $workspace));
    }

    public function approve(User $user, Workspace $workspace, string $kind, string $id): bool
    {
        return $this->source($kind)->approve($user, $workspace, $id);
    }

    public function deny(User $user, Workspace $workspace, string $kind, string $id): bool
    {
        return $this->source($kind)->deny($user, $workspace, $id);
    }

    private function source(string $kind): RequestSource
    {
        foreach ($this->sources as $source) {
            if ($source->kind() === $kind) {
                return $source;
            }
        }

        abort(404);
    }
}
