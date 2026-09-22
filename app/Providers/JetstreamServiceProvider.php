<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Jetstream\AddWorkspaceMember;
use App\Actions\Jetstream\CreateWorkspace;
use App\Actions\Jetstream\DeleteUser;
use App\Actions\Jetstream\DeleteWorkspace;
use App\Actions\Jetstream\InviteWorkspaceMember;
use App\Actions\Jetstream\RemoveWorkspaceMember;
use App\Actions\Jetstream\UpdateWorkspaceName;
use App\Enums\WorkspaceCapability;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Profile\DeleteAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;

final class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Jetstream::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureModels();
        $this->configurePermissions();
        $this->configureActions();

        Livewire::component('profile.delete-user-form', DeleteAccount::class);
    }

    /**
     * Configure the models that Jetstream uses.
     */
    private function configureModels(): void
    {
        Jetstream::useUserModel(User::class);
        Jetstream::useTeamModel(Workspace::class);
        Jetstream::useTeamInvitationModel(WorkspaceInvitation::class);
    }

    /**
     * Configure the actions that are available within the application.
     */
    private function configureActions(): void
    {
        Jetstream::createTeamsUsing(CreateWorkspace::class);
        Jetstream::updateTeamNamesUsing(UpdateWorkspaceName::class);
        Jetstream::addTeamMembersUsing(AddWorkspaceMember::class);
        Jetstream::inviteTeamMembersUsing(InviteWorkspaceMember::class);
        Jetstream::removeTeamMembersUsing(RemoveWorkspaceMember::class);
        Jetstream::deleteTeamsUsing(DeleteWorkspace::class);
        Jetstream::deleteUsersUsing(DeleteUser::class);
    }

    /**
     * Configure the roles and permissions that are available within the application.
     */
    private function configurePermissions(): void
    {
        Jetstream::defaultApiTokenPermissions(['read']);

        foreach (WorkspaceRole::cases() as $role) {
            Jetstream::role($role->value, $role->label(), $this->tokenPermissions($role))
                ->description($role->description());
        }
    }

    /** @return array<int, string> */
    private function tokenPermissions(WorkspaceRole $role): array
    {
        $capabilities = $role->capabilities();

        return array_values(array_filter([
            'read',
            in_array(WorkspaceCapability::RecordsCreate, $capabilities, true) ? 'create' : null,
            in_array(WorkspaceCapability::RecordsUpdate, $capabilities, true) ? 'update' : null,
            in_array(WorkspaceCapability::RecordsDelete, $capabilities, true) ? 'delete' : null,
        ]));
    }
}
