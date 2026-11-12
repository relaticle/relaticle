<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\OnboardingUseCase;
use App\Enums\WorkspaceSetupStep;
use App\Filament\Pages\Concerns\BuildsOnboardingPreview;
use App\Filament\Pages\Concerns\RunsInviteStep;
use App\Filament\Pages\Concerns\RunsMailboxSteps;
use App\Filament\Pages\Concerns\RunsUseCaseStep;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentView;
use Livewire\Attributes\Locked;
use Override;

/**
 * @property-read Schema $form
 */
final class SetupWorkspace extends Page
{
    use BuildsOnboardingPreview;
    use RunsInviteStep;
    use RunsMailboxSteps;
    use RunsUseCaseStep;

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

        if ($this->step() === WorkspaceSetupStep::Sharing) {
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
            ->components($this->step() === WorkspaceSetupStep::Invite ? $this->inviteComponents() : $this->useCaseComponents())
            ->statePath('data');
    }

    public function step(): WorkspaceSetupStep
    {
        return $this->workspace->onboarding_step ?? WorkspaceSetupStep::UseCase;
    }

    public function stepView(): string
    {
        return $this->step()->view();
    }

    public function previewPanel(): string
    {
        if (in_array($this->step(), [WorkspaceSetupStep::Email, WorkspaceSetupStep::Sharing], true)) {
            return 'people';
        }

        if ($this->step() === WorkspaceSetupStep::Invite) {
            return 'members';
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

        if ($this->step() === WorkspaceSetupStep::Sharing && $this->connectedMailbox()?->isActive()) {
            $preview['mailboxProgress'] = $this->mailboxProgress();
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

    private function redirectTo(string $url): void
    {
        $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
    }

    private function landingUrl(Workspace $workspace): string
    {
        $conversationId = $workspace->setupConversation()->value('id');

        return is_string($conversationId)
            ? ChatConversation::getUrl(['conversationId' => $conversationId, 'tenant' => $workspace])
            : Dashboard::getUrl(['tenant' => $workspace]);
    }
}
