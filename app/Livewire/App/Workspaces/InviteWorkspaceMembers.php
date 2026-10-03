<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Actions\Jetstream\UpdateInviteLinkSettings;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Workspaces\Concerns\InvitesWorkspaceMembers;
use App\Livewire\BaseLivewireComponent;
use App\Models\Workspace;
use App\Support\Workspaces\RoleOptions;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TextInput\Actions\CopyAction;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

final class InviteWorkspaceMembers extends BaseLivewireComponent
{
    use InvitesWorkspaceMembers;

    #[Locked]
    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('workspaces.sections.add_workspace_member.title'))
                ->aside()
                ->visible(fn (): bool => Gate::check('addWorkspaceMember', $this->workspace))
                ->description(__('workspaces.sections.add_workspace_member.description'))
                ->schema([
                    Actions::make([
                        $this->invitePeopleAction(),
                        $this->manageInviteLinkAction(),
                    ]),
                ]),
        ]);
    }

    public function manageInviteLinkAction(): Action
    {
        return Action::make('manageInviteLink')
            ->label(__('workspaces.actions.invite_link'))
            ->icon('heroicon-m-link')
            ->color('gray')
            ->button()
            ->outlined()
            ->modalHeading(__('workspaces.invite_link.heading'))
            ->modalDescription(__('workspaces.invite_link.description'))
            ->modalIcon('heroicon-o-link')
            ->modalWidth('lg')
            ->modalSubmitAction(false)
            // No footer confirm: every control in here saves on use, so the only
            // way out is the close icon.
            ->modalCancelAction(false)
            ->fillForm(fn (): array => [
                'invite_link_default_role' => $this->workspace->invite_link_default_role,
                'invite_link_url' => $this->inviteLinkUrl(),
            ])
            ->schema([
                // Read-only rather than an entry: an input with a copy button is
                // the shape people already know a shareable link by.
                TextInput::make('invite_link_url')
                    ->label(__('workspaces.invite_link.url'))
                    ->readOnly()
                    ->dehydrated(false)
                    ->visible(fn (): bool => $this->hasLiveInviteLink())
                    ->helperText(fn (): string => __('workspaces.invite_link.expires_in', [
                        'time' => $this->workspace->invite_link_token_expires_at?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, options: CarbonInterface::ROUND),
                    ]))
                    // Clicking selects the whole link for a manual copy, but the
                    // caret lands at the tail, so the field is wound back to the host.
                    ->extraInputAttributes([
                        'class' => 'font-mono text-sm',
                        'onclick' => 'this.select(); this.scrollLeft = 0',
                    ])
                    ->suffixAction(
                        CopyAction::make()
                            ->label(__('workspaces.actions.copy_invite_link'))
                            ->icon(null)
                            ->button()
                            ->extraAttributes(['autofocus' => true])
                            ->copyMessage(__('workspaces.invite_link.copied')),
                    ),
                Callout::make(fn (): string => __('workspaces.invite_link.lapsed.title', [
                    'time' => $this->workspace->invite_link_token_expires_at?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE),
                ]))
                    ->description(__('workspaces.invite_link.lapsed.notice'))
                    ->warning()
                    ->visible(fn (): bool => $this->workspace->hasInviteLink() && ! $this->hasLiveInviteLink()),
                Radio::make('invite_link_default_role')
                    ->label(__('workspaces.invite_link.default_role'))
                    ->helperText(__('workspaces.invite_link.default_role_helper'))
                    ->hintAction(RoleOptions::compareAction())
                    ->visible(fn (): bool => $this->workspace->hasInviteLink())
                    ->options(RoleOptions::forInviteLink())
                    ->in(array_keys(RoleOptions::forInviteLink()))
                    ->descriptions(RoleOptions::descriptions())
                    ->required()
                    ->markAsRequired(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state): void {
                        if ($state === null) {
                            return;
                        }

                        resolve(UpdateInviteLinkSettings::class)->update($this->authUser(), $this->workspace, $state);

                        $this->sendNotification(__('workspaces.notifications.invite_link_role_updated.success', [
                            'role' => WorkspaceRole::labelFor($state),
                        ]));
                    }),
                Callout::make(__('workspaces.invite_link.disabled.title'))
                    ->description(__('workspaces.invite_link.disabled.notice'))
                    ->icon('heroicon-o-no-symbol')
                    ->visible(fn (): bool => ! $this->workspace->hasInviteLink()),
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
            ->label(__('workspaces.actions.rotate_invite_link'))
            ->icon('heroicon-m-arrow-path')
            ->color('gray')
            ->link()
            ->visible(fn (): bool => $this->hasLiveInviteLink())
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalHeading(__('workspaces.modals.rotate_invite_link.heading'))
            ->modalDescription(__('workspaces.modals.rotate_invite_link.notice'))
            ->modalSubmitActionLabel(__('workspaces.actions.rotate_invite_link'))
            ->action(function (): void {
                resolve(UpdateInviteLinkSettings::class)->rotate($this->authUser(), $this->workspace);

                $this->sendNotification(__('workspaces.notifications.invite_link_rotated.success'));
                $this->remountInviteLinkModal();
            });
    }

    private function disableInviteLinkAction(): Action
    {
        return Action::make('disableInviteLink')
            ->label(__('workspaces.actions.disable_invite_link'))
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->link()
            ->visible(fn (): bool => $this->workspace->hasInviteLink())
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-no-symbol')
            ->modalHeading(__('workspaces.modals.disable_invite_link.heading'))
            ->modalDescription(__('workspaces.modals.disable_invite_link.notice'))
            ->modalSubmitActionLabel(__('workspaces.actions.disable_invite_link'))
            ->action(function (): void {
                resolve(UpdateInviteLinkSettings::class)->disable($this->authUser(), $this->workspace);

                $this->sendNotification(__('workspaces.notifications.invite_link_disabled.success'));
                $this->remountInviteLinkModal();
            });
    }

    // Turning the link back on mints a fresh token, so a link disabled after a
    // leak cannot be revived by re-enabling it. An expired link renews the same way.
    private function enableInviteLinkAction(): Action
    {
        return Action::make('enableInviteLink')
            ->label(fn (): string => $this->workspace->hasInviteLink()
                ? __('workspaces.actions.rotate_invite_link')
                : __('workspaces.actions.enable_invite_link'))
            ->icon('heroicon-m-link')
            ->button()
            ->extraAttributes(['autofocus' => true])
            ->visible(fn (): bool => ! $this->hasLiveInviteLink())
            ->action(function (): void {
                $notification = $this->workspace->hasInviteLink()
                    ? __('workspaces.notifications.invite_link_rotated.success')
                    : __('workspaces.notifications.invite_link_enabled.success');

                resolve(UpdateInviteLinkSettings::class)->rotate($this->authUser(), $this->workspace);

                $this->sendNotification($notification);
                $this->remountInviteLinkModal();
            });
    }

    /**
     * The modal's fields are filled once at mount, so a token minted or cleared
     * by a footer action would leave a stale URL on screen. Remounting refills
     * the form against the workspace as it now stands.
     */
    private function remountInviteLinkModal(): void
    {
        $this->workspace->refresh();

        $this->replaceMountedAction('manageInviteLink');
    }

    private function hasLiveInviteLink(): bool
    {
        return $this->workspace->hasInviteLink() && ! $this->workspace->isInviteLinkTokenExpired();
    }

    private function inviteLinkUrl(): ?string
    {
        if (! $this->hasLiveInviteLink()) {
            return null;
        }

        return route('workspaces.join', ['token' => $this->workspace->invite_link_token]);
    }

    public function render(): View
    {
        return view('livewire.app.workspaces.invite-workspace-members');
    }
}
