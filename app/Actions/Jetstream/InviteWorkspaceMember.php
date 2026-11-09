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
use App\Support\Workspaces\InvitationCeiling;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\InvitesTeamMembers;
use Laravel\Jetstream\Events\InvitingTeamMember;
use Laravel\Jetstream\Rules\Role;

final readonly class InviteWorkspaceMember implements InvitesTeamMembers
{
    public function __construct(private InvitationCeiling $ceiling) {}

    /**
     * Invite a new workspace member to the given workspace.
     *
     * @return WorkspaceInvitationModel the created invitation
     */
    public function invite(User $user, Workspace $workspace, string $email, ?string $role = null): WorkspaceInvitationModel
    {
        $email = Str::lower($email);

        Gate::forUser($user)->authorize('addWorkspaceMember', $workspace);

        if (WorkspaceRole::keyIsAdmin($role)) {
            Gate::forUser($user)->authorize('promoteToAdmin', $workspace);
        }

        $this->ensureWorkspaceIsNotClosing($workspace);

        $email = EmailAddress::canonicalize($email);

        $this->validate($workspace, $email, $role);

        $this->ceiling->ensureAvailable($user, $workspace);

        event(new InvitingTeamMember($workspace, $email, $role));

        // The validator checked before this write, so a concurrent invite is caught here. The transaction is
        // a savepoint for a caller that holds one (the chat approval path).
        try {
            [$invitation, $rawToken] = DB::transaction(fn (): array => $this->issueInvitation($user, $workspace, $email, $role));
        } catch (UniqueConstraintViolationException) {
            throw $this->alreadyInvited();
        }

        // Queued, and deferred to after the transaction commits. The chat
        // approval path runs this inside PendingActionService::approve()'s
        // transaction while it holds lockForUpdate() on the pending action, so
        // a synchronous send made a third-party SMTP round trip decide how long
        // that row stayed locked. afterCommit() is what keeps the queue push
        // itself out of the transaction too: a rolled back approval must not
        // leave a real invitation email on its way.
        Mail::to($invitation->email)->queue(new WorkspaceInvitationMail($invitation, $rawToken)->afterCommit());

        $this->ceiling->recordSent($user, $workspace);

        return $invitation;
    }

    private function ensureWorkspaceIsNotClosing(Workspace $workspace): void
    {
        if (! $workspace->isScheduledForDeletion()) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => __('workspaces.validation.workspace_scheduled_for_deletion'),
        ])->errorBag('addWorkspaceMember');
    }

    /**
     * Validate the invite member operation.
     */
    private function validate(Workspace $workspace, string $email, ?string $role): void
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules())->after(
            $this->ensureAddressIsNotAlreadyInvited($workspace, $email)
        )->after(
            $this->ensureUserIsNotAlreadyOnWorkspace($workspace, $email)
        )->validateWithBag('addWorkspaceMember');
    }

    /**
     * @return list<mixed>
     */
    public static function emailRules(): array
    {
        return ['max:255', ...RegistrableEmail::rules(checkDns: false)];
    }

    /**
     * Get the validation rules for inviting a workspace member.
     *
     * @return array<string, list<Role|string>>
     */
    private function rules(): array
    {
        return [
            'email' => ['required', ...self::emailRules()],
            'role' => ['required', 'string', new Role],
        ];
    }

    /**
     * @return array{WorkspaceInvitationModel, string}
     */
    private function issueInvitation(User $inviter, Workspace $workspace, string $email, ?string $role): array
    {
        /** @var WorkspaceInvitationModel|null $invitation */
        $invitation = $workspace->workspaceInvitations()->where('email', $email)->lockForUpdate()->first();

        if ($invitation instanceof WorkspaceInvitationModel && ! $invitation->isExpired()) {
            throw $this->alreadyInvited();
        }

        $invitation ??= $workspace->workspaceInvitations()->make(['email' => $email]);
        $invitation->fill(['role' => $role, 'inviter_id' => $inviter->id]);

        $rawToken = $invitation->issueToken();
        $invitation->save();

        return [$invitation, $rawToken];
    }

    private function alreadyInvited(): ValidationException
    {
        return ValidationException::withMessages([
            'email' => __('workspaces.validation.email_already_invited'),
        ])->errorBag('addWorkspaceMember');
    }

    private function ensureAddressIsNotAlreadyInvited(Workspace $workspace, string $email): Closure
    {
        return function ($validator) use ($workspace, $email): void { // @pest-ignore-type
            $validator->errors()->addIf(
                $workspace->workspaceInvitations()->unexpired()->where('email', $email)->exists(),
                'email',
                __('workspaces.validation.email_already_invited')
            );
        };
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
