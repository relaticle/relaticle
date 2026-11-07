<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Actions\Onboarding\SaveOnboardingDataSource;
use App\Enums\OnboardingDataSource;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

trait RunsSourceStep
{
    public function saveSource(): void
    {
        resolve(SaveOnboardingDataSource::class)->execute($this->authUser(), $this->workspace, $this->form->getState());

        $this->redirectTo(self::getUrl(['tenant' => $this->workspace]));
    }

    /**
     * @return array<Component>
     */
    private function sourceComponents(): array
    {
        $sources = OnboardingDataSource::offered();

        return [
            Radio::make('onboarding_data_source')
                ->label(__('filament/pages/workspaces.setup_workspace.source.label'))
                ->validationAttribute(__('filament/pages/workspaces.setup_workspace.source.validation_attribute'))
                ->required()
                ->options(collect($sources)->mapWithKeys(fn (OnboardingDataSource $source): array => [$source->value => $source->getLabel()])->all())
                ->descriptions(collect($sources)->mapWithKeys(fn (OnboardingDataSource $source): array => [$source->value => $source->getDescription()])->all())
                ->live()
                ->afterStateUpdated(function (): void {
                    $this->resetErrorBag('data.onboarding_data_source');
                }),
            TextInput::make('onboarding_data_source_detail')
                ->label(__('filament/pages/workspaces.setup_workspace.source.detail_label'))
                ->validationAttribute(__('filament/pages/workspaces.setup_workspace.source.detail_label'))
                ->maxLength(120)
                ->visible(fn (Get $get): bool => $get('onboarding_data_source') === OnboardingDataSource::OtherCrm->value),
        ];
    }
}
