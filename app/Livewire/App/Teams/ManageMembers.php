<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\ResendTeamInvitation;
use App\Actions\Jetstream\RevokeTeamInvitation;
use App\Actions\Jetstream\UpdateInviteLinkSettings;
use App\Actions\Jetstream\UpdateTeamMemberRole;
use App\Enums\TeamRole;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamPerson;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;

final class ManageMembers extends BaseLivewireComponent implements Tables\Contracts\HasTable
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
            ->query(fn (): Builder => TeamPerson::forTeam($this->team))
            ->searchable()
            ->paginated([10, 25, 50])
            ->defaultSort('happened_at')
            ->heading(__('teams.table.heading'))
            ->description(fn (): string => __('teams.table.counts', [
                'members' => $this->team->users()->count() + 1,
                'pending' => $this->team->teamInvitations()->count(),
            ]))
            ->headerActions([
                $this->invitePeopleAction(),
                $this->manageInviteLinkAction(),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('teams.table.person'))
                    ->description(fn (TeamPerson $record): string => $record->email)
                    ->searchable(['name', 'email'])
                    ->default(fn (TeamPerson $record): string => $record->email),
                Tables\Columns\TextColumn::make('role')
                    ->label(__('teams.table.role'))
                    ->badge()
                    ->formatStateUsing(fn (TeamPerson $record): string => $this->isOwner($record)
                        ? __('teams.roles.owner.label')
                        : $this->roleLabel($record->role)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('teams.table.status'))
                    ->badge()
                    ->color(fn (TeamPerson $record): string => $record->status === 'invited' ? 'warning' : 'success')
                    ->formatStateUsing(fn (TeamPerson $record): string => $record->status === 'invited'
                        ? __('teams.table.status_invited')
                        : __('teams.table.status_member')),
                Tables\Columns\TextColumn::make('happened_at')
                    ->label(__('teams.table.since'))
                    ->date(),
            ])
            ->recordActions([
                $this->updateTeamRoleAction(),
                $this->removeTeamMemberAction(),
                $this->leaveTeamAction(),
                $this->resendTeamInvitationAction(),
                $this->copyInviteLinkAction(),
                $this->revokeTeamInvitationAction(),
            ]);
    }

    private function isOwner(TeamPerson $record): bool
    {
        return $record->user_id !== null && $record->user_id === $this->team->user_id;
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
            ->visible(fn (): bool => Gate::check('addTeamMember', $this->team))
            ->modalWidth('lg')
            ->schema([
                Repeater::make('invites')
                    ->hiddenLabel()
                    ->defaultItems(1)
                    ->maxItems(self::MAX_INVITES_PER_SUBMISSION)
                    ->addActionLabel(__('teams.actions.add_another'))
                    ->schema([
                        TextInput::make('email')
                            ->label(__('teams.form.email.label'))
                            ->email()
                            ->required(),
                        Select::make('role')
                            ->label(__('teams.table.role'))
                            ->options(fn (): array => $this->assignableRoles())
                            ->in(fn (): array => array_keys($this->assignableRoles()))
                            ->default(TeamRole::Editor->value)
                            ->required(),
                    ])
                    ->columns(2),
            ])
            ->action(function (array $data): void {
                $this->sendInvitations($data['invites']);
            });
    }

    /**
     * @param  array<int, array{email: ?string, role: ?string}>  $invites
     */
    private function sendInvitations(array $invites): void
    {
        $invites = array_values(array_filter(
            $invites,
            fn (array $invite): bool => filled($invite['email'] ?? null),
        ));

        if ($invites === []) {
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

        RateLimiter::increment($rateLimitKey, self::INVITE_WINDOW_SECONDS, count($invites));

        $failures = [];
        $sent = 0;

        foreach ($invites as $invite) {
            try {
                resolve(InviteTeamMember::class)->invite(
                    $this->authUser(),
                    $this->team,
                    (string) $invite['email'],
                    $invite['role'] ?? TeamRole::Editor->value,
                );

                $sent++;
            } catch (ValidationException $exception) {
                $failures[] = "{$invite['email']}: {$exception->validator->errors()->first()}";
            }
        }

        if ($sent > 0) {
            $this->sendNotification(__('teams.notifications.team_invitation_sent.success'));
        }

        if ($failures !== []) {
            $this->sendNotification(
                __('teams.notifications.some_invites_failed.title'),
                implode("\n", $failures),
                'warning',
            );
        }

        $this->resetTable();
    }

    private function manageInviteLinkAction(): Action
    {
        return Action::make('manageInviteLink')
            ->label(__('teams.actions.invite_link'))
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
            ->label(__('teams.actions.update_team_role'))
            ->visible(fn (TeamPerson $record): bool => $record->status === 'member'
                && ! $this->isOwner($record)
                && Gate::check('updateTeamMember', $this->team))
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
                    ->default(fn (TeamPerson $record): string => $record->role)
                    ->rules([
                        fn (TeamPerson $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            $touchesAdminStatus = $value === TeamRole::Admin->value
                                || $record->role === TeamRole::Admin->value;

                            if ($touchesAdminStatus && ! Gate::check('promoteToAdmin', $this->team)) {
                                $fail(__('teams.validation.only_owner_promotes_admins'));
                            }
                        },
                    ]),
            ])
            ->action(function (TeamPerson $record, array $data): void {
                // `user_id` is nullable on TeamPerson (invitation rows carry none),
                // so narrow before passing it to an action that requires a string.
                $userId = $record->user_id;

                if ($userId === null) {
                    return;
                }

                try {
                    resolve(UpdateTeamMemberRole::class)->update(
                        $this->authUser(),
                        $this->team,
                        $userId,
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
            ->visible(fn (TeamPerson $record): bool => $record->status === 'member'
                && ! $this->isOwner($record)
                && $record->user_id !== $this->authUser()->id
                && Gate::check('removeTeamMember', $this->team))
            ->action(function (TeamPerson $record): void {
                $member = User::query()->find($record->user_id);

                if ($member === null) {
                    return;
                }

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
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('danger')
            ->modalDescription(__('teams.modals.leave_team.notice'))
            ->requiresConfirmation()
            // Hidden on the owner row: RemoveTeamMember always rejects the owner,
            // so showing it could only ever produce an error (defect A8).
            ->visible(fn (TeamPerson $record): bool => $record->status === 'member'
                && ! $this->isOwner($record)
                && $record->user_id === $this->authUser()->id)
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
            ->color('primary')
            ->requiresConfirmation()
            ->visible(fn (TeamPerson $record): bool => $record->status === 'invited'
                && Gate::check('updateTeamMember', $this->team))
            ->action(function (TeamPerson $record): void {
                $invitation = $this->invitationFor($record);

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

    private function copyInviteLinkAction(): Action
    {
        return Action::make('copyInviteLink')
            ->label(__('teams.actions.copy_invite_link'))
            ->color('gray')
            ->visible(fn (TeamPerson $record): bool => $record->status === 'invited'
                && Gate::check('updateTeamMember', $this->team))
            ->action(function (TeamPerson $record): void {
                $invitation = $this->invitationFor($record);

                // Only a resend can mint a fresh raw token — the stored value is a
                // hash — so legacy rows still hand out their signed URL.
                $url = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

                $this->js('navigator.clipboard.writeText('.json_encode($url, JSON_THROW_ON_ERROR).')');

                $this->sendNotification(__('teams.notifications.invite_link_copied.success'));
            });
    }

    private function revokeTeamInvitationAction(): Action
    {
        return Action::make('revokeTeamInvitation')
            ->label(__('teams.actions.revoke_team_invitation'))
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (TeamPerson $record): bool => $record->status === 'invited'
                && Gate::check('removeTeamMember', $this->team))
            ->action(function (TeamPerson $record): void {
                $invitation = $this->invitationFor($record);

                resolve(RevokeTeamInvitation::class)->revoke($invitation);

                $this->sendNotification(__('teams.notifications.team_invitation_revoked.success'));
                $this->resetTable();
            });
    }

    /**
     * Resolve an invitation row back to its model, re-asserting tenant ownership.
     * The record key arrives from the client, so the team scope is the boundary.
     *
     * Aborts rather than returning null on a miss: the replaced
     * PendingTeamInvitations component answered a foreign invitation with
     * `abort_unless($invitation->team_id === $this->team->id, 403)`, and
     * ManageMembersCrossTenantTest pins that contract. Returning null here
     * would soften a 403 into a silent no-op — still safe, but a weaker
     * guarantee than the one the suite already holds us to.
     */
    private function invitationFor(TeamPerson $record): TeamInvitation
    {
        Gate::authorize('updateTeamMember', $this->team);

        $invitation = TeamInvitation::query()
            ->whereKey($record->source_id)
            ->where('team_id', $this->team->id)
            ->first();

        abort_if($invitation === null, 403);

        return $invitation;
    }

    public function render(): View
    {
        return view('livewire.app.teams.manage-members');
    }
}
