<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Onboarding\MoveWorkspaceSetup;
use App\Actions\Onboarding\SaveOnboardingUseCase;
use App\Enums\CreationSource;
use App\Enums\OnboardingStep;
use App\Enums\OnboardingUseCase;
use App\Features\EmailIntegration;
use App\Filament\Pages\Concerns\BuildsOnboardingPreview;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Onboarding\MailboxProviderHint;
use App\Services\WorkspaceActivationFacts;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentView;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Locked;
use Override;
use Relaticle\EmailIntegration\Actions\SaveUserEmailSharingDefaultAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\PrivacyService;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;

/**
 * @property-read Schema $form
 */
final class SetupWorkspace extends Page
{
    use BuildsOnboardingPreview;

    /** @var list<string> */
    private const array OFFERED_SHARING_TIERS = [
        EmailPrivacyTier::METADATA_ONLY->value,
        EmailPrivacyTier::SUBJECT->value,
    ];

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected static ?string $slug = 'setup';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.setup-workspace';

    protected array $extraBodyAttributes = [
        'class' => 'fi-onboarding-wizard',
    ];

    #[Locked]
    public Workspace $workspace;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public string $sharingTier = EmailPrivacyTier::METADATA_ONLY->value;

    public function mount(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();

        $this->workspace = $workspace;

        if ($workspace->user_id !== $this->authUser()->getKey() || $workspace->onboarding_step === null) {
            $this->redirect(Dashboard::getUrl(['tenant' => $workspace]));

            return;
        }

        $this->leaveMailboxStepWhenSettled($workspace);

        $this->form->fill();

        if ($this->step() === OnboardingStep::Sharing) {
            $this->sharingTier = $this->preselectedSharingTier();
        }
    }

    public function authUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    #[Override]
    public function getTitle(): string
    {
        return __('filament/pages/workspaces.setup_workspace.title');
    }

    #[Override]
    public function getHeading(): string
    {
        return '';
    }

    public function getMaxContentWidth(): Width
    {
        return Width::FiveExtraLarge;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->useCaseComponents())
            ->statePath('data');
    }

    public function saveUseCase(): void
    {
        $workspace = $this->workspace;

        resolve(SaveOnboardingUseCase::class)->execute($this->authUser(), $workspace, $this->form->getState(), null);

        $workspace->refresh();

        Notification::make()
            ->title(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.title'))
            ->body(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.body', ['name' => $workspace->name]))
            ->success()
            ->send();

        $url = $this->landingUrl($workspace);

        $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
    }

    public function saveSharing(): void
    {
        $this->validate(['sharingTier' => ['required', Rule::in(self::OFFERED_SHARING_TIERS)]]);

        $moved = resolve(MoveWorkspaceSetup::class)->execute($this->authUser(), $this->workspace, OnboardingStep::Sharing, OnboardingStep::UseCase);

        if (! $moved || ! $this->asksForSharing()) {
            return;
        }

        $tier = EmailPrivacyTier::from($this->sharingTier);

        resolve(SaveUserEmailSharingDefaultAction::class)->execute($this->authUser(), $tier, $tier);
    }

    public function skipMailbox(): void
    {
        resolve(MoveWorkspaceSetup::class)->execute($this->authUser(), $this->workspace, OnboardingStep::Email, OnboardingStep::UseCase);
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
        $hinted = MailboxProviderHint::for($this->authUser());

        return in_array($hinted, $this->configuredProviders(), true) ? $hinted : null;
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
        return ConnectedAccount::query()
            ->ownedBy($this->authUser(), $this->workspace)
            ->connected()
            ->latest()
            ->first(['provider', 'email_address']);
    }

    /**
     * @return array<string, array{label: string, description: string, subjectShown: bool}>
     */
    public function sharingOptions(): array
    {
        return [
            EmailPrivacyTier::METADATA_ONLY->value => [
                'label' => EmailPrivacyTier::METADATA_ONLY->getLabel(),
                'description' => __('filament/pages/workspaces.setup_workspace.sharing.participants_description'),
                'subjectShown' => false,
            ],
            EmailPrivacyTier::SUBJECT->value => [
                'label' => EmailPrivacyTier::SUBJECT->getLabel(),
                'description' => __('filament/pages/workspaces.setup_workspace.sharing.subject_description'),
                'subjectShown' => true,
            ],
        ];
    }

    public function step(): OnboardingStep
    {
        return $this->workspace->onboarding_step ?? OnboardingStep::UseCase;
    }

    public function stepView(): string
    {
        return match ($this->step()) {
            OnboardingStep::Email => 'email',
            OnboardingStep::Sharing => 'sharing',
            default => 'use-case',
        };
    }

    public function previewPanel(): string
    {
        if (in_array($this->step(), [OnboardingStep::Email, OnboardingStep::Sharing], true)) {
            return 'people';
        }

        return $this->selectedUseCase() instanceof OnboardingUseCase ? 'board' : 'dashboard';
    }

    /**
     * @return array<string, mixed>
     */
    public function getPreview(): array
    {
        $workspace = $this->workspace;

        $preview = $this->onboardingPreview(
            $workspace->name,
            $workspace->getFilamentAvatarUrl(),
            $this->authUser()->name,
            $this->selectedUseCase()?->pipelineStages() ?? [],
        );

        if ($this->step() === OnboardingStep::Sharing) {
            $preview['mailboxChip'] = $this->syncingChip();
        }

        return $preview;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function getLayoutData(): array
    {
        return [
            'hasTopbar' => false,
            'maxContentWidth' => $this->getMaxContentWidth(),
            'maxWidth' => $this->getMaxContentWidth(),
        ];
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
        $effective = resolve(PrivacyService::class)->effectiveSharingTierForUser($this->authUser())->value;

        return in_array($effective, self::OFFERED_SHARING_TIERS, true) ? $effective : EmailPrivacyTier::METADATA_ONLY->value;
    }

    private function syncingChip(): string
    {
        $mailboxPeople = People::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $this->workspace->getKey())
            ->where('creation_source', CreationSource::MAILBOX)
            ->count();

        return trans_choice('filament/pages/workspaces.setup_workspace.preview.syncing', $mailboxPeople);
    }

    private function selectedUseCase(): ?OnboardingUseCase
    {
        return OnboardingUseCase::tryFrom((string) ($this->data['onboarding_use_case'] ?? ''));
    }

    private function landingUrl(Workspace $workspace): string
    {
        $conversationId = $workspace->setupConversation()->value('id');

        return is_string($conversationId)
            ? ChatConversation::getUrl(['conversationId' => $conversationId, 'tenant' => $workspace])
            : Dashboard::getUrl(['tenant' => $workspace]);
    }

    /**
     * @return array<Component>
     */
    private function useCaseComponents(): array
    {
        return [
            ToggleButtons::make('onboarding_use_case')
                ->label(__('filament/pages/workspaces.create_workspace.form.use_case_label'))
                ->validationAttribute(__('filament/pages/workspaces.create_workspace.form.use_case_validation_attribute'))
                ->required()
                ->options(collect(OnboardingUseCase::cases())->mapWithKeys(fn (OnboardingUseCase $case): array => [$case->value => $case->getLabel()])->all())
                ->icons(collect(OnboardingUseCase::cases())->mapWithKeys(fn (OnboardingUseCase $case): array => [$case->value => $case->getIcon()])->all())
                ->inline()
                ->live()
                // Stale sub-options from the previous use case are invisible yet fail validation.
                ->afterStateUpdated(function (Set $set): void {
                    $set('onboarding_context', []);
                }),
            $this->useCaseContextField(),
            TextInput::make('onboarding_other_use_case')
                ->label(__('filament/pages/workspaces.create_workspace.form.other_use_case_label'))
                ->placeholder(__('filament/pages/workspaces.create_workspace.form.other_use_case_placeholder'))
                ->validationAttribute(__('filament/pages/workspaces.create_workspace.form.other_use_case_validation_attribute'))
                ->maxLength(120)
                ->visible(fn (Get $get): bool => $get('onboarding_use_case') === OnboardingUseCase::Other->value),
        ];
    }

    private function useCaseContextField(): ToggleButtons
    {
        $subOptions = fn (Get $get): array => OnboardingUseCase::tryFrom($get('onboarding_use_case') ?? '')?->getSubOptions() ?? [];

        return ToggleButtons::make('onboarding_context')
            ->label(__('filament/pages/workspaces.create_workspace.form.use_case_context_label'))
            ->validationAttribute(__('filament/pages/workspaces.create_workspace.form.use_case_context_validation_attribute'))
            ->required()
            ->options($subOptions)
            ->inline()
            ->multiple()
            ->visible(fn (Get $get): bool => $subOptions($get) !== []);
    }
}
