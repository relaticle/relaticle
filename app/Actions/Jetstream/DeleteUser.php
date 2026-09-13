<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

final readonly class DeleteUser implements DeletesUsers
{
    /**
     * Create a new action instance.
     */
    public function __construct(private DeletesTeams $deletesWorkspaces) {}

    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->deleteWorkspaces($user);
            $user->deleteProfilePhoto();
            $user->loadMissing('tokens');
            $user->tokens->each->delete();
            $user->delete();
        });
    }

    /**
     * Delete the workspaces and workspace associations attached to the user.
     */
    private function deleteWorkspaces(User $user): void
    {
        $user->workspaces()->detach();
        $user->loadMissing('ownedWorkspaces');

        $user->ownedWorkspaces->each(function (Model $workspace): void {
            /** @var Workspace $workspace */
            $this->deletesWorkspaces->delete($workspace);
        });
    }
}
