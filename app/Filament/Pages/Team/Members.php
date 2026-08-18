<?php

declare(strict_types=1);

namespace App\Filament\Pages\Team;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Livewire\App\Teams\AddTeamMember;
use App\Livewire\App\Teams\PendingTeamInvitations;
use App\Livewire\App\Teams\TeamMembers;
use App\Models\Team;
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

    protected static ?string $slug = 'team/members';

    protected string $view = 'filament.pages.team.members';

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        /** @var Team $tenant */
        $tenant = Filament::getTenant();

        return auth()->user()?->can('manageMembers', $tenant) === true;
    }

    public static function getLabel(): string
    {
        return __('teams.tabs.members');
    }

    public function getTitle(): string
    {
        return __('teams.tabs.members');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        /** @var Team $tenant */
        $tenant = Filament::getTenant();

        return $schema->components([
            Livewire::make(AddTeamMember::class)
                ->data(['team' => $tenant]),
            Livewire::make(PendingTeamInvitations::class)
                ->data(['team' => $tenant]),
            Livewire::make(TeamMembers::class)
                ->data(['team' => $tenant]),
        ]);
    }
}
