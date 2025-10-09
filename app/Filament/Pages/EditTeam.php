<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Livewire\App\Teams\AddTeamMember;
use App\Livewire\App\Teams\DeleteTeam;
use App\Livewire\App\Teams\PendingTeamInvitations;
use App\Livewire\App\Teams\TeamMembers;
use App\Livewire\App\Teams\UpdateTeamName;
use App\Models\Team;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;

final class EditTeam extends EditTenantProfile
{
    protected string $view = 'filament.pages.edit-team';

    protected static ?string $slug = 'team';

    protected static ?int $navigationSort = 2;

    public function form(Schema $schema): Schema
    {
        /** @var Team $tenant */
        $tenant = $this->tenant;

        return $schema->components([
            Livewire::make(UpdateTeamName::class)
                ->data(['team' => $tenant]),
            Livewire::make(AddTeamMember::class)
                ->data(['team' => $tenant]),
            Livewire::make(PendingTeamInvitations::class)
                ->data(['team' => $tenant]),
            Livewire::make(TeamMembers::class)
                ->data(['team' => $tenant]),
            Livewire::make(DeleteTeam::class)
                ->visible(fn (): bool => $tenant->isPersonalTeam() === false)
                ->data(['team' => $tenant]),
        ]);
    }

    public static function getLabel(): string
    {
        return __('teams.edit_team');
    }
}
