<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Team;

use App\Actions\Team\CreateTeamInvitation;
use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
                ->description('Workspace role: "editor" (default) or "admin".'),
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
     * Inviting is owner-only (TeamPolicy::addTeamMember). InviteTeamMember enforces
     * that again at approval, but through Gate::authorize(), whose
     * AuthorizationException the proposal card does not catch: without this the
     * Approve button would be a permanent no-op for anyone else. Refuse at
     * proposal time instead, so the assistant can say why.
     *
     * The refusal names no page. `Members::canAccess()` is `can('update', $tenant)`,
     * the exact complement of the guard below, so the Members page 403s for every
     * user who can reach this message: linking it would trade a hallucinated URL
     * (`app.relaticle.com/settings/members`, the bug that put a URL here at all)
     * for a real one that dead-ends just the same. Forbid any link instead, so the
     * model cannot invent one either.
     */
    protected function validateRecord(array $record, User $user): ?string
    {
        if (! $user->ownsTeam($user->currentTeam)) {
            return __('Only the workspace owner can invite teammates. Tell the user to ask an owner, and do not link to any page.');
        }

        $email = (string) ($record['email'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return "\"{$email}\" is not a valid email address.";
        }

        $role = $record['role'] ?? TeamRole::Editor->value;

        if (! in_array($role, [TeamRole::Editor->value, TeamRole::Admin->value], true)) {
            return "Role must be \"editor\" or \"admin\", got \"{$role}\".";
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
