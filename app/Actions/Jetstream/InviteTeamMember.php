<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Enums\TeamRole;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation as TeamInvitationModel;
use App\Models\User;
use App\Rules\RegistrableEmail;
use App\Support\EmailAddress;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\InvitesTeamMembers;
use Laravel\Jetstream\Events\InvitingTeamMember;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

final readonly class InviteTeamMember implements InvitesTeamMembers
{
    /**
     * Invite a new team member to the given team.
     *
     * @return TeamInvitationModel the created invitation
     */
    public function invite(User $user, Team $team, string $email, ?string $role = null): TeamInvitationModel
    {
        $email = Str::lower($email);

        Gate::forUser($user)->authorize('addTeamMember', $team);

        if ($role === TeamRole::Admin->value) {
            Gate::forUser($user)->authorize('promoteToAdmin', $team);
        }

        $email = EmailAddress::canonicalize($email);

        $this->validate($team, $email, $role);

        event(new InvitingTeamMember($team, $email, $role));

        $invitation = $team->teamInvitations()->make([
            'email' => $email,
            'role' => $role,
            'inviter_id' => $user->id,
        ]);

        /** @var TeamInvitationModel $invitation */
        $rawToken = $invitation->issueToken();

        // The unique rule above is a check-then-write, so a concurrent invite to
        // the same address reaches the constraint instead of the validator. The
        // transaction makes that a savepoint, so a caller holding one (the chat
        // approval path) survives the rollback.
        try {
            DB::transaction(fn () => $invitation->save());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => __('teams.validation.email_already_invited'),
            ])->errorBag('addTeamMember');
        }

        // Queued, and deferred to after the transaction commits. The chat
        // approval path runs this inside PendingActionService::approve()'s
        // transaction while it holds lockForUpdate() on the pending action, so
        // a synchronous send made a third-party SMTP round trip decide how long
        // that row stayed locked. afterCommit() is what keeps the queue push
        // itself out of the transaction too: a rolled back approval must not
        // leave a real invitation email on its way.
        Mail::to($invitation->email)->queue(new TeamInvitationMail($invitation, $rawToken)->afterCommit());

        return $invitation;
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
