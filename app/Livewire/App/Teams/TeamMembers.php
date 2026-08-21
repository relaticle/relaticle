<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\UpdateTeamMemberRole;
use App\Enums\TeamRole;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;

/**
 * The workspace's people directory. Inviting lives in InviteTeamMembers and
 * pending invitations in PendingTeamInvitations — three sections down the page,
 * so the roster stays a roster and the actionable sets are not buried inside
 * it. Merging them into one paginated table hid pending invites behind
 * pagination, which is why Twenty and Slack keep them apart too.
 */
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
        return $table
            ->query($this->membersQuery(...))
            ->paginated(false)
            ->defaultSort('name')
            // Split rather than discrete columns: this is a short roster, and a
            // header row over two fields reads as table chrome around a list.
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\ImageColumn::make('profile_photo_url')
                        ->circular()
                        ->imageSize(32)
                        ->defaultImageUrl(fn (User $record): string => Filament::getUserAvatarUrl($record))
                        ->grow(false),
                    Tables\Columns\TextColumn::make('name')
                        ->description(fn (User $record): string => $record->email),
                    // Every other row wears its role on the updateTeamRole button,
                    // which the owner never gets — this keeps their row labelled
                    // without opening a role-change surface on it.
                    Tables\Columns\TextColumn::make('owner_label')
                        ->badge()
                        ->color('primary')
                        ->grow(false)
                        // Empty string, not null: Filament renders no badge for a
                        // blank state, so non-owner rows stay clean.
                        ->state(fn (User $record): string => $this->isOwner($record)
                            ? __('teams.roles.owner.label')
                            : ''),
                ]),
            ])
            ->recordActions([
                $this->updateTeamRoleAction(),
                $this->removeTeamMemberAction(),
                $this->leaveTeamAction(),
            ]);
    }

    /**
     * Owner and members in one list, without the raw-SQL union the merged table
     * needed: Jetstream tracks ownership on `teams.user_id` rather than the
     * `team_user` pivot, so the owner is pulled in by a second `where` leg and
     * carries a null `team_role`.
     *
     * Selecting through `users` also drops orphaned pivot rows structurally —
     * production is missing the `team_user` foreign keys, so a deleted account
     * can leave a row whose user is gone, and `Filament::getUserAvatarUrl()` is
     * typed non-nullable and 500s on it.
     *
     * @return Builder<User>
     */
    private function membersQuery(): Builder
    {
        $pivot = fn (string $column): QueryBuilder => DB::table('team_user')
            ->select($column)
            ->whereColumn('team_user.user_id', 'users.id')
            ->where('team_user.team_id', $this->team->id)
            ->limit(1);

        return User::query()
            ->select('users.*')
            ->addSelect([
                'team_role' => $pivot('role'),
                'joined_at' => $pivot('created_at'),
            ])
            ->where(function (Builder $query): void {
                $query
                    ->whereKey($this->team->user_id)
                    ->orWhereExists(fn (QueryBuilder $exists): QueryBuilder => $exists
                        ->from('team_user')
                        ->whereColumn('team_user.user_id', 'users.id')
                        ->where('team_user.team_id', $this->team->id));
            });
    }

    private function isOwner(User $record): bool
    {
        return $record->getKey() === $this->team->user_id;
    }

    /**
     * `team_role` is a per-query select, not a column on `users`, so it is read
     * through the attribute bag rather than declared on the model. It is null on
     * the owner row, which has no `team_user` pivot to select from.
     */
    private function roleKey(User $record): ?string
    {
        $role = $record->getAttribute('team_role');

        return is_string($role) ? $role : null;
    }

    /**
     * Falls back to the raw role string for a legacy or unregistered role key
     * rather than throwing — Jetstream::findRole()'s untyped PHPDoc return
     * makes PHPStan misjudge a plain `?->` chain as never-null here.
     */
    private function roleLabel(string $role): string
    {
        $registeredRole = Jetstream::findRole($role);

        if ($registeredRole === null) {
            return $role;
        }

        return $registeredRole->name;
    }

    private function updateTeamRoleAction(): Action
    {
        return Action::make('updateTeamRole')
            // The button reads as the member's current role, so the row shows the
            // role without a column for it and the click target is the thing you
            // would change. This is how the page worked before the rewrite.
            ->label(fn (User $record): string => $this->roleLabel($this->roleKey($record) ?? ''))
            ->badge()
            ->color('gray')
            ->visible(fn (User $record): bool => ! $this->isOwner($record)
                && Gate::check('updateTeamMember', $this->team))
            ->modalHeading(__('teams.actions.update_team_role'))
            ->modalWidth('lg')
            ->schema([
                Radio::make('role')
                    ->hiddenLabel()
                    ->required()
                    ->options(fn (): array => $this->assignableRoles())
                    ->in(fn (): array => array_keys($this->assignableRoles()))
                    ->descriptions(fn (): array => collect(Jetstream::$roles)
                        ->only(array_keys($this->assignableRoles()))
                        ->pluck('description', 'key')
                        ->all())
                    ->default(fn (User $record): string => $this->roleKey($record) ?? '')
                    ->rules([
                        fn (User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            $touchesAdminStatus = $value === TeamRole::Admin->value
                                || $this->roleKey($record) === TeamRole::Admin->value;

                            if ($touchesAdminStatus && ! Gate::check('promoteToAdmin', $this->team)) {
                                $fail(__('teams.validation.only_owner_promotes_admins'));
                            }
                        },
                    ]),
            ])
            ->action(function (User $record, array $data): void {
                try {
                    resolve(UpdateTeamMemberRole::class)->update(
                        $this->authUser(),
                        $this->team,
                        (string) $record->getKey(),
                        $data['role'],
                    );

                    $this->sendNotification(__('teams.notifications.role_updated.success'));
                } catch (AuthorizationException) {
                    $this->sendNotification(
                        __('teams.notifications.permission_denied.cannot_promote_to_admin'),
                        type: 'danger'
                    );
                }

                $this->resetTable();
            });
    }

    /**
     * @return array<string, string>
     */
    private function assignableRoles(): array
    {
        $roles = collect(Jetstream::$roles)->pluck('name', 'key');

        if (! Gate::check('promoteToAdmin', $this->team)) {
            $roles = $roles->except(TeamRole::Admin->value);
        }

        return $roles->all();
    }

    private function removeTeamMemberAction(): Action
    {
        return Action::make('removeTeamMember')
            ->label(__('teams.actions.remove_team_member'))
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (User $record): bool => ! $this->isOwner($record)
                && $record->getKey() !== $this->authUser()->getKey()
                && Gate::check('removeTeamMember', $this->team))
            ->action(function (User $record): void {
                try {
                    resolve(RemoveTeamMember::class)->remove($this->authUser(), $this->team, $record);
                    $this->sendNotification(__('teams.notifications.team_member_removed.success'));
                } catch (AuthorizationException) {
                    $this->sendNotification(__('teams.notifications.permission_denied.cannot_remove_team_member'), type: 'danger');
                } catch (ValidationException $exception) {
                    $this->sendNotification($exception->validator->errors()->first(), type: 'danger');
                }

                $this->resetTable();
            });
    }

    private function leaveTeamAction(): Action
    {
        return Action::make('leaveTeam')
            ->label(__('teams.actions.leave_team'))
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('danger')
            ->modalDescription(__('teams.modals.leave_team.notice'))
            ->requiresConfirmation()
            // Hidden on the owner row: RemoveTeamMember always rejects the owner,
            // so showing it could only ever produce an error (defect A8).
            ->visible(fn (User $record): bool => ! $this->isOwner($record)
                && $record->getKey() === $this->authUser()->getKey())
            ->action(function (): void {
                $user = $this->authUser();

                try {
                    resolve(RemoveTeamMember::class)->remove($user, $this->team, $user);
                    $this->sendNotification(__('teams.notifications.leave_team.success'));
                    $this->redirect(Filament::getHomeUrl());
                } catch (ValidationException $exception) {
                    $this->sendNotification($exception->validator->errors()->first(), type: 'danger');
                }
            });
    }

    public function render(): View
    {
        return view('livewire.app.teams.team-members');
    }
}
