<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Billing;
use App\Enums\DeletionReason;
use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Contracts\DeletesTeams;

#[DeleteWhenMissingModels]
#[Timeout(300)]
#[Tries(2)]
#[UniqueFor(900)]
final class PurgeWorkspace implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Workspace $workspace)
    {
        $this->onQueue('imports');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->workspace->getKey();
    }

    public function handle(DeletesTeams $deletesWorkspaces, Billing $billing): void
    {
        $purged = DB::transaction(function () use ($deletesWorkspaces, $billing): bool {
            $workspace = Workspace::query()->whereKey($this->workspace->getKey())->lockForUpdate()->first();

            if (! $workspace instanceof Workspace || ! $workspace->isDeletionDue()) {
                return false;
            }

            if ($workspace->deletion_reason === DeletionReason::Inactivity && ! $billing->inactivityDeletionStands($workspace)) {
                $this->cancelInactivityDeletion($workspace);

                return false;
            }

            $deletesWorkspaces->delete($workspace);

            return true;
        });

        if ($purged) {
            Log::info('Purged workspace', ['workspace_id' => $this->workspace->getKey(), 'name' => $this->workspace->name]);
        }
    }

    private function cancelInactivityDeletion(Workspace $workspace): void
    {
        Workspace::query()
            ->whereKey($workspace->getKey())
            ->scheduledForInactivity()
            ->update(['scheduled_deletion_at' => null, 'deletion_reason' => null]);

        Log::info('Cancelled an inactivity deletion that no longer stands', ['workspace_id' => $workspace->getKey()]);
    }
}
