<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Support\ChunkIterator;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use League\Csv\Statement;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Relaticle\ImportWizard\Data\ColumnAnalysis;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Concerns\HasImportEntities;
use Relaticle\ImportWizard\Livewire\Concerns\HasColumnMapping;
use Relaticle\ImportWizard\Livewire\Concerns\HasCsvParsing;
use Relaticle\ImportWizard\Livewire\Concerns\HasImportPreview;
use Relaticle\ImportWizard\Livewire\Concerns\HasValueAnalysis;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Services\CsvReaderFactory;

/**
 * 4-step import wizard following the Attio pattern.
 *
 * Steps:
 * 1. Upload - File upload with row/column counts
 * 2. Map Columns - Smart auto-detection + manual adjustment
 * 3. Review Values - See unique values, fix invalid data
 * 4. Preview Import - Summary of creates/updates/skips before committing
 */
final class ImportWizard extends Component implements HasActions, HasForms
{
    use HasColumnMapping;
    use HasCsvParsing;
    use HasImportEntities;
    use HasImportPreview;
    use HasValueAnalysis;
    use InteractsWithActions;
    use InteractsWithForms;
    use WithFileUploads;

    // Step constants
    public const int STEP_UPLOAD = 1;

    public const int STEP_MAP = 2;

    public const int STEP_REVIEW = 3;

    public const int STEP_PREVIEW = 4;

    // Current step
    public int $currentStep = self::STEP_UPLOAD;

    // Entity type (passed from parent page, locked to prevent tampering)
    #[Locked]
    public string $entityType = 'companies';

    // URL to redirect to after import completion
    #[Locked]
    public ?string $returnUrl = null;

    #[Validate('required|file|max:102400')]
    public mixed $uploadedFile = null;

    public ?string $persistedFilePath = null;

    public int $rowCount = 0;

    /** @var array<string> */
    public array $csvHeaders = [];

    // Step 2: Column mapping
    /** @var array<string, string> */
    public array $columnMap = [];

    // Step 3: Value analysis
    /** @var array<array<string, mixed>> */
    public array $columnAnalysesData = [];

    /** @var array<string, array<string, string>> */
    public array $valueCorrections = [];

    // Review values UI state
    public int $reviewPage = 1;

    public ?string $expandedColumn = null;

    // Step 4: Preview
    /** @var array<string, mixed>|null */
    public ?array $previewResultData = null;

    /** @var array<int, array<string, mixed>> All rows for preview/editing */
    public array $previewRows = [];

    /**
     * Handle file upload.
     */
    public function updatedUploadedFile(): void
    {
        $this->resetErrorBag('uploadedFile');
        $this->parseUploadedFile();
    }

    /**
     * Move to the next step.
     */
    public function nextStep(): void
    {
        if (! $this->canProceedToNextStep()) {
            // If on review step with validation errors, show confirmation dialog
            if ($this->currentStep === self::STEP_REVIEW && $this->hasValidationErrors()) {
                $this->mountAction('proceedWithErrors');
            }

            return;
        }

        $this->advanceToNextStep();
    }

    /**
     * Actually advance to the next step (called directly or after confirmation).
     */
    public function advanceToNextStep(): void
    {
        // Perform step-specific actions before advancing
        match ($this->currentStep) {
            self::STEP_UPLOAD => $this->prepareForMapping(),
            self::STEP_MAP => $this->prepareForReview(),
            self::STEP_REVIEW => $this->prepareForPreview(),
            default => null,
        };

        $this->currentStep++;
    }

    /**
     * Move to the previous step.
     */
    public function previousStep(): void
    {
        if ($this->currentStep > self::STEP_UPLOAD) {
            $this->currentStep--;
        }
    }

    /**
     * Navigate directly to a specific step (for clickable step navigation).
     */
    public function goToStep(int $step): void
    {
        // Only allow navigating to completed steps or current step
        if ($step < self::STEP_UPLOAD || $step > $this->currentStep) {
            return;
        }

        $this->currentStep = $step;
    }

    /**
     * Check if user can proceed to the next step.
     */
    public function canProceedToNextStep(): bool
    {
        return match ($this->currentStep) {
            self::STEP_UPLOAD => $this->persistedFilePath !== null && $this->csvHeaders !== [],
            self::STEP_MAP => $this->hasAllRequiredMappings(),
            self::STEP_REVIEW => ! $this->hasValidationErrors(),
            self::STEP_PREVIEW => $this->hasRecordsToImport(),
            default => false,
        };
    }

    /**
     * Prepare for the mapping step.
     */
    private function prepareForMapping(): void
    {
        $this->autoMapColumns();
    }

    /**
     * Prepare for the review step.
     */
    private function prepareForReview(): void
    {
        $this->analyzeColumns();
        $this->reviewPage = 1;

        // Select first column by default
        $firstAnalysis = $this->columnAnalyses->first();
        $this->expandedColumn = $firstAnalysis?->mappedToField;
    }

    /**
     * Prepare for the preview step.
     */
    private function prepareForPreview(): void
    {
        $this->generateImportPreview();
    }

    /**
     * Execute the import.
     */
    public function executeImport(): void
    {
        if (! $this->hasRecordsToImport()) {
            Notification::make()
                ->title('No Records to Import')
                ->body('There are no records that would be created or updated.')
                ->warning()
                ->send();

            return;
        }

        $team = Filament::getTenant();
        $user = auth()->user();
        $importerClass = $this->getImporterClass();

        if ($team === null || $user === null || $importerClass === null || $this->persistedFilePath === null) {
            Notification::make()
                ->title('Import Error')
                ->body('Unable to start import. Please try again.')
                ->danger()
                ->send();

            return;
        }

        // Create the Import model
        $import = Import::create([
            'team_id' => $team->getKey(),
            'user_id' => $user->getAuthIdentifier(),
            'file_name' => $this->uploadedFile?->getClientOriginalName() ?? 'import.csv',
            'file_path' => $this->moveFileToPermanentStorage(),
            'importer' => $importerClass,
            'total_rows' => $this->rowCount,
            'processed_rows' => 0,
            'successful_rows' => 0,
        ]);

        // Dispatch import jobs
        $this->dispatchImportJobs($import);

        Notification::make()
            ->title('Import Started')
            ->body("Importing {$this->rowCount} records.")
            ->success()
            ->send();

        // Clean up and redirect
        $this->cleanupTempFile();

        if ($this->returnUrl !== null) {
            $this->redirect($this->returnUrl);
        }
    }

    /**
     * Move the temporary file to permanent storage.
     */
    private function moveFileToPermanentStorage(): string
    {
        $permanentPath = 'imports/'.Str::uuid()->toString().'.csv';

        // If we have corrections, apply them
        if ($this->valueCorrections !== []) {
            $correctedContent = $this->applyCorrectionsToCsv();
            Storage::disk('local')->put($permanentPath, $correctedContent);
        } else {
            // Just copy the file
            $tempStoragePath = str_replace(Storage::disk('local')->path(''), '', $this->persistedFilePath);
            Storage::disk('local')->copy($tempStoragePath, $permanentPath);
        }

        return $permanentPath;
    }

    /**
     * Apply value corrections to the CSV and return the corrected content.
     */
    private function applyCorrectionsToCsv(): string
    {
        $csvReader = app(CsvReaderFactory::class)->createFromPath($this->persistedFilePath);
        $headers = $csvReader->getHeader();

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            return '';
        }

        fputcsv($output, $headers, escape: '\\');

        foreach ($csvReader->getRecords() as $record) {
            foreach ($this->valueCorrections as $fieldName => $valueMappings) {
                $csvColumn = $this->columnMap[$fieldName] ?? null;
                if ($csvColumn !== null && isset($record[$csvColumn])) {
                    $currentValue = $record[$csvColumn];
                    if (isset($valueMappings[$currentValue])) {
                        $record[$csvColumn] = $valueMappings[$currentValue];
                    }
                }
            }

            fputcsv($output, array_values($record), escape: '\\');
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return $content !== false ? $content : '';
    }

    /**
     * Dispatch import jobs using Filament's job batching.
     */
    private function dispatchImportJobs(Import $import): void
    {
        $csvReader = app(CsvReaderFactory::class)->createFromPath(
            Storage::disk('local')->path($import->file_path)
        );

        $statement = new Statement;
        $records = $statement->process($csvReader);

        $chunkSize = 100;
        $chunkIterator = new ChunkIterator($records->getIterator(), $chunkSize);

        $jobs = [];
        foreach ($chunkIterator->get() as $chunk) {
            $jobs[] = new ImportCsv(
                import: $import,
                rows: base64_encode(serialize($chunk)),
                columnMap: $this->columnMap,
                options: [
                    'duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW,
                ],
            );
        }

        Bus::batch($jobs)
            ->name("import-{$import->getKey()}")
            ->finally(function () use ($import): void {
                $import->update(['completed_at' => now()]);
            })
            ->dispatch();
    }

    /**
     * Remove the current file and allow selecting a new one.
     */
    public function removeFile(): void
    {
        $this->cleanupTempFile();
        $this->uploadedFile = null;
        $this->persistedFilePath = null;
        $this->rowCount = 0;
        $this->csvHeaders = [];
        $this->columnMap = [];
    }

    /**
     * Reset the wizard to start a new import.
     */
    public function resetWizard(): void
    {
        $this->cleanupTempFile();

        $this->currentStep = self::STEP_UPLOAD;
        // Note: entityType and returnUrl are locked, don't reset them
        $this->uploadedFile = null;
        $this->persistedFilePath = null;
        $this->rowCount = 0;
        $this->csvHeaders = [];
        $this->columnMap = [];
        $this->columnAnalysesData = [];
        $this->valueCorrections = [];
        $this->previewResultData = null;
        $this->previewRows = [];
        $this->reviewPage = 1;
        $this->expandedColumn = null;
    }

    /**
     * Get the count of rows that will be imported.
     */
    public function getActiveRowCount(): int
    {
        return $this->previewResultData['totalRows'] ?? 0;
    }

    /**
     * Cancel the import and return to the resource list.
     */
    public function cancelImport(): void
    {
        $this->cleanupTempFile();

        if ($this->returnUrl !== null) {
            $this->redirect($this->returnUrl);
        }
    }

    /**
     * Get the entity label for display.
     */
    public function getEntityLabel(): string
    {
        $entities = $this->getEntities();

        return $entities[$this->entityType]['label'] ?? str($this->entityType)->title()->toString();
    }

    /**
     * Toggle column expansion in review step.
     */
    public function toggleColumn(string $columnName): void
    {
        $this->expandedColumn = $this->expandedColumn === $columnName ? null : $columnName;
        $this->reviewPage = 1;
    }

    /**
     * Get the step labels for the progress indicator.
     *
     * @return array<int, string>
     */
    public function getStepLabels(): array
    {
        return [
            self::STEP_UPLOAD => 'Upload',
            self::STEP_MAP => 'Map Columns',
            self::STEP_REVIEW => 'Review Values',
            self::STEP_PREVIEW => 'Preview',
        ];
    }

    /**
     * Action for confirming to proceed with validation errors.
     */
    public function proceedWithErrorsAction(): Action
    {
        return Action::make('proceedWithErrors')
            ->label('Continue with errors')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Continue with validation errors?')
            ->modalDescription(fn (): string => $this->getAffectedRowCount().' rows have validation errors and will be skipped.')
            ->modalSubmitActionLabel('Skip errors and continue')
            ->action(function (): void {
                $this->skipAllErrorValues();
                $this->advanceToNextStep();
            });
    }

    /**
     * Skip all values that have validation errors.
     */
    private function skipAllErrorValues(): void
    {
        /** @var ColumnAnalysis $analysis */
        foreach ($this->columnAnalyses as $analysis) {
            foreach ($analysis->issues as $issue) {
                if ($issue->severity === 'error') {
                    $this->skipValue($analysis->mappedToField, $issue->value);
                }
            }
        }
    }

    /**
     * Get the total number of rows affected by validation errors.
     */
    private function getAffectedRowCount(): int
    {
        $count = 0;

        /** @var ColumnAnalysis $analysis */
        foreach ($this->columnAnalyses as $analysis) {
            foreach ($analysis->issues as $issue) {
                if ($issue->severity === 'error') {
                    $count += $issue->rowCount;
                }
            }
        }

        return $count;
    }

    public function render(): View
    {
        return view('import-wizard::livewire.import-wizard');
    }
}
