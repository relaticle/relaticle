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
            ->icon('heroicon-m-table-cells')
            ->link()
            ->modalHeading(__('workspaces.actions.compare_roles'))
            ->modalWidth('2xl')
            ->modalContent(fn (): View => view('livewire.app.workspaces.role-matrix', [
                'matrix' => self::matrix(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelAction(fn (Action $action): Action => $action
                ->label(__('workspaces.actions.close'))
                ->extraAttributes(['autofocus' => true]))
            ->extraModalFooterActions([
                Action::make('readRolesHelp')
                    ->label(__('workspaces.actions.compare_roles_help_link'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconPosition('after')
                    ->link()
                    ->url(url()->getPublicUrl(route('help.show', ['category' => 'workspace', 'slug' => 'manage-members-and-roles'], false)))
                    ->openUrlInNewTab()
                    ->extraAttributes(['aria-label' => __('workspaces.actions.compare_roles_help_link').' '.__('workspaces.role_matrix.opens_in_new_tab')]),
            ]);
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
