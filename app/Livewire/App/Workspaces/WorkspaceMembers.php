<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Actions\Jetstream\RemoveWorkspaceMember;
use App\Actions\Jetstream\ResendWorkspaceInvitation;
use App\Actions\Jetstream\RevokeWorkspaceInvitation;
use App\Actions\Jetstream\UpdateWorkspaceMemberRole;
use App\Enums\WorkspaceRole;
use App\Livewire\BaseLivewireComponent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Carbon\CarbonImmutable;
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
final class WorkspaceMembers extends BaseLivewireComponent implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    // The roster query scopes on $this->workspace->id alone and Membership carries no
    // global scope, so this property is what keeps it inside one workspace.
    #[Locked]
    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    #[On('workspaceInvitationSent')]
    public function refreshRoster(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, int|string $page, int|string $recordsPerPage): Collection|LengthAwarePaginator => $this->roster($search, $page, $recordsPerPage))
            ->searchable()
            ->searchPlaceholder(__('workspaces.table.search_placeholder'))
            ->paginated([10, 25, 50, 'all'])
            // The roster always holds the owner, so the only way to empty it is
            // a search that matches nobody.
            ->emptyStateIcon(Heroicon::OutlinedMagnifyingGlass)
            ->emptyStateHeading(__('workspaces.table.no_results.heading'))
            ->emptyStateDescription(__('workspaces.table.no_results.description'))
            ->columns([
                Tables\Columns\ViewColumn::make('identity')
                    ->label(__('workspaces.table.user'))
                    ->view('filament.tables.columns.roster-identity'),
                Tables\Columns\TextColumn::make('role')
                    ->label(__('workspaces.table.role'))
                    ->badge()
                    ->color(fn (array $record): string => $record['is_owner'] ? 'primary' : 'gray')
                    // State, not a format callback: the owner has no pivot row,
                    // and Filament skips formatting an empty state.
                    ->state(fn (array $record): string => $this->roleLabel($record)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('workspaces.table.status'))
                    ->badge()
                    // The identity cell already dates the invitation, so a phone
                    // loses the pill without losing the fact.
                    ->visibleFrom('sm')
                    ->color(fn (array $record): string => $record['is_expired'] ? 'danger' : 'info')
                    // Empty string, not null: Filament renders no badge for a
                    // blank state, so joined members keep a clean cell.
                    ->state(fn (array $record): string => match (true) {
                        $record['kind'] !== 'invitation' => '',
                        $record['is_expired'] => __('workspaces.table.invite_expired'),
                        default => __('workspaces.table.invite_pending'),
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->updateWorkspaceRoleAction(),
                    $this->resendWorkspaceInvitationAction(),
                    $this->removeWorkspaceMemberAction(),
                    $this->revokeWorkspaceInvitationAction(),
                    $this->leaveWorkspaceAction(),
                ]),
            ]);
    }

    /**
     * @return Collection<string, array<string, mixed>>|LengthAwarePaginator<string, array<string, mixed>>
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
     * Ownership lives on workspaces.user_id, not the pivot, so the owner joins by a
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
                    coalesce((select role from workspace_user where workspace_user.user_id = users.id and workspace_user.workspace_id = ? limit 1), '') as role,
                    null::timestamp as expires_at,
                    1 as sort_group,
                    lower(users.name) as sort_key,
                    lower(users.name || ' ' || users.email) as search_blob
                    SQL,
                [$this->workspace->id],
            )
            ->where(function (QueryBuilder $query): void {
                $query
                    ->where('users.id', $this->workspace->user_id)
                    ->orWhereExists(fn (QueryBuilder $exists): QueryBuilder => $exists
                        ->from('workspace_user')
                        ->whereColumn('workspace_user.user_id', 'users.id')
                        ->where('workspace_user.workspace_id', $this->workspace->id));
            });
    }

    // Sorted ahead of the members: an invitation is the row somebody still has
    // to act on, and there are never many of them.
    private function invitationRows(): QueryBuilder
    {
        return DB::table('workspace_invitations')
            ->selectRaw(
                <<<'SQL'
                    workspace_invitations.id as id,
                    'invitation' as kind,
                    null as name,
                    workspace_invitations.email as email,
                    workspace_invitations.role as role,
                    workspace_invitations.expires_at as expires_at,
                    0 as sort_group,
                    lower(workspace_invitations.email) as sort_key,
                    lower(workspace_invitations.email) as search_blob
                    SQL,
            )
            ->where('workspace_invitations.workspace_id', $this->workspace->id);
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
                'is_owner' => ! $isInvitation && $key === $this->workspace->user_id,
                'is_expired' => $isExpired,
                'avatar_url' => $avatars[$key] ?? null,
                'subtitle' => $this->subtitle($isInvitation, $expiresAt, (string) $row->email),
            ];

            return [$key => $entry];
        });
    }

    // Purely temporal, never a repeat of the status badge: the badge says which
    // state the invitation is in, this says since or until when.
    private function subtitle(bool $isInvitation, ?CarbonImmutable $expiresAt, string $email): ?string
    {
        if (! $isInvitation) {
            return $email;
        }

        if (! $expiresAt instanceof CarbonImmutable) {
            return null;
        }

        $elapsed = $expiresAt->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE);

        return $expiresAt->isFuture()
            ? __('workspaces.table.expires_in', ['time' => $elapsed])
            : __('workspaces.table.expired_ago', ['time' => $elapsed]);
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
            return __('workspaces.roles.owner.label');
        }

        return WorkspaceRole::label((string) $record['role']);
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
     * The record key arrives from the client, so the workspace boundary is asserted
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
                    ->whereKey($this->workspace->user_id)
                    ->orWhereExists(fn (QueryBuilder $exists): QueryBuilder => $exists
                        ->from('workspace_user')
                        ->whereColumn('workspace_user.user_id', 'users.id')
                        ->where('workspace_user.workspace_id', $this->workspace->id));
            })
            ->first();

        abort_unless($member instanceof User, 403);

        return $member;
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    private function findInvitation(?array $record): WorkspaceInvitation
    {
        abort_unless($this->isInvitation($record) && $record !== null, 403);

        $invitation = WorkspaceInvitation::query()
            ->whereKey($this->recordKey($record))
            ->where('workspace_id', $this->workspace->id)
            ->first();

        abort_unless($invitation instanceof WorkspaceInvitation, 403);

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
        if ((string) $record['role'] !== WorkspaceRole::Admin->value) {
            return true;
        }

        return Gate::check('promoteToAdmin', $this->workspace);
    }

    private function updateWorkspaceRoleAction(): Action
    {
        return Action::make('updateWorkspaceRole')
            ->label(__('workspaces.actions.update_workspace_role'))
            ->icon('heroicon-m-user-circle')
            ->visible(fn (?array $record): bool => $this->isMember($record)
                && ! $record['is_owner']
                && $this->canActOnRole($record)
                && Gate::check('updateWorkspaceMember', $this->workspace))
            ->modalHeading(__('workspaces.actions.update_workspace_role'))
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
                            $touchesAdminStatus = $value === WorkspaceRole::Admin->value
                                || $record['role'] === WorkspaceRole::Admin->value;

                            if ($touchesAdminStatus && ! Gate::check('promoteToAdmin', $this->workspace)) {
                                $fail(__('workspaces.validation.only_owner_promotes_admins'));
                            }
                        },
                    ]),
            ])
            ->action(function (?array $record, array $data): void {
                $member = $this->findMember($record);

                try {
                    resolve(UpdateWorkspaceMemberRole::class)->update(
                        $this->authUser(),
                        $this->workspace,
                        (string) $member->getKey(),
                        $data['role'],
                    );

                    $this->sendNotification(__('workspaces.notifications.role_updated.success'));
                } catch (AuthorizationException) {
                    $this->sendNotification(
                        __('workspaces.notifications.permission_denied.cannot_promote_to_admin'),
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

        if (! Gate::check('promoteToAdmin', $this->workspace)) {
            $roles = $roles->except(WorkspaceRole::Admin->value);
        }

        return $roles->all();
    }

    private function removeWorkspaceMemberAction(): Action
    {
        return Action::make('removeWorkspaceMember')
            ->label(__('workspaces.actions.remove_workspace_member'))
            ->icon('heroicon-m-user-minus')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (?array $record): bool => $this->isMember($record)
                && ! $record['is_owner']
                && $this->recordKey($record) !== (string) $this->authUser()->getKey()
                && $this->canActOnRole($record)
                && Gate::check('removeWorkspaceMember', $this->workspace))
            ->action(function (?array $record): void {
                $member = $this->findMember($record);

                try {
                    resolve(RemoveWorkspaceMember::class)->remove($this->authUser(), $this->workspace, $member);
                    $this->sendNotification(__('workspaces.notifications.workspace_member_removed.success'));
                } catch (AuthorizationException) {
                    $this->sendNotification(__('workspaces.notifications.permission_denied.cannot_remove_workspace_member'), type: 'danger');
                } catch (ValidationException $exception) {
                    $this->sendNotification($exception->validator->errors()->first(), type: 'danger');
                }

                $this->resetTable();
            });
    }

    private function leaveWorkspaceAction(): Action
    {
        return Action::make('leaveWorkspace')
            ->label(__('workspaces.actions.leave_workspace'))
            ->icon('heroicon-m-arrow-right-start-on-rectangle')
            ->color('danger')
            ->modalDescription(__('workspaces.modals.leave_workspace.notice'))
            ->requiresConfirmation()
            // Hidden on the owner row: RemoveWorkspaceMember always rejects the owner,
            // so showing it could only ever produce an error.
            ->visible(fn (?array $record): bool => $this->isMember($record)
                && ! $record['is_owner']
                && $this->recordKey($record) === (string) $this->authUser()->getKey())
            ->action(function (): void {
                $user = $this->authUser();

                try {
                    resolve(RemoveWorkspaceMember::class)->remove($user, $this->workspace, $user);
                    $this->sendNotification(__('workspaces.notifications.leave_workspace.success'));
                    $this->redirect(Filament::getHomeUrl());
                } catch (ValidationException $exception) {
                    $this->sendNotification($exception->validator->errors()->first(), type: 'danger');
                }
            });
    }

    private function resendWorkspaceInvitationAction(): Action
    {
        return Action::make('resendWorkspaceInvitation')
            ->label(__('workspaces.actions.resend_workspace_invitation'))
            ->icon('heroicon-m-paper-airplane')
            ->requiresConfirmation()
            ->visible(fn (?array $record): bool => $this->isInvitation($record)
                && Gate::check('updateWorkspaceMember', $this->workspace))
            ->action(function (?array $record): void {
                Gate::authorize('updateWorkspaceMember', $this->workspace);

                $invitation = $this->findInvitation($record);

                $key = "resend-invitation:{$invitation->getKey()}";

                if (RateLimiter::tooManyAttempts($key, 1)) {
                    $this->sendNotification(__('workspaces.notifications.resend_throttled', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]), type: 'warning');

                    return;
                }

                RateLimiter::hit($key, 60);

                resolve(ResendWorkspaceInvitation::class)->resend($invitation);

                $this->sendNotification(__('workspaces.notifications.workspace_invitation_sent.success'));
                $this->resetTable();
            });
    }

    private function revokeWorkspaceInvitationAction(): Action
    {
        return Action::make('revokeWorkspaceInvitation')
            ->label(__('workspaces.actions.revoke_workspace_invitation'))
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (?array $record): bool => $this->isInvitation($record)
                && Gate::check('removeWorkspaceMember', $this->workspace))
            ->action(function (?array $record): void {
                Gate::authorize('removeWorkspaceMember', $this->workspace);

                resolve(RevokeWorkspaceInvitation::class)->revoke($this->findInvitation($record));

                $this->sendNotification(__('workspaces.notifications.workspace_invitation_revoked.success'));
                $this->resetTable();
            });
    }

    public function render(): View
    {
        return view('livewire.app.workspaces.workspace-members');
    }
}
