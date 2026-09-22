<?php

declare(strict_types=1);

namespace App\Support\Workspaces;

use App\Enums\WorkspaceCapability;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * The only source of assignable roles, their labels, and their hints, so an
 * invite picker, a role-change picker, and the invite-link default can never
 * drift from one another or from the capability map behind them.
 */
final readonly class RoleOptions
{
    /**
     * @return array<string, string>
     */
    public static function assignable(User $user, Workspace $workspace): array
    {
        $roles = self::labels();

        if (! $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::MembersPromoteAdmin)) {
            $roles = $roles->except(WorkspaceRole::Admin->value);
        }

        return $roles->all();
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return collect(WorkspaceRole::cases())
            ->mapWithKeys(fn (WorkspaceRole $role): array => [$role->value => $role->description()])
            ->all();
    }

    /**
     * The link can only ever grant Member or Viewer: Admin access
     * always names the person it went to, through an emailed invite.
     *
     * @return array<string, string>
     */
    public static function forInviteLink(User $user, Workspace $workspace): array
    {
        return collect(self::assignable($user, $workspace))
            ->except(WorkspaceRole::Admin->value)
            ->all();
    }

    /**
     * Capability value to role key to granted, Owner included even though it
     * carries no `WorkspaceRole` case, so a row only Owner holds never reads
     * as something nobody can do.
     *
     * @return array<string, array<string, bool>>
     */
    public static function matrix(): array
    {
        $roleCapabilities = collect(WorkspaceRole::cases())
            ->mapWithKeys(fn (WorkspaceRole $role): array => [$role->value => $role->capabilities()])
            ->prepend(WorkspaceCapability::forOwner(), 'owner');

        return collect(WorkspaceCapability::cases())
            ->mapWithKeys(fn (WorkspaceCapability $capability): array => [
                $capability->value => $roleCapabilities
                    ->map(fn (array $granted): bool => in_array($capability, $granted, true))
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return Collection<string, string>
     */
    private static function labels(): Collection
    {
        return collect(WorkspaceRole::cases())
            ->mapWithKeys(fn (WorkspaceRole $role): array => [$role->value => $role->label()]);
    }
}
