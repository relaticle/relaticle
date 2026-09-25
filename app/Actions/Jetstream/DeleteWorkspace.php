<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Actions\Billing\CancelWorkspaceSubscription;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class DeleteWorkspace implements DeletesTeams
{
    public function __construct(private CancelWorkspaceSubscription $cancelSubscription) {}

    public function delete(Workspace $workspace): void
    {
        $this->cancelSubscription->execute($workspace, immediately: true);

        $workspace->purge();

        DB::afterCommit(function () use ($workspace): void {
            Media::query()->where('workspace_id', $workspace->getKey())->lazyById()->each->delete();
        });
    }
}
