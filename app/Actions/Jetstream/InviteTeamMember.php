<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Enums\TeamRole;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation as TeamInvitationModel;
use App\Models\User;
use App\Rules\RegistrableEmail;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Laravel\Jetstream\Contracts\InvitesTeamMembers;
use Laravel\Jetstream\Events\InvitingTeamMember;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

final readonly class InviteTeamMember implements InvitesTeamMembers
{
    /**
     * Invite a new team member to the given team.
     */
    public function invite(User $user, Team $team, string $email, ?string $role = null): void
    {
        $email = Str::lower($email);

        Gate::forUser($user)->authorize('addTeamMember', $team);

        if ($role === TeamRole::Admin->value) {
            Gate::forUser($user)->authorize('promoteToAdmin', $team);
        }

        $this->validate($team, $email, $role);

        event(new InvitingTeamMember($team, $email, $role));

        $invitation = $team->teamInvitations()->make([
            'email' => $email,
            'role' => $role,
            'inviter_id' => $user->id,
        ]);

        /** @var TeamInvitationModel $invitation */
        $rawToken = $invitation->issueToken();
        $invitation->save();

        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation, $rawToken));
    }

    /**
     * Validate the invite member operation.
     */
    private function validate(Team $team, string $email, ?string $role): void
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules($team), [
            'email.unique' => __('teams.validation.email_already_invited'),
        ])->after(
            $this->ensureUserIsNotAlreadyOnTeam($team, $email)
        )->validateWithBag('addTeamMember');
    }

    /**
     * Get the validation rules for inviting a team member.
     *
     * @return array<string, list<Unique|Role|string>>
     */
    private function rules(Team $team): array
    {
        return [
            'email' => [
                'required', ...RegistrableEmail::rules(checkDns: false),
                Rule::unique(Jetstream::teamInvitationModel())->where(function (Builder $query) use ($team): void {
                    $query->where('team_id', $team->id);
                }),
            ],
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
                __('teams.validation.email_already_member')
            );
        };
    }
}
