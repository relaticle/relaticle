<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Actions\Jetstream\InviteWorkspaceMember;
use App\Actions\Jetstream\UpdateInviteLinkSettings;
use App\Actions\Onboarding\MoveWorkspaceSetup;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceSetupStep;
use App\Livewire\App\Workspaces\Concerns\SendsWorkspaceInvitations;
use App\Support\EmailAddress;
use App\Support\Workspaces\RoleOptions;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Schema;
use Filament\Support\Enums\VerticalAlignment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Throwable;

trait RunsInviteStep
{
    use SendsWorkspaceInvitations;

    public function finish(): void
    {
        $state = $this->inviteForm()->getState();

        if (! resolve(MoveWorkspaceSetup::class)->execute($this->authUser(), $this->workspace, WorkspaceSetupStep::Invite, null)) {
            $this->redirectTo(self::getUrl(['tenant' => $this->workspace]));

            return;
        }

        $this->sendInvitationsOrWarn($state);

        Notification::make()
            ->title(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.title'))
            ->body(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.body', ['name' => $this->workspace->name]))
            ->success()
            ->send();

        // Full page load: the panel's sidebar store initialises only on a page's first load.
        $this->redirect($this->landingUrl($this->workspace));
    }

    public function createInviteLink(): void
    {
        if ($this->step() !== WorkspaceSetupStep::Invite || $this->inviteLinkUrl() !== null) {
            return;
        }

        resolve(UpdateInviteLinkSettings::class)->rotate($this->authUser(), $this->workspace);
    }

    public function inviteLinkUrl(): ?string
    {
        if (! Gate::forUser($this->authUser())->allows('addWorkspaceMember', $this->workspace)) {
            return null;
        }

        if (! $this->workspace->hasInviteLink() || $this->workspace->isInviteLinkTokenExpired()) {
            return null;
        }

        return route('workspaces.join', ['token' => $this->workspace->invite_link_token]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function sendInvitationsOrWarn(array $state): void
    {
        try {
            $this->sendInvitations(
                $this->parseEmails((string) ($state['emails'] ?? '')),
                (string) ($state['role'] ?? WorkspaceRole::Member->value),
            );
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('filament/pages/workspaces.setup_workspace.invite.not_sent.title'))
                ->body(__('filament/pages/workspaces.setup_workspace.invite.not_sent.body'))
                ->warning()
                ->send();
        }
    }

    private function inviteForm(): Schema
    {
        return Schema::make($this)->components($this->inviteComponents())->statePath('data');
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

        if ($emails === []) {
            return;
        }

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
            ['email' => InviteWorkspaceMember::emailRules()],
        )->passes();
    }
}
