<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Steps;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
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

    private ?Import $import = null;

    private ?ImportStore $store = null;

    public function mount(ImportEntityType $entityType, ?string $storeId = null): void
    {
        $this->entityType = $entityType;
        $this->storeId = $storeId;

        if ($storeId === null) {
            return;
        }

        $this->import = Import::query()
            ->forTeam($this->getCurrentTeamId() ?? '')
            ->find($storeId);

        if (! $this->import instanceof Import) {
            return;
        }

        $this->headers = $this->import->headers ?? [];
        $this->rowCount = $this->import->total_rows;
        $this->isParsed = true;
    }

    private function getCurrentTeamId(): ?string
    {
        $tenant = filament()->getTenant();

        return $tenant instanceof Model ? (string) $tenant->getKey() : null;
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
        if (! $this->uploadedFile instanceof TemporaryUploadedFile) {
            return;
        }

        $this->cleanupExisting();
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
            report($e);
            $this->addError('uploadedFile', 'Unable to process this file. Please check the format and try again.');
        }
    }

    public function continueToMapping(): void
    {
        if (! $this->isParsed || ! $this->uploadedFile instanceof TemporaryUploadedFile) {
            $this->addError('uploadedFile', 'File no longer available. Please re-upload.');
            $this->reset(['headers', 'rowCount', 'isParsed']);

            return;
        }

        $teamId = $this->getCurrentTeamId();

        if (blank($teamId)) {
            $this->addError('uploadedFile', 'Unable to determine your workspace. Please refresh and try again.');

            return;
        }

        try {
            $this->import = Import::create([
                'team_id' => $teamId,
                'user_id' => (string) auth()->id(),
                'entity_type' => $this->entityType,
                'file_name' => $this->uploadedFile->getClientOriginalName(),
                'status' => ImportStatus::Uploading,
                'total_rows' => 0,
                'headers' => $this->headers,
            ]);

            $this->store = ImportStore::create($this->import->id);

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
                ->each(fn (LazyCollection $chunk) => $this->store->query()->insert($chunk->all()));

            if ($rowCount === 0) {
                $this->store->destroy();
                $this->store = null;
                $this->import->delete();
                $this->import = null;
                $this->addError('uploadedFile', 'Could not read file. Please re-upload.');
                $this->reset(['headers', 'rowCount', 'isParsed']);

                return;
            }

            $this->import->update([
                'total_rows' => $rowCount,
                'status' => ImportStatus::Mapping,
            ]);

            $this->dispatch('completed', storeId: $this->import->id, rowCount: $rowCount, columnCount: count($this->headers));
        } catch (\Exception $e) {
            report($e);
            $this->store?->destroy();
            $this->store = null;
            $this->import?->delete();
            $this->import = null;
            $this->addError('uploadedFile', 'Unable to process this file. Please try again or use a different file.');
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
        $this->cleanupExisting();
        $this->reset(['storeId', 'uploadedFile', 'headers', 'rowCount', 'isParsed']);
    }

    private function cleanupExisting(): void
    {
        if ($this->store !== null) {
            $this->store->destroy();
            $this->store = null;
        }

        if ($this->import !== null) {
            $this->import->delete();
            $this->import = null;
        }
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
