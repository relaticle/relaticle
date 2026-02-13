<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;

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

    #[Url(as: 'import')]
    public ?string $storeId = null;

    public int $rowCount = 0;

    public int $columnCount = 0;

    public bool $importStarted = false;

    public bool $validationInProgress = false;

    public function mount(ImportEntityType $entityType, ?string $returnUrl = null): void
    {
        $this->entityType = $entityType;
        $this->returnUrl = $returnUrl;

        $this->restoreFromStore();
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.import-wizard');
    }

    public function onUploadCompleted(string $storeId, int $rowCount, int $columnCount): void
    {
        $this->storeId = $storeId;
        $this->rowCount = $rowCount;
        $this->columnCount = $columnCount;
        $this->nextStep();
    }

    public function nextStep(): void
    {
        $this->currentStep = min($this->currentStep + 1, self::STEP_PREVIEW);
    }

    public function goBack(): void
    {
        if ($this->importStarted || $this->validationInProgress) {
            return;
        }

        $this->currentStep = max($this->currentStep - 1, self::STEP_UPLOAD);
        $this->syncStepStatus();
    }

    public function goToStep(int $step): void
    {
        if ($this->importStarted || $this->validationInProgress) {
            return;
        }

        if ($step < self::STEP_UPLOAD || $step >= $this->currentStep) {
            return;
        }

        if ($step === self::STEP_UPLOAD) {
            $this->mountAction('startOver');

            return;
        }

        $this->currentStep = $step;
        $this->syncStepStatus();
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
        $label = $this->entityType->label();
        $singular = $this->entityType->singular();

        return match ($this->currentStep) {
            self::STEP_UPLOAD => "Upload your CSV file to import {$label}",
            self::STEP_MAP => "Match CSV columns to {$singular} fields",
            self::STEP_REVIEW => 'Review and fix any data issues',
            self::STEP_PREVIEW => 'Preview changes and start import',
            default => '',
        };
    }

    public function cancelImport(): void
    {
        if ($this->storeId !== null) {
            $this->destroyImportAndStore($this->storeId);
        }

        if ($this->returnUrl !== null) {
            $this->redirect($this->returnUrl);
        }
    }

    #[On('import-started')]
    public function onImportStarted(): void
    {
        $this->importStarted = true;
    }

    #[On('validation-state-changed')]
    public function onValidationStateChanged(bool $inProgress): void
    {
        $this->validationInProgress = $inProgress;
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
            $this->destroyImportAndStore($this->storeId);
        }

        $this->storeId = null;
        $this->rowCount = 0;
        $this->columnCount = 0;
        $this->importStarted = false;
        $this->currentStep = self::STEP_UPLOAD;
    }

    private function restoreFromStore(): void
    {
        $import = $this->findCurrentImport();

        if ($import === null) {
            $this->storeId = null;

            return;
        }

        $this->rowCount = $import->total_rows;
        $this->columnCount = count($import->headers ?? []);
        $this->currentStep = $this->stepFromStatus($import->status);
        $this->importStarted = in_array($import->status, [
            ImportStatus::Importing,
            ImportStatus::Completed,
            ImportStatus::Failed,
        ], true);
    }

    private function syncStepStatus(): void
    {
        $this->findCurrentImport()
            ?->update(['status' => $this->statusForStep($this->currentStep)]);
    }

    private function findCurrentImport(): ?Import
    {
        if ($this->storeId === null) {
            return null;
        }

        $teamId = $this->getCurrentTeamId();

        if ($teamId === null) {
            return null;
        }

        $import = Import::query()
            ->forTeam($teamId)
            ->find($this->storeId);

        return $import instanceof Import ? $import : null;
    }

    private function statusForStep(int $step): ImportStatus
    {
        return match ($step) {
            self::STEP_UPLOAD => ImportStatus::Uploading,
            self::STEP_MAP => ImportStatus::Mapping,
            self::STEP_REVIEW => ImportStatus::Reviewing,
            default => ImportStatus::Previewing,
        };
    }

    private function stepFromStatus(ImportStatus $status): int
    {
        return match ($status) {
            ImportStatus::Uploading => self::STEP_UPLOAD,
            ImportStatus::Mapping => self::STEP_MAP,
            ImportStatus::Reviewing => self::STEP_REVIEW,
            ImportStatus::Previewing, ImportStatus::Importing, ImportStatus::Completed, ImportStatus::Failed => self::STEP_PREVIEW,
        };
    }

    private function destroyImportAndStore(string $importId): void
    {
        Import::query()->where('id', $importId)->delete();
        ImportStore::load($importId)?->destroy();
    }

    private function getCurrentTeamId(): ?string
    {
        $tenant = filament()->getTenant();

        return $tenant instanceof Model ? (string) $tenant->getKey() : null;
    }
}
