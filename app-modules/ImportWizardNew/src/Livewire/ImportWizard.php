<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Support\Icons\Heroicon;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Relaticle\ImportWizardNew\Enums\ImportEntityType;
use Relaticle\ImportWizardNew\Store\ImportStore;

/**
 * Parent orchestrator for the 4-step import wizard.
 *
 * This component manages navigation between steps and holds shared state.
 * Each step is a separate child component that communicates via events or $parent.
 *
 * Steps:
 * 1. Upload - File upload with row/column counts (dispatches @completed event)
 * 2. Map Columns - Auto-detection + manual adjustment (uses $parent.nextStep())
 * 3. Review Values - See unique values, fix invalid data (uses $parent.nextStep())
 * 4. Preview/Import - Summary of creates/updates before committing
 */
final class ImportWizard extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public const int STEP_UPLOAD = 1;

    public const int STEP_MAP = 2;

    public const int STEP_REVIEW = 3;

    public const int STEP_PREVIEW = 4;

    public int $currentStep = self::STEP_UPLOAD;

    #[Locked]
    public ImportEntityType $entityType = ImportEntityType::Company;

    #[Locked]
    public ?string $returnUrl = null;

    public ?string $storeId = null;

    public int $rowCount = 0;

    public int $columnCount = 0;

    public function mount(ImportEntityType $entityType, ?string $returnUrl = null): void
    {
        $this->entityType = $entityType;
        $this->returnUrl = $returnUrl;
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.import-wizard');
    }

    /**
     * Called via @completed event on UploadStep component tag.
     */
    public function onUploadCompleted(string $storeId, int $rowCount, int $columnCount): void
    {
        $this->storeId = $storeId;
        $this->rowCount = $rowCount;
        $this->columnCount = $columnCount;
        $this->nextStep();
    }

    /**
     * Called via @completed event OR directly via $parent.nextStep() from children.
     */
    public function nextStep(): void
    {
        $this->currentStep = min($this->currentStep + 1, self::STEP_PREVIEW);
    }

    /**
     * Called directly via $parent.goBack() from children.
     */
    public function goBack(): void
    {
        $this->currentStep = max($this->currentStep - 1, self::STEP_UPLOAD);
    }

    /**
     * Navigate directly to a specific step (only for completed steps).
     */
    public function goToStep(int $step): void
    {
        if ($step < self::STEP_UPLOAD || $step >= $this->currentStep) {
            return;
        }

        if ($step === self::STEP_UPLOAD) {
            $this->mountAction('startOver');

            return;
        }

        $this->currentStep = $step;
    }

    public function getStepTitle(): string
    {
        return match ($this->currentStep) {
            self::STEP_UPLOAD => 'Upload CSV',
            self::STEP_MAP => 'Map Columns',
            self::STEP_REVIEW => 'Review Values',
            self::STEP_PREVIEW => 'Preview & Import',
            default => '',
        };
    }

    public function getStepDescription(): string
    {
        return match ($this->currentStep) {
            self::STEP_UPLOAD => 'Upload your CSV file to import '.$this->entityType->label(),
            self::STEP_MAP => 'Match CSV columns to '.$this->entityType->singular().' fields',
            self::STEP_REVIEW => 'Review and fix any data issues',
            self::STEP_PREVIEW => 'Preview changes and start import',
            default => '',
        };
    }

    public function cancelImport(): void
    {
        if ($this->storeId !== null) {
            ImportStore::load($this->storeId)?->destroy();
        }

        if ($this->returnUrl !== null) {
            $this->redirect($this->returnUrl);
        }
    }

    public function startOverAction(): Action
    {
        return Action::make('startOver')
            ->label('Start over')
            ->color('gray')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalHeading('Start a new import?')
            ->modalDescription('Are you sure you want to start a new import? Your current progress will be lost.')
            ->modalSubmitActionLabel('Start over')
            ->modalCancelActionLabel('Cancel')
            ->action(fn () => $this->startOver());
    }

    public function startOver(): void
    {
        if ($this->storeId !== null) {
            ImportStore::load($this->storeId)?->destroy();
        }

        $this->storeId = null;
        $this->rowCount = 0;
        $this->columnCount = 0;
        $this->currentStep = self::STEP_UPLOAD;
    }
}
