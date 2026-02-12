<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\UpdatesTeamNames;

final readonly class UpdateTeamName implements UpdatesTeamNames
{
    /**
     * Validate and update the given team's name.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, Team $team, array $input): void
    {
        Gate::forUser($user)->authorize('update', $team);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'min:3', 'regex:' . Team::SLUG_REGEX, "unique:teams,slug,{$team->id}"],
        ])->validateWithBag('updateTeamName');

        $team->update([
            'name' => $input['name'],
            'slug' => $input['slug'],
        ]);
    }
}
