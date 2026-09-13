<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Livewire\App\Workspaces\DeleteWorkspace;
use App\Livewire\App\Workspaces\UpdateWorkspaceLogo;
use App\Livewire\App\Workspaces\UpdateWorkspaceName;
use App\Models\Workspace;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;

final class EditWorkspace extends EditTenantProfile
{
    use HasWorkspaceSettingsNavigation;

    protected string $view = 'filament.pages.edit-workspace';

    protected static ?string $slug = 'workspace';

    protected static ?int $navigationSort = 2;

    public function form(Schema $schema): Schema
    {
        /** @var Workspace $tenant */
        $tenant = $this->tenant;

        return $schema->components([
            Livewire::make(UpdateWorkspaceName::class)
                ->data(['workspace' => $tenant]),
            Livewire::make(UpdateWorkspaceLogo::class)
                ->data(['workspace' => $tenant]),
            Livewire::make(DeleteWorkspace::class)
                ->visible(fn (): bool => $tenant->isPersonalWorkspace() === false)
                ->data(['workspace' => $tenant]),
        ]);
    }

    public static function getLabel(): string
    {
        return __('workspaces.edit_workspace');
    }
}
