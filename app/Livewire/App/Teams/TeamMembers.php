<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\UpdateInviteLinkSettings;
use App\Actions\Jetstream\UpdateTeamMemberRole;
use App\Enums\TeamRole;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;

/**
 * The workspace's people directory. Pending invitations are a separate,
 * unpaginated worklist rendered by PendingTeamInvitations — merging the two
 * into one paginated table buried the actionable set behind pagination, which
 * is why Twenty and Slack keep them apart too.
 */
final class TeamMembers extends BaseLivewireComponent implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    /**
     * Bounds one invite submission — generous for real bulk-inviting a team,
     * but stops a single authorized call from queuing an unbounded number of
     * Mail sends (the onboarding wizard's invite step caps at 5 for the same
     * reason; this screen is used long after onboarding, so it allows more).
     */
    private const int MAX_INVITES_PER_SUBMISSION = 10;

    /**
     * Bounds cumulative invite volume per actor within the window below,
     * independent of how many separate submissions it takes to reach it —
     * `rateLimit()` alone only throttles the number of calls, not the number
     * of emails each call can queue.
     */
    private const int MAX_INVITES_PER_WINDOW = 20;

    private const int INVITE_WINDOW_SECONDS = 60;

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
            ->heading(__('teams.table.members_heading'))
            ->description(fn (): string => trans_choice('teams.table.members_count', $this->memberCount()))
            ->headerActions([
                $this->invitePeopleAction(),
                $this->manageInviteLinkAction(),
            ])
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

    private function memberCount(): int
    {
        return $this->membersQuery()->toBase()->count();
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

    private function invitePeopleAction(): Action
    {
        return Action::make('invitePeople')
            ->label(__('teams.actions.invite_people'))
            ->icon('heroicon-m-user-plus')
            ->visible(fn (): bool => Gate::check('addTeamMember', $this->team))
            ->modalHeading(__('teams.modals.invite_people.heading'))
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('teams.actions.send_invitations'))
            ->schema([
                Textarea::make('emails')
                    ->label(__('teams.form.emails.label'))
                    ->placeholder(__('teams.form.emails.placeholder'))
                    ->helperText(__('teams.form.emails.helper'))
                    ->rows(4)
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $emails = $this->parseEmails(is_string($value) ? $value : '');

                        if ($emails === []) {
                            $fail(__('teams.validation.no_valid_emails'));

                            return;
                        }

                        if (count($emails) > self::MAX_INVITES_PER_SUBMISSION) {
                            $fail(__('teams.validation.too_many_invites', ['max' => self::MAX_INVITES_PER_SUBMISSION]));
                        }
                    }),
                Select::make('role')
                    ->label(__('teams.form.invite_as.label'))
                    ->options(fn (): array => $this->assignableRoles())
                    ->in(fn (): array => array_keys($this->assignableRoles()))
                    ->default(TeamRole::Editor->value)
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->sendInvitations(
                    $this->parseEmails((string) $data['emails']),
                    (string) $data['role'],
                );
            });
    }

    /**
     * Split a pasted block into addresses. Attio accepts one textarea and lets
     * people paste from a spreadsheet or a mail client, so anything that can
     * separate addresses in those sources counts: commas, semicolons, and any
     * whitespace including newlines.
     *
     * @return list<string>
     */
    private function parseEmails(string $input): array
    {
        $parts = preg_split('/[\s,;]+/', trim($input)) ?: [];

        return array_values(array_unique(array_filter(
            array_map(trim(...), $parts),
            fn (string $email): bool => $email !== '',
        )));
    }

    /**
     * @param  list<string>  $emails
     */
    private function sendInvitations(array $emails, string $role): void
    {
        if ($emails === []) {
            return;
        }

        // Volume-based, not call-based: `rateLimit()` would only throttle how
        // often this method runs, not how many emails a single run queues —
        // `maxItems()` on the repeater already bounds one submission, this
        // bounds cumulative volume across submissions from the same actor.
        $rateLimitKey = 'invite-team-members:'.$this->authUser()->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_INVITES_PER_WINDOW)) {
            $this->sendNotification(
                __('teams.notifications.invite_rate_limited.title'),
                __('teams.notifications.invite_rate_limited.body', [
                    'seconds' => RateLimiter::availableIn($rateLimitKey),
                ]),
                'danger',
            );

            return;
        }

        RateLimiter::increment($rateLimitKey, self::INVITE_WINDOW_SECONDS, count($emails));

        $failures = [];
        $sent = 0;

        foreach ($emails as $email) {
            try {
                resolve(InviteTeamMember::class)->invite($this->authUser(), $this->team, $email, $role);

                $sent++;
            } catch (ValidationException $exception) {
                $failures[] = "{$email}: {$exception->validator->errors()->first()}";
            }
        }

        if ($sent > 0) {
            $this->sendNotification(__('teams.notifications.team_invitation_sent.success'));
            $this->dispatch('teamInvitationSent');
        }

        if ($failures !== []) {
            $this->sendNotification(
                __('teams.notifications.some_invites_failed.title'),
                implode("\n", $failures),
                'warning',
            );
        }
    }

    private function manageInviteLinkAction(): Action
    {
        return Action::make('manageInviteLink')
            ->label(__('teams.actions.invite_link'))
            ->icon('heroicon-m-link')
            ->color('gray')
            ->visible(fn (): bool => Gate::check('addTeamMember', $this->team))
            ->schema([
                TextEntry::make('url')
                    ->label(__('teams.invite_link.url'))
                    ->state(fn (): string => route('teams.join', ['token' => $this->team->invite_link_token]))
                    ->copyable(),
                Select::make('invite_link_default_role')
                    ->label(__('teams.invite_link.default_role'))
                    ->options(fn (): array => $this->assignableRoles())
                    ->in(fn (): array => array_keys($this->assignableRoles()))
                    ->default(fn (): string => $this->team->invite_link_default_role)
                    ->required(),
            ])
            ->extraModalFooterActions([
                Action::make('rotateInviteLink')
                    ->label(__('teams.actions.rotate_invite_link'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        resolve(UpdateInviteLinkSettings::class)->rotate($this->authUser(), $this->team);

                        $this->sendNotification(__('teams.notifications.invite_link_rotated.success'));
                    }),
            ])
            ->action(function (array $data): void {
                resolve(UpdateInviteLinkSettings::class)->update(
                    $this->authUser(),
                    $this->team,
                    (string) $data['invite_link_default_role'],
                );

                $this->sendNotification();
            });
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
