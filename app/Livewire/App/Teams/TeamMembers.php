<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\RemoveTeamMember as RemoveTeamMemberAction;
use App\Livewire\BaseLivewireComponent;
use App\Models\Membership;
use App\Models\Team;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\TeamMemberUpdated;
use Laravel\Jetstream\Jetstream;

final class TeamMembers extends BaseLivewireComponent implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function table(Table $table): Table
    {
        $model = Membership::class;

        $teamForeignKeyColumn = 'team_id';

        return $table
            ->query(fn (): Builder => $model::with('user')->where($teamForeignKeyColumn, $this->team->id))
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\ImageColumn::make('profile_photo_url')
                        ->disk(config('jetstream.profile_photo_disk'))
                        ->defaultImageUrl(fn (Membership $record): string => Filament::getUserAvatarUrl($record->user))
                        ->circular()
                        ->imageSize(25)
                        ->grow(false),
                    Tables\Columns\TextColumn::make('user.email'),
                ]),
            ])
            ->paginated(false)
            ->recordActions([
                Action::make('updateTeamRole')
                    ->visible(fn (Membership $record): bool => Gate::check('updateTeamMember', $this->team))
                    ->label(fn (Membership $record): string => $record->roleName)
                    ->modalWidth('lg')
                    ->modalHeading(__('teams.actions.update_team_role'))
                    ->modalSubmitActionLabel(__('teams.actions.save'))
                    ->modalCancelAction(false)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->schema([
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
                                        ->descriptions($roles->pluck('description', 'key'))
                                        ->default(fn (Membership $record): string => $record->role),
                                ];
                            }),
                    ])
                    ->action(function (Membership $record, array $data): void {
                        $this->updateTeamRole($this->team, $record, $data);
                    }),
                Action::make('removeTeamMember')
                    ->visible(
                        fn (Membership $record): bool => (string) $this->authUser()->id !== (string) $record->user_id && Gate::check(
                            'removeTeamMember',
                            $this->team
                        )
                    )
                    ->label(__('teams.actions.remove_team_member'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Membership $record): void {
                        $this->removeTeamMember($this->team, $record);
                    }),
                Action::make('leaveTeam')
                    ->visible(fn (Membership $record): bool => (string) $this->authUser()->id === (string) $record->user_id)
                    ->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->color('danger')
                    ->label(__('teams.actions.leave_team'))
                    ->modalDescription(__('teams.modals.leave_team.notice'))
                    ->requiresConfirmation()
                    ->action(function (Membership $record): void {
                        $this->leaveTeam($this->team);
                    }),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTeamRole(Team $team, Membership $teamMember, array $data): void
    {
        if (! Gate::check('updateTeamMember', $team)) {
            $this->sendNotification(
                __('teams.notifications.permission_denied.cannot_update_team_member'),
                type: 'danger'
            );

            return;
        }

        $team->users()->updateExistingPivot($teamMember->user_id, ['role' => $data['role']]);

        TeamMemberUpdated::dispatch($team->fresh(), $teamMember);

        $this->sendNotification();

        $team->fresh();
    }

    public function removeTeamMember(Team $team, Membership $teamMember): void
    {
        try {
            app(RemoveTeamMemberAction::class)->remove($this->authUser(), $team, $teamMember->user);

            $this->sendNotification(__('teams.notifications.team_member_removed.success'));

            $team->fresh();
        } catch (AuthorizationException) {
            $this->sendNotification(
                __('teams.notifications.permission_denied.cannot_remove_team_member'),
                type: 'danger'
            );
        } catch (ValidationException $e) {
            $this->sendNotification(
                $e->validator->errors()->first(),
                type: 'danger'
            );
        }
    }

    public function leaveTeam(Team $team): void
    {
        $teamMember = $this->authUser();

        try {
            app(RemoveTeamMemberAction::class)->remove($teamMember, $team, $teamMember);

            $this->sendNotification(__('teams.notifications.leave_team.success'));

            $this->redirect(Filament::getHomeUrl());
        } catch (AuthorizationException) {
            $this->sendNotification(
                title: __('teams.notifications.permission_denied.cannot_leave_team'),
                type: 'danger'
            );
        } catch (ValidationException $e) {
            $this->sendNotification(
                $e->validator->errors()->first(),
                type: 'danger'
            );
        }
    }

    public function render(): View
    {
        return view('livewire.app.teams.team-members');
    }
}
