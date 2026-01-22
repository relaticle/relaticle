<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Steps;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Store\ImportStore;
use Spatie\SimpleExcel\SimpleExcelReader;

final class UploadStep extends Component implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    #[Locked]
    public ImportEntityType $entityType;

    #[Locked]
    public ?string $storeId = null;

    #[Validate('required|file|max:10240|mimes:csv,txt')]
    public ?TemporaryUploadedFile $uploadedFile = null;

    /** @var list<string> */
    public array $headers = [];

    public int $rowCount = 0;

    public bool $isParsed = false;

    private ?ImportStore $store = null;

    public function mount(ImportEntityType $entityType, ?string $storeId = null): void
    {
        $this->entityType = $entityType;
        $this->storeId = $storeId;
        $this->store = $storeId ? ImportStore::load($storeId) : null;

        if (! $this->store instanceof \Relaticle\ImportWizard\Store\ImportStore) {
            return;
        }

        $this->headers = $this->store->headers();
        $this->rowCount = $this->store->rowCount();
        $this->isParsed = true;
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.upload-step');
    }

    public function updatedUploadedFile(): void
    {
        $this->resetErrorBag('uploadedFile');
        $this->validateFile();
    }

    private function validateFile(): void
    {
        if (! $this->uploadedFile instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
            return;
        }

        $this->store?->destroy();
        $this->store = null;
        $this->reset(['headers', 'rowCount', 'isParsed']);

        try {
            $reader = SimpleExcelReader::create($this->uploadedFile->getRealPath())->trimHeaderRow();
            $rawHeaders = $reader->getHeaders();

            if (blank($rawHeaders)) {
                $this->addError('uploadedFile', 'CSV file is empty');

                return;
            }

            $headers = $this->processHeaders($rawHeaders);
            if ($headers === null) {
                return;
            }

            $rowCount = $reader
                ->getRows()
                ->reject(fn (array $row): bool => array_all($row, blank(...)))
                ->take($this->maxRows() + 1)
                ->count();

            if ($rowCount === 0) {
                $this->addError('uploadedFile', 'CSV file has no data rows');

                return;
            }

            if ($rowCount > $this->maxRows()) {
                $this->addError('uploadedFile', "Maximum {$this->maxRows()} rows allowed.");

                return;
            }

            $this->headers = $headers;
            $this->rowCount = $rowCount;
            $this->isParsed = true;
        } catch (\Exception $e) {
            $this->addError('uploadedFile', 'Invalid CSV file: '.$e->getMessage());
        }
    }

    public function continueToMapping(): void
    {
        if (! $this->isParsed || ! $this->uploadedFile instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
            $this->addError('uploadedFile', 'File no longer available. Please re-upload.');
            $this->reset(['headers', 'rowCount', 'isParsed']);

            return;
        }

        try {
            $this->store = ImportStore::create(
                teamId: (string) filament()->getTenant()?->getKey(),
                userId: (string) auth()->id(),
                entityType: $this->entityType,
                originalFilename: $this->uploadedFile->getClientOriginalName(),
            );

            $this->store->setHeaders($this->headers);

            // Stream directly from CSV to SQLite - never loads all rows into memory
            $rowCount = 0;

            SimpleExcelReader::create($this->uploadedFile->getRealPath())
                ->trimHeaderRow()
                ->getRows()
                ->reject(fn (array $row): bool => array_all($row, blank(...)))
                ->take($this->maxRows())
                ->map(function (array $row) use (&$rowCount): array {
                    return [
                        'row_number' => ++$rowCount + 1,
                        'raw_data' => json_encode(
                            array_combine($this->headers, $this->normalizeRow($row)),
                            JSON_UNESCAPED_UNICODE
                        ) ?: '{}',
                        'validation' => null,
                        'corrections' => null,
                    ];
                })
                ->chunk($this->chunkSize())
                ->each(fn ($chunk) => $this->store->query()->insert($chunk->all()));

            if ($rowCount === 0) {
                $this->store->destroy();
                $this->store = null;
                $this->addError('uploadedFile', 'Could not read file. Please re-upload.');
                $this->reset(['headers', 'rowCount', 'isParsed']);

                return;
            }

            $this->store->setRowCount($rowCount);
            $this->store->setStatus(ImportStatus::Mapping);

            $this->dispatch('completed', storeId: $this->store->id(), rowCount: $rowCount, columnCount: count($this->headers));
        } catch (\Exception $e) {
            $this->store?->destroy();
            $this->store = null;
            $this->addError('uploadedFile', 'Failed to process file: '.$e->getMessage());
        }
    }

    /**
     * @param  array<int|string, string>  $rawHeaders
     * @return list<string>|null
     */
    private function processHeaders(array $rawHeaders): ?array
    {
        /** @var list<string> $headers */
        $headers = collect($rawHeaders)
            ->values()
            ->map(fn (string $h, int $i): string => filled($h) ? $h : 'Column_'.($i + 1))
            ->all();

        if (count($headers) !== count(array_unique($headers))) {
            $this->addError('uploadedFile', 'Duplicate column names found.');

            return null;
        }

        return $headers;
    }

    /**
     * Normalize row values to match header count.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function normalizeRow(array $row): array
    {
        $headerCount = count($this->headers);
        $values = array_values($row);

        return array_slice(array_pad($values, $headerCount, ''), 0, $headerCount);
    }

    public function removeFile(): void
    {
        $this->store?->destroy();
        $this->store = null;
        $this->reset(['storeId', 'uploadedFile', 'headers', 'rowCount', 'isParsed']);
    }

    private function maxRows(): int
    {
        return once(fn (): int => (int) config('import-wizard.max_rows', 10_000));
    }

    private function chunkSize(): int
    {
        return once(fn (): int => (int) config('import-wizard.chunk_size', 500));
    }
}
