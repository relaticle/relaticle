<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Actions\Onboarding\SaveOnboardingUseCase;
use App\Enums\OnboardingUseCase;
use App\Enums\WorkspaceSetupStep;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

trait RunsUseCaseStep
{
    public function saveUseCase(): void
    {
        resolve(SaveOnboardingUseCase::class)->execute($this->authUser(), $this->workspace, $this->form->getState(), WorkspaceSetupStep::Invite);

        $this->redirectTo(self::getUrl(['tenant' => $this->workspace]));
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

    private function selectedUseCase(): ?OnboardingUseCase
    {
        return OnboardingUseCase::tryFrom((string) ($this->data['onboarding_use_case'] ?? ''));
    }
}
