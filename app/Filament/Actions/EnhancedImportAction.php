<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Services\Import\ExcelToCsvConverter;
use Closure;
use Filament\Actions\ImportAction;
use Filament\Actions\Imports\ImportColumn;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Csv\Reader as CsvReader;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Enhanced Import Action that supports both CSV and Excel files.
 *
 * Excel files are automatically converted to CSV before processing.
 * This is achieved by overriding getUploadedFileStream() to intercept
 * Excel files and convert them to CSV transparently.
 */
final class EnhancedImportAction extends ImportAction
{
    protected function setUp(): void
    {
        parent::setUp();

        // Override the schema with extended file type support
        $this->schema(fn (EnhancedImportAction $action): array => array_merge([
            FileUpload::make('file')
                ->label(__('filament-actions::import.modal.form.file.label'))
                ->placeholder('Upload a CSV or Excel file (.csv, .xlsx, .xls)')
                ->acceptedFileTypes(ExcelToCsvConverter::getAcceptedMimeTypes())
                ->rules($action->getFileValidationRules())
                ->afterStateUpdated(function (FileUpload $component, Component $livewire, Set $set, ?TemporaryUploadedFile $state) use ($action): void {
                    if (! $state instanceof TemporaryUploadedFile) {
                        return;
                    }

                    try {
                        $livewire->validateOnly($component->getStatePath());
                    } catch (ValidationException $exception) {
                        $component->state([]);

                        throw $exception;
                    }

                    $csvStream = $this->getUploadedFileStream($state);

                    if (! $csvStream) {
                        return;
                    }

                    $csvReader = CsvReader::createFromStream($csvStream);

                    if (filled($csvDelimiter = $this->getCsvDelimiter($csvReader))) {
                        $csvReader->setDelimiter($csvDelimiter);
                    }

                    $csvReader->setHeaderOffset($action->getHeaderOffset() ?? 0);

                    $csvColumns = $csvReader->getHeader();

                    $lowercaseCsvColumnValues = array_map(Str::lower(...), $csvColumns);
                    $lowercaseCsvColumnKeys = array_combine(
                        $lowercaseCsvColumnValues,
                        $csvColumns,
                    );

                    $set('columnMap', array_reduce($action->getImporter()::getColumns(), function (array $carry, ImportColumn $column) use ($lowercaseCsvColumnKeys, $lowercaseCsvColumnValues): array {
                        $carry[$column->getName()] = $lowercaseCsvColumnKeys[
                        Arr::first(
                            array_intersect(
                                $lowercaseCsvColumnValues,
                                $column->getGuesses(),
                            ),
                        )
                        ] ?? null;

                        return $carry;
                    }, []));
                })
                ->storeFiles(false)
                ->visibility('private')
                ->required()
                ->hiddenLabel(),
            Fieldset::make(__('filament-actions::import.modal.form.columns.label'))
                ->columns(1)
                ->inlineLabel()
                ->schema(function (Get $get) use ($action): array {
                    $file = $get('file');

                    if (! $file instanceof TemporaryUploadedFile) {
                        return [];
                    }

                    $csvStream = $this->getUploadedFileStream($file);

                    if (! $csvStream) {
                        return [];
                    }

                    $csvReader = CsvReader::createFromStream($csvStream);

                    if (filled($csvDelimiter = $this->getCsvDelimiter($csvReader))) {
                        $csvReader->setDelimiter($csvDelimiter);
                    }

                    $csvReader->setHeaderOffset($action->getHeaderOffset() ?? 0);

                    $csvColumns = $csvReader->getHeader();
                    $csvColumnOptions = array_combine($csvColumns, $csvColumns);

                    return array_map(
                        fn (ImportColumn $column): Select => $column->getSelect()->options($csvColumnOptions),
                        $action->getImporter()::getColumns(),
                    );
                })
                ->statePath('columnMap')
                ->visible(fn (Get $get): bool => $get('file') instanceof TemporaryUploadedFile),
        ], $action->getImporter()::getOptionsFormComponents()));
    }

    /**
     * Override parent's file stream method to convert Excel files to CSV.
     *
     * @return resource|false
     */
    public function getUploadedFileStream(TemporaryUploadedFile $file): mixed
    {
        $converter = app(ExcelToCsvConverter::class);

        $uploadedFile = new UploadedFile(
            $file->getRealPath(),
            $file->getClientOriginalName(),
            $file->getMimeType(),
        );

        if ($converter->isExcelFile($uploadedFile)) {
            try {
                $csvFile = $converter->convert($uploadedFile);

                return fopen($csvFile->getRealPath(), 'r');
            } catch (\Exception $e) {
                report($e);

                return false;
            }
        }

        return parent::getUploadedFileStream($file);
    }

    /**
     * @return array<mixed>
     */
    public function getFileValidationRules(): array
    {
        $acceptedExtensions = implode(',', ExcelToCsvConverter::getAcceptedExtensions());

        $fileRules = [
            "extensions:{$acceptedExtensions}",
            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                $csvStream = $this->getUploadedFileStream($value);

                if (! $csvStream) {
                    $fail('Unable to process the uploaded file. Please ensure it is a valid CSV or Excel file.');

                    return;
                }

                $csvReader = CsvReader::createFromStream($csvStream);

                if (filled($csvDelimiter = $this->getCsvDelimiter($csvReader))) {
                    $csvReader->setDelimiter($csvDelimiter);
                }

                $csvReader->setHeaderOffset($this->getHeaderOffset() ?? 0);

                $csvColumns = $csvReader->getHeader();

                $duplicateCsvColumns = [];

                foreach (array_count_values($csvColumns) as $header => $count) {
                    if ($count <= 1) {
                        continue;
                    }

                    $duplicateCsvColumns[] = $header;
                }

                if ($duplicateCsvColumns === []) {
                    return;
                }

                $filledDuplicateCsvColumns = array_filter($duplicateCsvColumns, filled(...));

                $fail(trans_choice('filament-actions::import.modal.form.file.rules.duplicate_columns', count($filledDuplicateCsvColumns), [
                    'columns' => implode(', ', $filledDuplicateCsvColumns),
                ]));
            },
        ];

        foreach ($this->fileValidationRules as $rules) {
            $rules = $this->evaluate($rules);

            if (is_string($rules)) {
                $rules = explode('|', $rules);
            }

            $fileRules = [
                ...$fileRules,
                ...$rules,
            ];
        }

        return $fileRules;
    }
}
