<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\ActivationStep;
use App\Models\Team;
use App\Services\WorkspaceActivationFacts;
use Spatie\Onboard\OnboardingSteps;

/**
 * The four activation steps every consumer reads: the activation checklist,
 * the setup-nudge command, and the chat agent's workspace-state block.
 *
 * Titles and descriptions live in lang files and are resolved by the
 * consumers via the `label_key`/`description_key` attributes. Step titles
 * are evaluated at registration, before any user locale is known, so the
 * title given here is only a fallback identifier.
 *
 * Registration takes the registry as an argument rather than going through
 * the Onboard facade: AppServiceProvider rebinds OnboardingSteps as a
 * non-singleton, so every resolve builds a fresh registry (see the binding
 * for why that is load-bearing) and each build must re-register these steps.
 *
 * Registration order IS display order, and it is ordered by what production
 * data says the step is worth, not by how the feature was built. Measured on
 * the analytics clone (2026-08-23, teams at least 8 days old, n=3587, "returned"
 * excluding a later import so importing twice cannot count as returning):
 * a day-0 record predicts a 10.0% return against a 4.0% base, day-0 chat 11.0%,
 * while import reaches 0.5% of workspaces and invite 0.3%. Import and invite
 * stay because they are true facts about the workspace and the setup nudge
 * reads the same registry, but they sit last: a checklist that opens on two
 * rows almost nobody completes reads as unachievable.
 */
final readonly class ActivationSteps
{
    public static function registerOn(OnboardingSteps $steps): void
    {
        $steps->addStep(ActivationStep::FirstRecord->value, Team::class)
            ->attributes([
                'key' => ActivationStep::FirstRecord,
                'label_key' => 'filament/pages/dashboard.activation.steps.first_record.label',
                'description_key' => 'filament/pages/dashboard.activation.steps.first_record.description',
                'icon' => 'heroicon-o-user-plus',
            ])
            ->completeIf(fn (Team $model): bool => resolve(WorkspaceActivationFacts::class)->hasOwnRecord($model));

        $steps->addStep(ActivationStep::AskRela->value, Team::class)
            ->attributes([
                'key' => ActivationStep::AskRela,
                'label_key' => 'filament/pages/dashboard.activation.steps.ask_rela.label',
                'description_key' => 'filament/pages/dashboard.activation.steps.ask_rela.description',
                'icon' => 'heroicon-o-sparkles',
            ])
            ->completeIf(fn (Team $model): bool => resolve(WorkspaceActivationFacts::class)->hasUserChatMessage($model));

        $steps->addStep(ActivationStep::Import->value, Team::class)
            ->attributes([
                'key' => ActivationStep::Import,
                'label_key' => 'filament/pages/dashboard.activation.steps.import.label',
                'description_key' => 'filament/pages/dashboard.activation.steps.import.description',
                'icon' => 'heroicon-o-arrow-up-tray',
            ])
            ->completeIf(fn (Team $model): bool => resolve(WorkspaceActivationFacts::class)->hasImportedRecord($model));

        $steps->addStep(ActivationStep::Invite->value, Team::class)
            ->attributes([
                'key' => ActivationStep::Invite,
                'label_key' => 'filament/pages/dashboard.activation.steps.invite.label',
                'description_key' => 'filament/pages/dashboard.activation.steps.invite.description',
                'icon' => 'heroicon-o-user-group',
            ])
            ->completeIf(fn (Team $model): bool => resolve(WorkspaceActivationFacts::class)->hasTeammate($model));
    }
}
