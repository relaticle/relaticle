<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Models\Team;
use App\Services\WorkspaceActivationFacts;
use Spatie\Onboard\Facades\Onboard;

/**
 * The four activation steps every consumer reads: dashboard checklist,
 * welcome-message job, and the chat agent's workspace-state block.
 *
 * Titles and descriptions live in lang files and are resolved by the
 * consumers via the `label_key`/`description_key` attributes — `addStep()`
 * titles are evaluated at boot, before any user locale is known, so the
 * title given here is only a fallback identifier.
 */
final readonly class ActivationSteps
{
    public static function register(): void
    {
        Onboard::addStep('first_record', Team::class)
            ->attributes([
                'key' => 'first_record',
                'label_key' => 'filament/pages/dashboard.activation.steps.first_record.label',
                'description_key' => 'filament/pages/dashboard.activation.steps.first_record.description',
                'icon' => 'heroicon-o-user-plus',
            ])
            ->completeIf(fn (Team $model): bool => resolve(WorkspaceActivationFacts::class)->hasOwnRecord($model));

        Onboard::addStep('import', Team::class)
            ->attributes([
                'key' => 'import',
                'label_key' => 'filament/pages/dashboard.activation.steps.import.label',
                'description_key' => 'filament/pages/dashboard.activation.steps.import.description',
                'icon' => 'heroicon-o-arrow-up-tray',
            ])
            ->completeIf(fn (Team $model): bool => resolve(WorkspaceActivationFacts::class)->hasImportedRecord($model));

        Onboard::addStep('invite', Team::class)
            ->attributes([
                'key' => 'invite',
                'label_key' => 'filament/pages/dashboard.activation.steps.invite.label',
                'description_key' => 'filament/pages/dashboard.activation.steps.invite.description',
                'icon' => 'heroicon-o-user-group',
            ])
            ->completeIf(fn (Team $model): bool => resolve(WorkspaceActivationFacts::class)->hasTeammate($model));

        Onboard::addStep('ask_rela', Team::class)
            ->attributes([
                'key' => 'ask_rela',
                'label_key' => 'filament/pages/dashboard.activation.steps.ask_rela.label',
                'description_key' => 'filament/pages/dashboard.activation.steps.ask_rela.description',
                'icon' => 'heroicon-o-sparkles',
            ])
            ->completeIf(fn (Team $model): bool => resolve(WorkspaceActivationFacts::class)->hasUserChatMessage($model));
    }
}
