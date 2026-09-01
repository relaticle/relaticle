<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\UpdateInviteLinkSettings;
use App\Enums\TeamRole;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;
use Livewire\Attributes\Locked;

final class InviteTeamMembers extends BaseLivewireComponent
{
    // Stops one authorized call queuing an unbounded number of mail sends.
    private const int MAX_INVITES_PER_SUBMISSION = 10;

    // Bounds cumulative volume per actor, which rateLimit() cannot: it counts
    // calls, not the emails each call queues.
    private const int MAX_INVITES_PER_WINDOW = 20;

    private const int INVITE_WINDOW_SECONDS = 60;

    #[Locked]
    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('teams.sections.add_team_member.title'))
                ->aside()
                ->visible(fn (): bool => Gate::check('addTeamMember', $this->team))
                ->description(__('teams.sections.add_team_member.description'))
                ->schema([
                    Actions::make([
                        $this->invitePeopleAction(),
                        $this->manageInviteLinkAction(),
                    ]),
                ]),
        ]);
    }

    // The addresses and the role live in the modal rather than on the page: the
    // page is a roster, and the form only has anything to say while inviting.
    public function invitePeopleAction(): Action
    {
        return Action::make('invitePeople')
            ->label(__('teams.actions.invite_people'))
            ->icon('heroicon-m-user-plus')
            ->button()
            ->modalHeading(__('teams.actions.invite_people'))
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('teams.actions.send_invitations'))
            ->schema([
                Textarea::make('emails')
                    ->label(__('teams.form.emails.label'))
                    ->placeholder(__('teams.form.emails.placeholder'))
                    ->helperText(__('teams.form.emails.helper'))
                    ->rows(3)
                    ->autofocus()
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

    public function manageInviteLinkAction(): Action
    {
        return Action::make('manageInviteLink')
            ->label(__('teams.actions.invite_link'))
            ->icon('heroicon-m-link')
            ->color('gray')
            ->button()
            ->outlined()
            ->modalHeading(__('teams.invite_link.heading'))
            ->modalDescription(__('teams.invite_link.description'))
            ->modalIcon('heroicon-o-link')
            ->modalWidth('lg')
            ->modalSubmitAction(false)
            // No footer confirm: every control in here saves on use, so the only
            // way out is the close icon.
            ->modalCancelAction(false)
            ->fillForm(fn (): array => [
                'invite_link_default_role' => $this->team->invite_link_default_role,
                'invite_link_url' => $this->inviteLinkUrl(),
            ])
            ->schema([
                // Read-only rather than an entry: an input with a copy button is
                // the shape people already know a shareable link by.
                TextInput::make('invite_link_url')
                    ->label(__('teams.invite_link.url'))
                    ->readOnly()
                    ->dehydrated(false)
                    ->visible(fn (): bool => $this->team->hasInviteLink())
                    ->prefixIcon('heroicon-m-link')
                    // Selecting on focus makes a manual copy one keystroke, but
                    // the caret lands at the tail and scrolls the host out of
                    // view, so the field is wound back to the start.
                    ->extraInputAttributes([
                        'class' => 'font-mono text-sm',
                        'onfocus' => 'this.select(); this.scrollLeft = 0',
                    ])
                    ->copyable(copyMessage: __('teams.invite_link.copied')),
                Select::make('invite_link_default_role')
                    ->label(__('teams.invite_link.default_role'))
                    ->helperText(__('teams.invite_link.default_role_helper'))
                    ->visible(fn (): bool => $this->team->hasInviteLink())
                    ->options(fn (): array => $this->inviteLinkRoles())
                    ->in(fn (): array => array_keys($this->inviteLinkRoles()))
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state): void {
                        if ($state === null) {
                            return;
                        }

                        resolve(UpdateInviteLinkSettings::class)->update($this->authUser(), $this->team, $state);

                        $this->sendNotification(__('teams.notifications.invite_link_role_updated.success', [
                            'role' => $this->assignableRoles()[$state] ?? $state,
                        ]));
                    }),
                Text::make(__('teams.invite_link.disabled_notice'))
                    ->visible(fn (): bool => ! $this->team->hasInviteLink()),
            ])
            ->extraModalFooterActions([
                $this->enableInviteLinkAction(),
                $this->rotateInviteLinkAction(),
                $this->disableInviteLinkAction(),
            ]);
    }

    // Rotation invalidates a link that may already be circulating, so the modal
    // says what breaks before it happens, not after.
    private function rotateInviteLinkAction(): Action
    {
        return Action::make('rotateInviteLink')
            ->label(__('teams.actions.rotate_invite_link'))
            ->icon('heroicon-m-arrow-path')
            ->color('gray')
            ->link()
            ->visible(fn (): bool => $this->team->hasInviteLink())
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalHeading(__('teams.modals.rotate_invite_link.heading'))
            ->modalDescription(__('teams.modals.rotate_invite_link.notice'))
            ->modalSubmitActionLabel(__('teams.actions.rotate_invite_link'))
            ->action(function (): void {
                resolve(UpdateInviteLinkSettings::class)->rotate($this->authUser(), $this->team);

                $this->sendNotification(__('teams.notifications.invite_link_rotated.success'));
                $this->remountInviteLinkModal();
            });
    }

    private function disableInviteLinkAction(): Action
    {
        return Action::make('disableInviteLink')
            ->label(__('teams.actions.disable_invite_link'))
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->link()
            ->visible(fn (): bool => $this->team->hasInviteLink())
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-no-symbol')
            ->modalHeading(__('teams.modals.disable_invite_link.heading'))
            ->modalDescription(__('teams.modals.disable_invite_link.notice'))
            ->modalSubmitActionLabel(__('teams.actions.disable_invite_link'))
            ->action(function (): void {
                resolve(UpdateInviteLinkSettings::class)->disable($this->authUser(), $this->team);

                $this->sendNotification(__('teams.notifications.invite_link_disabled.success'));
                $this->remountInviteLinkModal();
            });
    }

    // Turning the link back on mints a fresh token, so a link disabled after a
    // leak cannot be revived by re-enabling it.
    private function enableInviteLinkAction(): Action
    {
        return Action::make('enableInviteLink')
            ->label(__('teams.actions.enable_invite_link'))
            ->icon('heroicon-m-link')
            ->button()
            ->visible(fn (): bool => ! $this->team->hasInviteLink())
            ->action(function (): void {
                resolve(UpdateInviteLinkSettings::class)->rotate($this->authUser(), $this->team);

                $this->sendNotification(__('teams.notifications.invite_link_enabled.success'));
                $this->remountInviteLinkModal();
            });
    }

    /**
     * The modal's fields are filled once at mount, so a token minted or cleared
     * by a footer action would leave a stale URL on screen. Remounting refills
     * the form against the team as it now stands.
     */
    private function remountInviteLinkModal(): void
    {
        $this->team->refresh();

        $this->replaceMountedAction('manageInviteLink');
    }

    private function inviteLinkUrl(): ?string
    {
        if (! $this->team->hasInviteLink()) {
            return null;
        }

        return route('teams.join', ['token' => $this->team->invite_link_token]);
    }

    public function render(): View
    {
        return view('livewire.app.teams.invite-team-members');
    }

    /**
     * Addresses arrive pasted from a spreadsheet or mail client, so any
     * separator those produce counts: commas, semicolons, and whitespace.
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

        // Volume-based, not call-based: the cap above bounds one submission,
        // this bounds cumulative volume from the same actor.
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

    /**
     * @return array<string, string>
     */
    private function inviteLinkRoles(): array
    {
        return collect($this->assignableRoles())->except(TeamRole::Admin->value)->all();
    }
}
