<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Onboarding\MoveWorkspaceSetup;
use App\Actions\Onboarding\SaveOnboardingUseCase;
use App\Enums\OnboardingStep;
use App\Enums\OnboardingUseCase;
use App\Features\EmailIntegration;
use App\Filament\Pages\Concerns\BuildsOnboardingPreview;
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
use Laravel\Pennant\Feature;
use Livewire\Attributes\Locked;
use Override;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;

/**
 * @property-read Schema $form
 */
final class SetupWorkspace extends Page
{
    use BuildsOnboardingPreview;

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

    public function skipMailbox(): void
    {
        resolve(MoveWorkspaceSetup::class)->execute($this->authUser(), $this->workspace, OnboardingStep::Email, OnboardingStep::UseCase);
    }

    public function backToMailbox(): void
    {
        abort_unless($this->canGoBackToMailbox(), 403);

        resolve(MoveWorkspaceSetup::class)->execute($this->authUser(), $this->workspace, OnboardingStep::UseCase, OnboardingStep::Email);
    }

    public function canGoBackToMailbox(): bool
    {
        return $this->step() === OnboardingStep::UseCase
            && Feature::active(EmailIntegration::class)
            && ! resolve(WorkspaceActivationFacts::class)->hasConnectedMailbox($this->authUser(), $this->workspace);
    }

    public function mailboxConnectUrl(string $provider): string
    {
        $workspace = $this->workspace;

        return MailboxOAuthWorkspace::redirectUrl($provider, $workspace, self::getUrl(['tenant' => $workspace]));
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

    public function step(): OnboardingStep
    {
        return $this->workspace->onboarding_step ?? OnboardingStep::UseCase;
    }

    public function stepView(): string
    {
        return match ($this->step()) {
            OnboardingStep::Email => 'email',
            default => 'use-case',
        };
    }

    public function previewPanel(): string
    {
        if ($this->step() === OnboardingStep::Email) {
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

        return $this->onboardingPreview(
            $workspace->name,
            $workspace->getFilamentAvatarUrl(),
            $this->authUser()->name,
            $this->selectedUseCase()?->pipelineStages() ?? [],
        );
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
        if ($workspace->onboarding_step !== OnboardingStep::Email) {
            return;
        }

        $connected = resolve(WorkspaceActivationFacts::class)->hasConnectedMailbox($this->authUser(), $workspace);

        if (! Feature::active(EmailIntegration::class) || $connected) {
            resolve(MoveWorkspaceSetup::class)->execute($this->authUser(), $workspace, OnboardingStep::Email, OnboardingStep::UseCase);
        }
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
