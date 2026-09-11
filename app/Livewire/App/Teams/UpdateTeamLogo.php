<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;

final class UpdateTeamLogo extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->team)
            ->schema([
                Section::make(__('teams.sections.update_team_logo.title'))
                    ->aside()
                    ->description(__('teams.sections.update_team_logo.description'))
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('logo')
                            ->label(__('teams.form.team_logo.label'))
                            ->collection(Team::LOGO_MEDIA_COLLECTION)
                            ->image()
                            ->acceptedFileTypes(Team::LOGO_MIME_TYPES)
                            ->imageEditor()
                            ->avatar(),
                        Actions::make([
                            Action::make('save')
                                ->label(__('profile.actions.save'))
                                ->submit('updateLogo'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function updateLogo(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $this->form->getState();
        $this->form->saveRelationships();

        $this->sendNotification();
    }
}
