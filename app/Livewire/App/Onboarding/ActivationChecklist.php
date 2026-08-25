<?php

declare(strict_types=1);

namespace App\Livewire\App\Onboarding;

use App\Actions\Onboarding\DismissActivationChecklist;
use App\Data\ActivationStepData;
use App\Enums\ActivationStep;
use App\Filament\Pages\ChatConversation;
use App\Filament\Pages\Team\Members;
use App\Filament\Resources\PeopleResource;
use App\Models\Team;
use App\Models\User;
use App\Services\WorkspaceActivationFacts;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Relaticle\ImportWizard\Filament\Pages\ImportPeople;
use Spatie\Onboard\OnboardingStep;

/**
 * First-run guide on the dashboard. Each step is a fact about the workspace, not a
 * stored flag, so progress stays truthful however the record was created (form,
 * import, API, chat).
 *
 * @property-read list<ActivationStepData> $steps
 * @property-read int $completedCount
 */
final class ActivationChecklist extends Component
{
    public function dismiss(): void
    {
        $team = $this->team();

        if (! $team instanceof Team) {
            return;
        }

        resolve(DismissActivationChecklist::class)->execute($this->user(), $team);

        unset($this->steps);
    }

    public function render(): View
    {
        return view('livewire.app.onboarding.activation-checklist');
    }

    /**
     * Hidden once the workspace is set up, so a working CRM is never topped by a
     * checklist of things already done.
     */
    #[Computed]
    public function visible(): bool
    {
        $team = $this->team();

        if (! $team instanceof Team) {
            return false;
        }

        if ($team->activation_checklist_dismissed_at !== null) {
            return false;
        }

        // Every step links somewhere only a workspace admin can act on, so showing
        // this to an editor would be a checklist of 403s.
        if (! $this->user()->can('update', $team)) {
            return false;
        }

        return $this->completedCount < count($this->steps);
    }

    /**
     * @return list<ActivationStepData>
     */
    #[Computed]
    public function steps(): array
    {
        $team = $this->team();

        if (! $team instanceof Team) {
            return [];
        }

        $welcomeConversationId = resolve(WorkspaceActivationFacts::class)->welcomeConversationId($team);

        return array_values($team->onboarding()->steps()
            ->map(fn (OnboardingStep $step): ActivationStepData => new ActivationStepData(
                key: $this->stepKey($step)->value,
                label: __((string) $step->attribute('label_key')),
                description: __((string) $step->attribute('description_key')),
                url: $this->stepUrl($this->stepKey($step), $welcomeConversationId),
                prompt: $this->stepPrompt($this->stepKey($step), $welcomeConversationId),
                icon: (string) $step->attribute('icon'),
                complete: $step->complete(),
            ))
            ->all());
    }

    private function stepKey(OnboardingStep $step): ActivationStep
    {
        $key = $step->attribute('key');

        return $key instanceof ActivationStep ? $key : ActivationStep::from((string) $key);
    }

    /**
     * Every registered step needs a destination. Matching the key exhaustively
     * means a step added to ActivationSteps without one fails here rather than
     * quietly rendering a row that links to the People list.
     *
     * AskRela links straight to Rela's seeded welcome conversation so the step
     * opens onto the guided setup message. A workspace without one has no chat
     * to open: an id-less chat URL is not a destination, the page bounces
     * straight back to the dashboard, so that row seeds the composer instead.
     */
    private function stepUrl(ActivationStep $step, ?string $welcomeConversationId): ?string
    {
        return match ($step) {
            ActivationStep::FirstRecord => PeopleResource::getUrl('index'),
            ActivationStep::Import => ImportPeople::getUrl(),
            ActivationStep::Invite => Members::getUrl(),
            ActivationStep::AskRela => $welcomeConversationId === null
                ? null
                : ChatConversation::getUrl(['conversationId' => $welcomeConversationId]),
        };
    }

    /**
     * The question dropped into the dashboard composer for a step that has
     * nowhere to navigate. It is seeded, never sent: a checklist click must
     * not spend the workspace's credits on the user's behalf.
     */
    private function stepPrompt(ActivationStep $step, ?string $welcomeConversationId): ?string
    {
        if ($step !== ActivationStep::AskRela || $welcomeConversationId !== null) {
            return null;
        }

        return __('filament/pages/dashboard.activation.steps.ask_rela.prompt');
    }

    #[Computed]
    public function completedCount(): int
    {
        return count(array_filter(
            $this->steps,
            fn (ActivationStepData $step): bool => $step->complete,
        ));
    }

    /**
     * Whether the workspace still holds the demo records seeded at sign-up. Drives
     * the "this is sample data" line, which would be a lie in a second workspace.
     */
    #[Computed]
    public function hasSampleData(): bool
    {
        $team = $this->team();

        return $team instanceof Team && resolve(WorkspaceActivationFacts::class)->hasSampleData($team);
    }

    private function team(): ?Team
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Team ? $tenant : null;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
