<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Onboarding\StartDataArrivedTurn;
use App\Models\Workspace;
use App\Support\Workspaces\BindWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Tries;

#[Tries(1)]
final class OpenDataArrivedTurn implements ShouldQueueAfterCommit
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $sourceKey,
    ) {}

    /** @return list<BindWorkspace> */
    public function middleware(): array
    {
        return [new BindWorkspace($this->workspaceId)];
    }

    public function handle(StartDataArrivedTurn $action): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);

        if ($workspace instanceof Workspace) {
            $action->execute($workspace, $this->sourceKey);
        }
    }
}
