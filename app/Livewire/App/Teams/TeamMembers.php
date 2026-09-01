<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\ResendTeamInvitation;
use App\Actions\Jetstream\RevokeTeamInvitation;
use App\Actions\Jetstream\UpdateTeamMemberRole;
use App\Enums\TeamRole;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use stdClass;

/**
 * One roster of everyone with access, joined or not: a pending invitation is a
 * row awaiting acceptance rather than a second list that appears and pushes the
 * page around. Rows are arrays, not models, because the two halves come from
 * different tables and neither can honestly stand in for the other.
 *
 * A row carries: __key, kind, name, email, role, is_owner, is_expired,
 * avatar_url, subtitle. Filament hands rows back untyped, so the few values the
 * columns and actions read are narrowed where they are read.
 *
 * No per-invitation copy-link action: issueToken() stores only a hash, so
 * copying could only re-mint and invalidate the link already in the inbox.
 */
final class TeamMembers extends BaseLivewireComponent implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    // The roster query scopes on $this->team->id alone and Membership carries no
    // global scope, so this property is what keeps it inside one workspace.
    #[Locked]
    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    #[On('teamInvitationSent')]
    public function refreshRoster(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, int|string $page, int|string $recordsPerPage): Collection|LengthAwarePaginator => $this->roster($search, $page, $recordsPerPage))
            ->searchable()
            ->searchPlaceholder(__('teams.table.search_placeholder'))
            ->paginated([10, 25, 50, 'all'])
            // The roster always holds the owner, so the only way to empty it is
            // a search that matches nobody.
            ->emptyStateIcon(Heroicon::OutlinedMagnifyingGlass)
            ->emptyStateHeading(__('teams.table.no_results.heading'))
            ->emptyStateDescription(__('teams.table.no_results.description'))
            ->columns([
                Tables\Columns\ViewColumn::make('identity')
                    ->label(__('teams.table.user'))
                    ->view('filament.tables.columns.roster-identity'),
                Tables\Columns\TextColumn::make('role')
                    ->label(__('teams.table.role'))
                    ->badge()
                    ->color(fn (array $record): string => $record['is_owner'] ? 'primary' : 'gray')
                    // State, not a format callback: the owner has no pivot row,
                    // and Filament skips formatting an empty state.
                    ->state(fn (array $record): string => $this->roleLabel($record)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('teams.table.status'))
                    ->badge()
                    // The identity cell already dates the invitation, so a phone
                    // loses the pill without losing the fact.
                    ->visibleFrom('sm')
                    ->color(fn (array $record): string => $record['is_expired'] ? 'danger' : 'info')
                    // Empty string, not null: Filament renders no badge for a
                    // blank state, so joined members keep a clean cell.
                    ->state(fn (array $record): string => match (true) {
                        $record['kind'] !== 'invitation' => '',
                        $record['is_expired'] => __('teams.table.invite_expired'),
                        default => __('teams.table.invite_pending'),
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->updateTeamRoleAction(),
                    $this->resendTeamInvitationAction(),
                    $this->removeTeamMemberAction(),
                    $this->revokeTeamInvitationAction(),
                    $this->leaveTeamAction(),
                ]),
            ]);
    }

    /**
     * @return Collection<string, array<string, mixed>>|LengthAwarePaginator<int, array<string, mixed>>
     */
    private function roster(?string $search, int|string $page, int|string $recordsPerPage): Collection|LengthAwarePaginator
    {
        $query = DB::query()
            ->fromSub($this->invitationRows()->unionAll($this->memberRows()), 'roster')
            ->orderBy('sort_group')
            ->orderBy('sort_key');

        if (filled($search)) {
            $query->where('search_blob', 'like', '%'.$this->escapeForLike(mb_strtolower($search)).'%');
        }

        if ($recordsPerPage === 'all') {
            return $this->present($query->get());
        }

        $rows = $query->paginate(perPage: (int) $recordsPerPage, page: (int) $page);

        return $rows->setCollection($this->present($rows->getCollection()));
    }

    /**
     * Ownership lives on teams.user_id, not the pivot, so the owner joins by a
     * second where leg with a null role. Selecting through users also drops
     * orphaned pivot rows, which production can hold and which 500 on render.
     */
    private function memberRows(): QueryBuilder
    {
        return DB::table('users')
            ->selectRaw(
                <<<'SQL'
                    users.id as id,
                    'member' as kind,
                    users.name as name,
                    users.email as email,
                    coalesce((select role from team_user where team_user.user_id = users.id and team_user.team_id = ? limit 1), '') as role,
                    null::timestamp as expires_at,
                    1 as sort_group,
                    lower(users.name) as sort_key,
                    lower(users.name || ' ' || users.email) as search_blob
                    SQL,
                [$this->team->id],
            )
            ->where(function (QueryBuilder $query): void {
                $query
                    ->where('users.id', $this->team->user_id)
                    ->orWhereExists(fn (QueryBuilder $exists): QueryBuilder => $exists
                        ->from('team_user')
                        ->whereColumn('team_user.user_id', 'users.id')
                        ->where('team_user.team_id', $this->team->id));
            });
    }

    // Sorted ahead of the members: an invitation is the row somebody still has
    // to act on, and there are never many of them.
    private function invitationRows(): QueryBuilder
    {
        return DB::table('team_invitations')
            ->selectRaw(
                <<<'SQL'
                    team_invitations.id as id,
                    'invitation' as kind,
                    null as name,
                    team_invitations.email as email,
                    team_invitations.role as role,
                    team_invitations.expires_at as expires_at,
                    0 as sort_group,
                    lower(team_invitations.email) as sort_key,
                    lower(team_invitations.email) as search_blob
                    SQL,
            )
            ->where('team_invitations.team_id', $this->team->id);
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<string, array<string, mixed>>
     */
    private function present(Collection $rows): Collection
    {
        $avatars = $this->avatarUrls($rows);

        return $rows->mapWithKeys(function (stdClass $row) use ($avatars): array {
            $key = (string) $row->id;
            $isInvitation = $row->kind === 'invitation';
            $expiresAt = is_string($row->expires_at) ? Date::parse($row->expires_at) : null;
            $isExpired = $isInvitation && ! $expiresAt?->isFuture();

            /** @var array<string, mixed> $entry */
            $entry = [
                '__key' => $key,
                'kind' => $isInvitation ? 'invitation' : 'member',
                'name' => $isInvitation ? null : (string) $row->name,
                'email' => (string) $row->email,
                'role' => (string) $row->role,
                'is_owner' => ! $isInvitation && $key === $this->team->user_id,
                'is_expired' => $isExpired,
                'avatar_url' => $avatars[$key] ?? null,
                'subtitle' => $this->subtitle($isInvitation, $expiresAt, (string) $row->email),
            ];

            return [$key => $entry];
        });
    }

    // Purely temporal, never a repeat of the status badge: the badge says which
    // state the invitation is in, this says since or until when.
    private function subtitle(bool $isInvitation, ?Carbon $expiresAt, string $email): ?string
    {
        if (! $isInvitation) {
            return $email;
        }

        if (! $expiresAt instanceof Carbon) {
            return null;
        }

        $elapsed = $expiresAt->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE);

        return $expiresAt->isFuture()
            ? __('teams.table.expires_in', ['time' => $elapsed])
            : __('teams.table.expired_ago', ['time' => $elapsed]);
    }

    /**
     * The avatar provider reads a user, so the page's members are loaded once
     * here rather than hydrated from roster rows that are half invitation.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, string>
     */
    private function avatarUrls(Collection $rows): array
    {
        $memberIds = $rows->filter(fn (stdClass $row): bool => $row->kind === 'member')
            ->map(fn (stdClass $row): string => (string) $row->id)
            ->all();

        if ($memberIds === []) {
            return [];
        }

        return User::query()
            ->whereKey($memberIds)
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                (string) $user->getKey() => Filament::getUserAvatarUrl($user),
            ])
            ->all();
    }

    private function escapeForLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function roleLabel(array $record): string
    {
        if ($record['is_owner'] === true) {
            return __('teams.roles.owner.label');
        }

        return TeamRole::label((string) $record['role']);
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    private function isMember(?array $record): bool
    {
        return $record !== null && $record['kind'] === 'member';
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    private function isInvitation(?array $record): bool
    {
        return $record !== null && $record['kind'] === 'invitation';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordKey(array $record): string
    {
        return (string) $record['__key'];
    }

    /**
     * The record key arrives from the client, so the team boundary is asserted
     * again here: a foreign or mistyped key is a 403, never a silent write to
     * somebody else's workspace.
     *
     * @param  array<string, mixed>|null  $record
     */
    private function findMember(?array $record): User
    {
        abort_unless($this->isMember($record) && $record !== null, 403);

        $member = User::query()
            ->whereKey($this->recordKey($record))
            ->where(function (EloquentBuilder $query): void {
                $query
                    ->whereKey($this->team->user_id)
                    ->orWhereExists(fn (QueryBuilder $exists): QueryBuilder => $exists
                        ->from('team_user')
                        ->whereColumn('team_user.user_id', 'users.id')
                        ->where('team_user.team_id', $this->team->id));
            })
            ->first();

        abort_unless($member instanceof User, 403);

        return $member;
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    private function findInvitation(?array $record): TeamInvitation
    {
        abort_unless($this->isInvitation($record) && $record !== null, 403);

        $invitation = TeamInvitation::query()
            ->whereKey($this->recordKey($record))
            ->where('team_id', $this->team->id)
            ->first();

        abort_unless($invitation instanceof TeamInvitation, 403);

        return $invitation;
    }

    /**
     * Only the owner may change or remove another Administrator, so those
     * actions are hidden on a peer Admin's row rather than offered and then
     * refused, matching how the owner row hides Leave.
     *
     * @param  array<string, mixed>  $record
     */
    private function canActOnRole(array $record): bool
    {
        if ((string) $record['role'] !== TeamRole::Admin->value) {
            return true;
        }

        return Gate::check('promoteToAdmin', $this->team);
    }

    private function updateTeamRoleAction(): Action
    {
        return Action::make('updateTeamRole')
            ->label(__('teams.actions.update_team_role'))
            ->icon('heroicon-m-user-circle')
            ->visible(fn (?array $record): bool => $this->isMember($record)
                && ! $record['is_owner']
                && $this->canActOnRole($record)
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
                    ->default(fn (array $record): string => (string) $record['role'])
                    ->rules([
                        fn (array $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            $touchesAdminStatus = $value === TeamRole::Admin->value
                                || $record['role'] === TeamRole::Admin->value;

                            if ($touchesAdminStatus && ! Gate::check('promoteToAdmin', $this->team)) {
                                $fail(__('teams.validation.only_owner_promotes_admins'));
                            }
                        },
                    ]),
            ])
            ->action(function (?array $record, array $data): void {
                $member = $this->findMember($record);

                try {
                    resolve(UpdateTeamMemberRole::class)->update(
                        $this->authUser(),
                        $this->team,
                        (string) $member->getKey(),
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
            ->icon('heroicon-m-user-minus')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (?array $record): bool => $this->isMember($record)
                && ! $record['is_owner']
                && $this->recordKey($record) !== (string) $this->authUser()->getKey()
                && $this->canActOnRole($record)
                && Gate::check('removeTeamMember', $this->team))
            ->action(function (?array $record): void {
                $member = $this->findMember($record);

                try {
                    resolve(RemoveTeamMember::class)->remove($this->authUser(), $this->team, $member);
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
            ->icon('heroicon-m-arrow-right-start-on-rectangle')
            ->color('danger')
            ->modalDescription(__('teams.modals.leave_team.notice'))
            ->requiresConfirmation()
            // Hidden on the owner row: RemoveTeamMember always rejects the owner,
            // so showing it could only ever produce an error.
            ->visible(fn (?array $record): bool => $this->isMember($record)
                && ! $record['is_owner']
                && $this->recordKey($record) === (string) $this->authUser()->getKey())
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

    private function resendTeamInvitationAction(): Action
    {
        return Action::make('resendTeamInvitation')
            ->label(__('teams.actions.resend_team_invitation'))
            ->icon('heroicon-m-paper-airplane')
            ->requiresConfirmation()
            ->visible(fn (?array $record): bool => $this->isInvitation($record)
                && Gate::check('updateTeamMember', $this->team))
            ->action(function (?array $record): void {
                Gate::authorize('updateTeamMember', $this->team);

                $invitation = $this->findInvitation($record);

                $key = "resend-invitation:{$invitation->getKey()}";

                if (RateLimiter::tooManyAttempts($key, 1)) {
                    $this->sendNotification(__('teams.notifications.resend_throttled', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]), type: 'warning');

                    return;
                }

                RateLimiter::hit($key, 60);

                resolve(ResendTeamInvitation::class)->resend($invitation);

                $this->sendNotification(__('teams.notifications.team_invitation_sent.success'));
                $this->resetTable();
            });
    }

    private function revokeTeamInvitationAction(): Action
    {
        return Action::make('revokeTeamInvitation')
            ->label(__('teams.actions.revoke_team_invitation'))
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (?array $record): bool => $this->isInvitation($record)
                && Gate::check('removeTeamMember', $this->team))
            ->action(function (?array $record): void {
                Gate::authorize('removeTeamMember', $this->team);

                resolve(RevokeTeamInvitation::class)->revoke($this->findInvitation($record));

                $this->sendNotification(__('teams.notifications.team_invitation_revoked.success'));
                $this->resetTable();
            });
    }

    public function render(): View
    {
        return view('livewire.app.teams.team-members');
    }
}
