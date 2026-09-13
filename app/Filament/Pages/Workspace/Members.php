<?php

declare(strict_types=1);

namespace App\Filament\Pages\Workspace;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Livewire\App\Workspaces\InviteWorkspaceMembers;
use App\Livewire\App\Workspaces\WorkspaceMembers;
use App\Models\Workspace;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Override;

/**
 * @property-read Schema $form
 */
final class Members extends Page
{
    use HasWorkspaceSettingsNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $slug = 'workspace/members';

    protected string $view = 'filament.pages.workspace.members';

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        /** @var Workspace $tenant */
        $tenant = Filament::getTenant();

        return auth()->user()?->can('manageMembers', $tenant) === true;
    }

    public static function getLabel(): string
    {
        return __('workspaces.tabs.members');
    }

    public function getTitle(): string
    {
        return __('workspaces.tabs.members');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        /** @var Workspace $tenant */
        $tenant = Filament::getTenant();

        return $schema->components([
            Livewire::make(InviteWorkspaceMembers::class)
                ->data(['workspace' => $tenant]),
            Livewire::make(WorkspaceMembers::class)
                ->data(['workspace' => $tenant]),
        ]);
    }
}
