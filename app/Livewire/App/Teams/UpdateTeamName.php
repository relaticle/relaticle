<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\UpdateTeamName as UpdateTeamNameAction;
use App\Filament\Pages\EditTeam;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

final class UpdateTeamName extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public Team $team;

    public bool $slugManuallyEdited = false;

    public function mount(Team $team): void
    {
        $this->team = $team;

        $this->form->fill($team->only(['name', 'slug']));
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
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($this->slugManuallyEdited) {
                                    return;
                                }

                                $set('slug', Str::slug((string) $state));
                            }),
                        TextInput::make('slug')
                            ->label(__('teams.form.team_slug.label'))
                            ->helperText(__('teams.form.team_slug.helper_text'))
                            ->string()
                            ->maxLength(255)
                            ->required()
                            ->rules(['min:3', 'regex:' . Team::SLUG_REGEX])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (): void {
                                $this->slugManuallyEdited = true;
                            })
                            ->dehydrateStateUsing(fn (?string $state): string => Str::slug((string) $state))
                            ->unique('teams', 'slug', ignorable: $this->team),
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
        $oldSlug = $team->slug;

        resolve(UpdateTeamNameAction::class)->update($this->authUser(), $team, $data);

        if ($team->slug !== $oldSlug) {
            $this->redirect(EditTeam::getUrl(tenant: $team));

            return;
        }

        $this->sendNotification();
    }

    public function render(): View
    {
        return view('livewire.app.teams.update-team-name');
    }
}
