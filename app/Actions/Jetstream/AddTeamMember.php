<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

final readonly class AddTeamMember implements AddsTeamMembers
{
    /**
     * Add a new team member to the given team.
     */
    public function add(User $user, Team $team, string $email, ?string $role = null): void
    {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        $this->validate($team, $email, $role);

        $newTeamMember = Jetstream::findUserByEmailOrFail($email);

        event(new \Laravel\Jetstream\Events\AddingTeamMember($team, $newTeamMember));

        $team->users()->attach(
            $newTeamMember, ['role' => $role]
        );

        event(new \Laravel\Jetstream\Events\TeamMemberAdded($team, $newTeamMember));
    }

    /**
     * Validate the add member operation.
     */
    private function validate(Team $team, string $email, ?string $role): void
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules(), [
            'email.exists' => __('We were unable to find a registered user with this email address.'),
        ])->after(
            $this->ensureUserIsNotAlreadyOnTeam($team, $email)
        )->validateWithBag('addTeamMember');
    }

    /**
     * Get the validation rules for adding a team member.
     *
     * @return array<string, array<int, string|Rule>>
     */
    private function rules(): array
    {
        return [
            'email' => ['required', 'email', 'exists:users'],
            'role' => ['required', 'string', new Role],
        ];
    }

    /**
     * Ensure that the user is not already on the team.
     */
    private function ensureUserIsNotAlreadyOnTeam(Team $team, string $email): Closure
    {
        return function ($validator) use ($team, $email): void { // @pest-ignore-type

            $validator->errors()->addIf(
                $team->hasUserWithEmail($email),
                'email',
                __('This user already belongs to the team.')
            );
        };
    }
}
