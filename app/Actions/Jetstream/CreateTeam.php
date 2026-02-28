<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\CreatesTeams;
use Laravel\Jetstream\Events\AddingTeam;
use Laravel\Jetstream\Jetstream;

final readonly class CreateTeam implements CreatesTeams
{
    /**
     * Validate and create a new team for the given user.
     *
     * @param  array<string, string>  $input
     */
    public function create(User $user, array $input): Team
    {
        Gate::forUser($user)->authorize('create', Jetstream::newTeamModel());

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'min:3', 'max:255', 'regex:'.Team::SLUG_REGEX, 'unique:teams,slug'],
        ])->validateWithBag('createTeam');

        $isFirstTeam = $user->ownedTeams()->count() === 0;

        event(new AddingTeam($user));

        $user->switchTeam($team = $user->ownedTeams()->create([
            'name' => $input['name'],
            'slug' => $input['slug'],
            'personal_team' => $isFirstTeam,
        ]));

        /** @var Team $team */
        return $team;
    }
}
