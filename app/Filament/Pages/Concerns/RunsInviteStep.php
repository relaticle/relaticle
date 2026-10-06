<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Actions\Jetstream\UpdateInviteLinkSettings;
use App\Actions\Onboarding\MoveWorkspaceSetup;
use App\Enums\OnboardingStep;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Workspaces\Concerns\SendsWorkspaceInvitations;
use App\Rules\RegistrableEmail;
use App\Support\EmailAddress;
use App\Support\Workspaces\RoleOptions;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Support\Enums\VerticalAlignment;
use Illuminate\Support\Facades\Validator;

trait RunsInviteStep
{
    use SendsWorkspaceInvitations;

    public function finish(): void
    {
        if ($this->step() !== OnboardingStep::Invite) {
            $this->redirectTo(self::getUrl(['tenant' => $this->workspace]));

            return;
        }

        $state = $this->form->getState();

        if (! resolve(MoveWorkspaceSetup::class)->execute($this->authUser(), $this->workspace, OnboardingStep::Invite, null)) {
            $this->redirectTo(self::getUrl(['tenant' => $this->workspace]));

            return;
        }

        $this->sendInvitations(
            $this->parseEmails((string) ($state['emails'] ?? '')),
            (string) ($state['role'] ?? WorkspaceRole::Member->value),
        );

        Notification::make()
            ->title(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.title'))
            ->body(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.body', ['name' => $this->workspace->name]))
            ->success()
            ->send();

        $this->redirectTo($this->landingUrl($this->workspace));
    }

    public function createInviteLink(): void
    {
        if ($this->step() !== OnboardingStep::Invite || $this->inviteLinkUrl() !== null) {
            return;
        }

        resolve(UpdateInviteLinkSettings::class)->rotate($this->authUser(), $this->workspace);
    }

    public function inviteLinkUrl(): ?string
    {
        if (! $this->workspace->hasInviteLink() || $this->workspace->isInviteLinkTokenExpired()) {
            return null;
        }

        return route('workspaces.join', ['token' => $this->workspace->invite_link_token]);
    }

    protected function sendNotification(string $title, ?string $message = null, string $type = 'success'): void
    {
        Notification::make()->title($title)->body($message)->{$type}()->send();
    }

    /**
     * @return array<Component>
     */
    private function inviteComponents(): array
    {
        $assignableRoles = fn (): array => RoleOptions::assignable($this->authUser(), $this->workspace);

        return [
            Flex::make([
                TextInput::make('emails')
                    ->label(__('filament/pages/workspaces.setup_workspace.invite.emails_label'))
                    ->placeholder(__('filament/pages/workspaces.setup_workspace.invite.emails_placeholder'))
                    ->helperText(__('filament/pages/workspaces.setup_workspace.invite.emails_helper'))
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $this->failUnsendableInvites(is_string($value) ? $value : '', $fail);
                    }),
                Select::make('role')
                    ->label(__('workspaces.form.invite_as.label'))
                    ->options($assignableRoles)
                    ->in(fn (): array => array_keys($assignableRoles()))
                    ->default(WorkspaceRole::Member->value)
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->grow(false),
            ])->from('sm')->verticalAlignment(VerticalAlignment::Start)->extraAttributes(['class' => 'gap-3']),
        ];
    }

    private function failUnsendableInvites(string $value, Closure $fail): void
    {
        $emails = $this->parseEmails($value);

        if (count($emails) > self::MAX_INVITES_PER_SUBMISSION) {
            $fail(__('workspaces.validation.too_many_invites', ['max' => self::MAX_INVITES_PER_SUBMISSION]));

            return;
        }

        $malformed = array_filter($emails, fn (string $email): bool => ! $this->isInvitableAddress($email));

        if ($malformed !== []) {
            $fail(__('filament/pages/workspaces.setup_workspace.invite.invalid_emails', ['emails' => implode(', ', $malformed)]));

            return;
        }

        $retryAfter = $this->inviteRetryAfterSeconds();

        if ($retryAfter !== null) {
            $fail(__('workspaces.notifications.invite_rate_limited.body', ['seconds' => $retryAfter]));
        }
    }

    private function isInvitableAddress(string $email): bool
    {
        return Validator::make(
            ['email' => EmailAddress::canonicalize($email)],
            ['email' => RegistrableEmail::rules(checkDns: false)],
        )->passes();
    }
}
