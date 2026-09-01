<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Team;

use App\Actions\Team\CreateTeamInvitation;
use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Relaticle\Chat\Tools\BaseWriteCreateTool;

final class InviteTeamMemberTool extends BaseWriteCreateTool
{
    public function description(): string
    {
        return 'Propose inviting one or more people to this workspace by email. Returns a proposal for user approval; approved invitations send the invite email.';
    }

    protected function actionClass(): string
    {
        return CreateTeamInvitation::class;
    }

    protected function entityType(): string
    {
        return 'team_invitations';
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
        return [
            'email' => $schema->string()->description('Email address to invite.')->required(),
            'role' => $schema->string()
                ->description('Workspace role: "editor" (default), "viewer", or "admin".'),
        ];
    }

    protected function extractRecordData(array $record): array
    {
        return [
            'email' => (string) ($record['email'] ?? ''),
            'role' => is_string($record['role'] ?? null) && $record['role'] !== ''
                ? $record['role']
                : TeamRole::Editor->value,
        ];
    }

    /**
     * InviteTeamMember re-authorizes at approval through Gate::authorize(), whose
     * AuthorizationException the proposal card does not catch, so an unauthorized
     * proposal would leave Approve a permanent no-op. Refusing here lets the
     * assistant say why instead, and the refusal names no page so the model
     * cannot invent a URL for one.
     */
    protected function validateRecord(array $record, User $user): ?string
    {
        $team = $user->currentTeam;

        if (! Gate::forUser($user)->allows('addTeamMember', $team)) {
            return __('Only workspace owners and administrators can invite teammates. Tell the user to ask one, and do not link to any page.');
        }

        $email = (string) ($record['email'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return "\"{$email}\" is not a valid email address.";
        }

        $role = $record['role'] ?? TeamRole::Editor->value;

        if (! in_array($role, [TeamRole::Editor->value, TeamRole::Viewer->value, TeamRole::Admin->value], true)) {
            return "Role must be \"editor\", \"viewer\", or \"admin\", got \"{$role}\".";
        }

        if ($role === TeamRole::Admin->value && ! Gate::forUser($user)->allows('promoteToAdmin', $team)) {
            return __('Only the workspace owner can grant the Administrator role. Tell the user to ask the owner, and do not link to any page.');
        }

        return null;
    }

    protected function buildRecordDisplay(array $record): array
    {
        $email = (string) ($record['email'] ?? '');
        $role = (string) ($record['role'] ?? TeamRole::Editor->value);

        return [
            'title' => 'Invite Teammate',
            'summary' => "Invite {$email} as {$role}",
            'fields' => [
                ['label' => 'Email', 'value' => $email],
                ['label' => 'Role', 'value' => $role],
            ],
        ];
    }
}
