<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\UpdateTeamName as UpdateTeamNameAction;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class UpdateTeamName extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;

        $this->form->fill($team->only(['name']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('teams.sections.update_team_name.title'))
                    ->aside()
                    ->description(__('teams.sections.update_team_name.description'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('teams.form.team_name.label'))
                            ->string()
                            ->maxLength(255)
                            ->required(),
                        Actions::make([
                            Action::make('save')
                                ->label(__('teams.actions.save'))
                                ->action(fn () => $this->updateTeamName($this->team)),
                        ])->alignEnd(),
                    ]),
            ])
            ->statePath('data');
    }

    public function updateTeamName(Team $team): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $data = $this->form->getState();

        resolve(UpdateTeamNameAction::class)->update($this->authUser(), $team, $data);

        $this->sendNotification();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.app.teams.update-team-name');
    }
}
