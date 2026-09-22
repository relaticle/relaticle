<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Workspace;

use App\Actions\Workspace\CreateWorkspaceInvitation;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Relaticle\Chat\Tools\BaseWriteCreateTool;

final class InviteWorkspaceMemberTool extends BaseWriteCreateTool
{
    public function description(): string
    {
        return 'Propose inviting one or more people to this workspace by email. Returns a proposal for user approval; approved invitations send the invite email.';
    }

    protected function actionClass(): string
    {
        return CreateWorkspaceInvitation::class;
    }

    protected function entityType(): string
    {
        return 'workspace_invitations';
    }

    protected function ownedForeignKeys(): array
    {
        return [];
    }

    /**
     * Invitations are named by email, not `name`: the base's default name
     * check would otherwise reject every record for missing a field this
     * entity never has.
     */
    protected function nameAttribute(): string
    {
        return 'email';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        $roleValues = implode(', ', array_map(
            fn (WorkspaceRole $role): string => "\"{$role->value}\"",
            WorkspaceRole::cases(),
        ));

        return [
            'email' => $schema->string()->description('Email address to invite.')->required(),
            'role' => $schema->string()
                ->description("Workspace role: {$roleValues}. Defaults to \"".WorkspaceRole::Member->value.'".'),
        ];
    }

    protected function extractRecordData(array $record): array
    {
        return [
            'email' => (string) ($record['email'] ?? ''),
            'role' => is_string($record['role'] ?? null) && $record['role'] !== ''
                ? $record['role']
                : WorkspaceRole::Member->value,
        ];
    }

    /**
     * InviteWorkspaceMember re-authorizes at approval through Gate::authorize(), whose
     * AuthorizationException the proposal card does not catch, so an unauthorized
     * proposal would leave Approve a permanent no-op. Refusing here lets the
     * assistant say why instead, and the refusal names no page so the model
     * cannot invent a URL for one.
     */
    protected function validateRecord(array $record, User $user): ?string
    {
        $workspace = $user->currentWorkspace;

        if (! Gate::forUser($user)->allows('addWorkspaceMember', $workspace)) {
            return __('Only workspace owners and admins can invite teammates. Tell the user to ask one, and do not link to any page.');
        }

        $email = (string) ($record['email'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return __(':email is not a valid email address.', ['email' => "\"{$email}\""]);
        }

        $role = $record['role'] ?? WorkspaceRole::Member->value;
        $validRoles = array_map(fn (WorkspaceRole $case): string => $case->value, WorkspaceRole::cases());

        if (! in_array($role, $validRoles, true)) {
            return __('Role must be one of :roles, got :role.', [
                'roles' => implode(', ', array_map(fn (string $value): string => "\"{$value}\"", $validRoles)),
                'role' => "\"{$role}\"",
            ]);
        }

        if (WorkspaceRole::keyIsAdmin($role) && ! Gate::forUser($user)->allows('promoteToAdmin', $workspace)) {
            return __('Only the workspace owner can grant the Admin role. Tell the user to ask the owner, and do not link to any page.');
        }

        return null;
    }

    protected function buildRecordDisplay(array $record): array
    {
        $email = (string) ($record['email'] ?? '');
        $role = WorkspaceRole::tryFrom((string) ($record['role'] ?? WorkspaceRole::Member->value)) ?? WorkspaceRole::Member;
        $roleLabel = $role->label();

        return [
            'title' => 'Invite Teammate',
            'summary' => "Invite {$email} as {$roleLabel}",
            'fields' => [
                ['label' => 'Email', 'value' => $email],
                ['label' => 'Role', 'value' => $roleLabel],
            ],
        ];
    }
}
