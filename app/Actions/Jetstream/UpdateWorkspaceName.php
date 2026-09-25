<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Models\Workspace;
use App\Rules\ValidWorkspaceSlug;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\UpdatesTeamNames;

final readonly class UpdateWorkspaceName implements UpdatesTeamNames
{
    /**
     * Validate and update the given workspace's name.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, Workspace $workspace, array $input): void
    {
        Gate::forUser($user)->authorize('update', $workspace);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', new ValidWorkspaceSlug(ignoreValue: $workspace->slug), "unique:workspaces,slug,{$workspace->id}"],
        ])->validateWithBag('updateWorkspaceName');

        $workspace->update([
            'name' => $input['name'],
            'slug' => $input['slug'],
        ]);
    }
}
