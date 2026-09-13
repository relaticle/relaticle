<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Enums\WorkspaceRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation as WorkspaceInvitationModel;
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

final readonly class InviteWorkspaceMember implements InvitesTeamMembers
{
    /**
     * Invite a new workspace member to the given workspace.
     *
     * @return WorkspaceInvitationModel the created invitation
     */
    public function invite(User $user, Workspace $workspace, string $email, ?string $role = null): WorkspaceInvitationModel
    {
        $email = Str::lower($email);

        Gate::forUser($user)->authorize('addWorkspaceMember', $workspace);

        if ($role === WorkspaceRole::Admin->value) {
            Gate::forUser($user)->authorize('promoteToAdmin', $workspace);
        }

        $email = EmailAddress::canonicalize($email);

        $this->validate($workspace, $email, $role);

        event(new InvitingTeamMember($workspace, $email, $role));

        $invitation = $workspace->workspaceInvitations()->make([
            'email' => $email,
            'role' => $role,
            'inviter_id' => $user->id,
        ]);

        /** @var WorkspaceInvitationModel $invitation */
        $rawToken = $invitation->issueToken();

        // The unique rule above is a check-then-write, so a concurrent invite to
        // the same address reaches the constraint instead of the validator. The
        // transaction makes that a savepoint, so a caller holding one (the chat
        // approval path) survives the rollback.
        try {
            DB::transaction(fn () => $invitation->save());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => __('workspaces.validation.email_already_invited'),
            ])->errorBag('addWorkspaceMember');
        }

        // Queued, and deferred to after the transaction commits. The chat
        // approval path runs this inside PendingActionService::approve()'s
        // transaction while it holds lockForUpdate() on the pending action, so
        // a synchronous send made a third-party SMTP round trip decide how long
        // that row stayed locked. afterCommit() is what keeps the queue push
        // itself out of the transaction too: a rolled back approval must not
        // leave a real invitation email on its way.
        Mail::to($invitation->email)->queue(new WorkspaceInvitationMail($invitation, $rawToken)->afterCommit());

        return $invitation;
    }

    /**
     * Validate the invite member operation.
     */
    private function validate(Workspace $workspace, string $email, ?string $role): void
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules($workspace), [
            'email.unique' => __('workspaces.validation.email_already_invited'),
        ])->after(
            $this->ensureUserIsNotAlreadyOnWorkspace($workspace, $email)
        )->validateWithBag('addWorkspaceMember');
    }

    /**
     * Get the validation rules for inviting a workspace member.
     *
     * @return array<string, list<Unique|Role|string>>
     */
    private function rules(Workspace $workspace): array
    {
        return [
            'email' => [
                'required', ...RegistrableEmail::rules(checkDns: false),
                Rule::unique(Jetstream::teamInvitationModel())->where(function (Builder $query) use ($workspace): void {
                    $query->where('workspace_id', $workspace->id);
                }),
            ],
            'role' => ['required', 'string', new Role],
        ];
    }

    /**
     * Ensure that the user is not already on the workspace.
     */
    private function ensureUserIsNotAlreadyOnWorkspace(Workspace $workspace, string $email): Closure
    {
        return function ($validator) use ($workspace, $email): void { // @pest-ignore-type
            $validator->errors()->addIf(
                $workspace->hasUserWithEmail($email),
                'email',
                __('workspaces.validation.email_already_member')
            );
        };
    }
}
