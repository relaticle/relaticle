<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\InviteTeamMember;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;

final class AddTeamMember extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;

        $this->form->fill($this->team->only(['name']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Section::make(__('teams.sections.add_team_member.title'))
                    ->aside()
                    ->visible(fn () => Gate::check('addTeamMember', $this->team))
                    ->description(__('teams.sections.add_team_member.description'))
                    ->schema([
                        TextEntry::make('addTeamMemberNotice')
                            ->hiddenLabel()
                            ->state(fn (): string => __('teams.sections.add_team_member.notice')),
                        TextInput::make('email')
                            ->label(__('teams.form.email.label'))
                            ->email()
                            ->required(),
                        Grid::make()
                            ->columns(1)
                            ->schema(function (): array {
                                $roles = collect(Jetstream::$roles);

                                return [
                                    Radio::make('role')
                                        ->hiddenLabel()
                                        ->required()
                                        ->in($roles->pluck('key'))
                                        ->options($roles->pluck('name', 'key'))
                                        ->descriptions($roles->pluck('description', 'key')),
                                ];
                            }),
                        Actions::make([
                            Action::make('addTeamMember')
                                ->label(__('teams.actions.add_team_member'))
                                ->action(function (): void {
                                    $this->addTeamMember($this->team);
                                }),
                        ])->alignEnd(),
                    ]),
            ]);
    }

    public function addTeamMember(Team $team): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $data = $this->form->getState();

        resolve(InviteTeamMember::class)->invite(
            $this->authUser(),
            $team,
            $data['email'],
            $data['role'] ?? null
        );

        $this->sendNotification(__('teams.notifications.team_invitation_sent.success'));

        $this->redirect(Filament::getTenantProfileUrl());
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.app.teams.add-team-member');
    }
}
