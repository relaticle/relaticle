<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Actions\Onboarding\MoveWorkspaceSetup;
use App\Actions\Onboarding\SaveOnboardingSharing;
use App\Enums\CreationSource;
use App\Enums\OnboardingStep;
use App\Features\EmailIntegration;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Onboarding\MailboxProviderHint;
use App\Services\WorkspaceActivationFacts;
use Filament\Actions\Action;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\PrivacyService;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;

trait RunsMailboxSteps
{
    public string $sharingTier = EmailPrivacyTier::METADATA_ONLY->value;

    public function saveSharing(): void
    {
        $this->validate(['sharingTier' => ['required', Rule::enum(EmailPrivacyTier::class)->only(SaveOnboardingSharing::OFFERED_TIERS)]]);

        if (! $this->asksForSharing()) {
            resolve(MoveWorkspaceSetup::class)->execute($this->authUser(), $this->workspace, OnboardingStep::Sharing, OnboardingStep::UseCase);

            return;
        }

        resolve(SaveOnboardingSharing::class)->execute($this->authUser(), $this->workspace, EmailPrivacyTier::from($this->sharingTier));
    }

    public function skipMailboxAction(): Action
    {
        return Action::make('skipMailbox')
            ->label(__('filament/pages/workspaces.setup_workspace.email.skip'))
            ->link()
            ->color('gray')
            ->modalWidth(Width::Large)
            ->modalCloseButton(false)
            ->modalHeading(__('filament/pages/workspaces.setup_workspace.email.skip_confirm.heading'))
            ->modalDescription(__('filament/pages/workspaces.setup_workspace.email.skip_confirm.description'))
            ->modalContent(view('filament.pages.setup-workspace.benefits', ['boxed' => true]))
            ->modalSubmitAction(fn (Action $action): Action => $action
                ->label(__('filament/pages/workspaces.setup_workspace.email.skip_confirm.submit'))
                ->color('danger'))
            ->modalFooterActionsAlignment(Alignment::End)
            ->action(function (): void {
                resolve(MoveWorkspaceSetup::class)->execute($this->authUser(), $this->workspace, OnboardingStep::Email, OnboardingStep::UseCase);
            });
    }

    public function backToMailbox(): void
    {
        if (! $this->canGoBackToMailbox()) {
            return;
        }

        resolve(MoveWorkspaceSetup::class)->execute($this->authUser(), $this->workspace, OnboardingStep::UseCase, OnboardingStep::Email);
    }

    public function canGoBackToMailbox(): bool
    {
        return $this->step() === OnboardingStep::UseCase
            && Feature::active(EmailIntegration::class)
            && ! resolve(WorkspaceActivationFacts::class)->hasConnectedMailbox($this->authUser(), $this->workspace);
    }

    public function connectMailbox(string $provider): void
    {
        if ($this->step() !== OnboardingStep::Email || ! Feature::active(EmailIntegration::class)) {
            return;
        }

        if (! in_array($provider, $this->offeredProviders(), true)) {
            return;
        }

        $workspace = $this->workspace;

        $this->redirect(MailboxOAuthWorkspace::redirectUrl($provider, $workspace, self::getUrl(['tenant' => $workspace])));
    }

    public function emphasizedProvider(): ?string
    {
        return once(function (): ?string {
            $hinted = MailboxProviderHint::for($this->authUser());

            return in_array($hinted, $this->configuredProviders(), true) ? $hinted : null;
        });
    }

    /**
     * @return list<string>
     */
    public function offeredProviders(): array
    {
        $configured = $this->configuredProviders();
        $emphasized = $this->emphasizedProvider();

        return $emphasized === null
            ? $configured
            : [$emphasized, ...array_values(array_diff($configured, [$emphasized]))];
    }

    public function connectedMailbox(): ?ConnectedAccount
    {
        return once(fn (): ?ConnectedAccount => ConnectedAccount::query()
            ->ownedBy($this->authUser(), $this->workspace)
            ->connected()
            ->latest()
            ->first(['provider', 'email_address', 'status']));
    }

    /**
     * @return array<string, array{label: string, description: string, subjectShown: bool}>
     */
    public function sharingOptions(): array
    {
        return collect(SaveOnboardingSharing::OFFERED_TIERS)
            ->mapWithKeys(fn (EmailPrivacyTier $tier): array => [$tier->value => [
                'label' => $tier->getLabel(),
                'description' => $tier->getDescription(),
                'subjectShown' => $tier === EmailPrivacyTier::SUBJECT,
            ]])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function configuredProviders(): array
    {
        return array_values(array_map(
            fn (EmailProvider $provider): string => $provider->value,
            array_filter(EmailProvider::cases(), fn (EmailProvider $provider): bool => $provider->isConfigured()),
        ));
    }

    private function leaveMailboxStepWhenSettled(Workspace $workspace): void
    {
        if (! in_array($workspace->onboarding_step, [OnboardingStep::Email, OnboardingStep::Sharing], true)) {
            return;
        }

        $move = resolve(MoveWorkspaceSetup::class);

        if (! Feature::active(EmailIntegration::class)) {
            $move->execute($this->authUser(), $workspace, $workspace->onboarding_step, OnboardingStep::UseCase);

            return;
        }

        if ($workspace->onboarding_step === OnboardingStep::Sharing) {
            if (! $this->asksForSharing()) {
                $move->execute($this->authUser(), $workspace, OnboardingStep::Sharing, OnboardingStep::UseCase);
            }

            return;
        }

        if (! resolve(WorkspaceActivationFacts::class)->hasConnectedMailbox($this->authUser(), $workspace)) {
            return;
        }

        $next = $this->asksForSharing() ? OnboardingStep::Sharing : OnboardingStep::UseCase;

        $move->execute($this->authUser(), $workspace, OnboardingStep::Email, $next);
    }

    private function asksForSharing(): bool
    {
        return $this->authUser()->default_email_sharing_tier === null
            && ! ConnectedAccount::hasConnectedOutside($this->authUser(), $this->workspace);
    }

    private function preselectedSharingTier(): string
    {
        $effective = resolve(PrivacyService::class)->effectiveSharingTierForUser($this->authUser());

        return in_array($effective, SaveOnboardingSharing::OFFERED_TIERS, true) ? $effective->value : EmailPrivacyTier::METADATA_ONLY->value;
    }

    private function mailboxProgress(): string
    {
        $mailboxPeople = People::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $this->workspace->getKey())
            ->where('creation_source', CreationSource::MAILBOX)
            ->count();

        return trans_choice('filament/pages/workspaces.setup_workspace.preview.found', $mailboxPeople);
    }
}
