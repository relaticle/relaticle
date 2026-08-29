<?php

declare(strict_types=1);

namespace App\Livewire\App\Onboarding;

use App\Actions\Onboarding\DismissActivationChecklist;
use App\Data\ActivationStepData;
use App\Enums\ActivationStep;
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

        return array_values($team->onboarding()->steps()
            ->map(fn (OnboardingStep $step): ActivationStepData => new ActivationStepData(
                key: $this->stepKey($step)->value,
                label: $this->stepLabel($this->stepKey($step), $step),
                description: __((string) $step->attribute('description_key')),
                url: $this->stepUrl($this->stepKey($step)),
                prompt: $this->stepPrompt($this->stepKey($step)),
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
     * AskRela has no transcript to open, so it seeds the composer instead: an
     * id-less chat URL is not a destination, the page bounces straight back to
     * the dashboard.
     */
    private function stepUrl(ActivationStep $step): ?string
    {
        return match ($step) {
            ActivationStep::FirstRecord => PeopleResource::getUrl('index'),
            ActivationStep::Import => ImportPeople::getUrl(),
            ActivationStep::Invite => Members::getUrl(),
            ActivationStep::AskRela => null,
        };
    }

    /**
     * The question dropped into the dashboard composer for a step that has
     * nowhere to navigate. It is seeded, never sent: a checklist click must
     * not spend the workspace's credits on the user's behalf.
     *
     * A workspace holding no records cannot answer a pipeline question: the
     * assistant spends a tool round-trip to report the zero this component
     * already knows, then offers to create a deal in a workspace with no
     * companies and no contacts. The capability question below answers the same
     * workspace with a setup plan it can act on. Empty means no records at all,
     * not `hasOwnRecord()` -- a freshly seeded workspace has no record of its
     * own either, and that one has a real pipeline to talk about.
     */
    private function stepPrompt(ActivationStep $step): ?string
    {
        if ($step !== ActivationStep::AskRela) {
            return null;
        }

        return __($this->hasAnyRecord()
            ? 'filament/pages/dashboard.activation.steps.ask_rela.prompt'
            : 'filament/pages/dashboard.activation.steps.ask_rela.prompt_empty');
    }

    /**
     * Follows the prompt: a row promising an answer about the pipeline must not
     * type a question about setting the workspace up.
     */
    private function stepLabel(ActivationStep $key, OnboardingStep $step): string
    {
        if ($key === ActivationStep::AskRela && ! $this->hasAnyRecord()) {
            return __('filament/pages/dashboard.activation.steps.ask_rela.label_empty');
        }

        return __((string) $step->attribute('label_key'));
    }

    private function hasAnyRecord(): bool
    {
        $team = $this->team();

        return $team instanceof Team && resolve(WorkspaceActivationFacts::class)->hasAnyRecord($team);
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
