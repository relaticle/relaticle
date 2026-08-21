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
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;

/**
 * The invite form that opens the members page. It is an inline section rather
 * than a modal so the primary action on the page is visible without a click,
 * matching the other workspace settings sections.
 */
final class InviteTeamMembers extends BaseLivewireComponent
{
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

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;

        $this->form->fill(['role' => TeamRole::Editor->value]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Section::make(__('teams.sections.add_team_member.title'))
                    ->aside()
                    ->visible(fn (): bool => Gate::check('addTeamMember', $this->team))
                    ->description(__('teams.sections.add_team_member.description'))
                    ->schema([
                        Textarea::make('emails')
                            ->label(__('teams.form.emails.label'))
                            ->placeholder(__('teams.form.emails.placeholder'))
                            ->helperText(__('teams.form.emails.helper'))
                            ->rows(3)
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
                        Actions::make([
                            Action::make('invitePeople')
                                ->label(__('teams.actions.send_invitations'))
                                ->action(fn () => $this->invitePeople()),
                            $this->manageInviteLinkAction(),
                        ])->alignEnd(),
                    ]),
            ]);
    }

    /**
     * A plain Livewire method rather than a schema action's closure: actions
     * nested inside a form schema are not addressable by name from outside it,
     * so this keeps the invite path callable (and testable) as one entry point.
     */
    public function invitePeople(): void
    {
        $data = $this->form->getState();

        $this->sendInvitations(
            $this->parseEmails((string) $data['emails']),
            (string) $data['role'],
        );
    }

    public function manageInviteLinkAction(): Action
    {
        return Action::make('manageInviteLink')
            ->label(__('teams.actions.invite_link'))
            ->icon('heroicon-m-link')
            ->color('gray')
            ->link()
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

    public function render(): View
    {
        return view('livewire.app.teams.invite-team-members');
    }

    /**
     * Addresses arrive as one blob rather than a row at a time, because most
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
        // the submission cap above already bounds one submission, this bounds
        // cumulative volume across submissions from the same actor.
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
            $this->form->fill(['role' => $role]);
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
     * Only the owner may grant Admin, so an Admin inviting someone never sees
     * the role they cannot assign (the action enforces this server-side too).
     *
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
}
