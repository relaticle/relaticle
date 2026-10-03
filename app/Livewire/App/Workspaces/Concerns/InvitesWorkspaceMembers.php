<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces\Concerns;

use App\Actions\Jetstream\InviteWorkspaceMember;
use App\Enums\WorkspaceRole;
use App\Support\Workspaces\RoleOptions;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

trait InvitesWorkspaceMembers
{
    // Stops one authorized call queuing an unbounded number of mail sends.
    private const int MAX_INVITES_PER_SUBMISSION = 10;

    // Bounds cumulative volume per actor, which rateLimit() cannot: it counts
    // calls, not the emails each call queues.
    private const int MAX_INVITES_PER_WINDOW = 20;

    private const int INVITE_WINDOW_SECONDS = 60;

    // The addresses and the role live in the modal rather than on the page: the
    // page is a roster, and the form only has anything to say while inviting.
    public function invitePeopleAction(): Action
    {
        return Action::make('invitePeople')
            ->label(__('workspaces.actions.invite_people'))
            ->icon('heroicon-m-user-plus')
            ->button()
            ->authorize(fn (): bool => Gate::check('addWorkspaceMember', $this->workspace))
            ->modalHeading(__('workspaces.actions.invite_people'))
            ->modalDescription(fn (): string => __('workspaces.sections.invite_people.description', ['workspace' => $this->workspace->name]))
            ->modalIcon('heroicon-o-user-plus')
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('workspaces.actions.send_invitations'))
            ->schema([
                Textarea::make('emails')
                    ->label(__('workspaces.form.emails.label'))
                    ->placeholder(__('workspaces.form.emails.placeholder'))
                    ->helperText(__('workspaces.form.emails.helper'))
                    ->rows(3)
                    ->autofocus()
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $emails = $this->parseEmails(is_string($value) ? $value : '');

                        if ($emails === []) {
                            $fail(__('workspaces.validation.no_valid_emails'));

                            return;
                        }

                        if (count($emails) > self::MAX_INVITES_PER_SUBMISSION) {
                            $fail(__('workspaces.validation.too_many_invites', ['max' => self::MAX_INVITES_PER_SUBMISSION]));
                        }
                    }),
                Select::make('role')
                    ->label(__('workspaces.form.invite_as.label'))
                    ->options(fn (): array => RoleOptions::assignable($this->authUser(), $this->workspace))
                    ->in(fn (): array => array_keys(RoleOptions::assignable($this->authUser(), $this->workspace)))
                    ->helperText(fn (Get $get): ?string => RoleOptions::descriptions()[$get('role')] ?? null)
                    ->hintAction(RoleOptions::compareAction())
                    ->default(WorkspaceRole::Member->value)
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->live()
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
        $rateLimitKey = 'invite-workspace-members:'.$this->authUser()->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_INVITES_PER_WINDOW)) {
            $this->sendNotification(
                __('workspaces.notifications.invite_rate_limited.title'),
                __('workspaces.notifications.invite_rate_limited.body', [
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
                resolve(InviteWorkspaceMember::class)->invite($this->authUser(), $this->workspace, $email, $role);

                $sent++;
            } catch (ValidationException $exception) {
                $failures[] = "{$email}: {$exception->validator->errors()->first()}";
            }
        }

        if ($sent > 0) {
            $this->sendNotification(__('workspaces.notifications.workspace_invitation_sent.success'));
            $this->dispatch('workspaceInvitationSent');
        }

        if ($failures !== []) {
            $this->sendNotification(
                __('workspaces.notifications.some_invites_failed.title'),
                implode("\n", $failures),
                'warning',
            );
        }
    }
}
