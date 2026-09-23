<?php

declare(strict_types=1);

namespace App\Support\Workspaces;

use App\Enums\WorkspaceCapability;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

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
     * @return array<string, string>
     */
    public static function forInviteLink(): array
    {
        return self::labels()
            ->except(WorkspaceRole::Admin->value)
            ->all();
    }

    /**
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

    public static function compareAction(): Action
    {
        return Action::make('compareRoles')
            ->label(__('workspaces.actions.compare_roles'))
            ->color('gray')
            ->link()
            ->modalHeading(__('workspaces.actions.compare_roles'))
            ->modalWidth('2xl')
            ->modalContent(fn (): View => view('livewire.app.workspaces.role-matrix', [
                'matrix' => self::matrix(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('workspaces.actions.close'));
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
