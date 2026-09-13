<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Models\Workspace;
use App\Support\EmailAddress;
use Closure;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

final readonly class AddWorkspaceMember implements AddsTeamMembers
{
    /**
     * Add a new workspace member to the given workspace.
     */
    public function add(User $user, Workspace $workspace, string $email, ?string $role = null): void
    {
        Gate::forUser($user)->authorize('addWorkspaceMember', $workspace);

        $email = EmailAddress::canonicalize($email);

        $this->validate($workspace, $email, $role);

        $newWorkspaceMember = Jetstream::findUserByEmailOrFail($email);

        event(new AddingTeamMember($workspace, $newWorkspaceMember));

        try {
            DB::transaction(function () use ($workspace, $newWorkspaceMember, $role, $email): void {
                $workspace->users()->attach($newWorkspaceMember, ['role' => $role]);

                // However they got here, any invitation to this workspace for the same
                // address is spent; leaving it strands a banner on every page.
                $workspace->workspaceInvitations()
                    ->whereRaw('lower(email) = ?', [Str::lower($email)])
                    ->delete();
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request already attached this member and fired the event.
            return;
        }

        event(new TeamMemberAdded($workspace, $newWorkspaceMember));
    }

    /**
     * Validate the add member operation.
     */
    private function validate(Workspace $workspace, string $email, ?string $role): void
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules(), [
            'email.exists' => __('We were unable to find a registered user with this email address.'),
        ])->after(
            $this->ensureUserIsNotAlreadyOnWorkspace($workspace, $email)
        )->validateWithBag('addWorkspaceMember');
    }

    /**
     * Get the validation rules for adding a workspace member.
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
