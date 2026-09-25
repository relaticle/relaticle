<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Illuminate\Support\LazyCollection;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Exceptions\ImportFileException;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

final readonly class ImportFileLoader
{
    /**
     * @return array{headers: list<string>, row_count: int}
     */
    public function inspect(string $path): array
    {
        $reader = $this->reader($path);
        $rawHeaders = $reader->getHeaders();

        throw_if(blank($rawHeaders), ImportFileException::class, 'CSV file is empty');

        $headers = $this->processHeaders($rawHeaders);

        $rowCount = $reader
            ->getRows()
            ->reject(fn (array $row): bool => array_all($row, blank(...)))
            ->take($this->maxRows() + 1)
            ->count();

        throw_if($rowCount === 0, ImportFileException::class, 'CSV file has no data rows');

        if ($rowCount > $this->maxRows()) {
            throw new ImportFileException("Maximum {$this->maxRows()} rows allowed.");
        }

        return ['headers' => $headers, 'row_count' => $rowCount];
    }

    public function load(string $path, string $fileName, ImportEntityType $entityType, string $workspaceId, string $userId): Import
    {
        $headers = $this->inspect($path)['headers'];

        $import = Import::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'entity_type' => $entityType,
            'file_name' => $fileName,
            'status' => ImportStatus::Uploading,
            'total_rows' => 0,
            'headers' => $headers,
        ]);

        $store = ImportStore::create($import->id);
        $rowCount = 0;

        try {
            $this->reader($path)
                ->getRows()
                ->reject(fn (array $row): bool => array_all($row, blank(...)))
                ->take($this->maxRows())
                ->map(function (array $row) use ($headers, &$rowCount): array {
                    return [
                        'row_number' => ++$rowCount + 1,
                        'raw_data' => json_encode(
                            array_combine($headers, $this->normalizeRow($headers, $row)),
                            JSON_UNESCAPED_UNICODE
                        ) ?: '{}',
                        'validation' => null,
                        'corrections' => null,
                    ];
                })
                ->chunk($this->chunkSize())
                ->each(fn (LazyCollection $chunk) => $store->query()->insert($chunk->all()));
        } catch (Throwable $e) {
            $store->destroy();
            $import->delete();

            throw $e;
        }

        if ($rowCount === 0) {
            $store->destroy();
            $import->delete();

            throw new ImportFileException('Could not read file. Please re-upload.');
        }

        $import->update([
            'total_rows' => $rowCount,
            'status' => ImportStatus::Mapping,
        ]);

        return $import;
    }

    private function reader(string $path): SimpleExcelReader
    {
        return SimpleExcelReader::create($path, 'csv')->trimHeaderRow();
    }

    /**
     * @param  array<int|string, string>  $rawHeaders
     * @return list<string>
     */
    private function processHeaders(array $rawHeaders): array
    {
        /** @var list<string> $headers */
        $headers = collect($rawHeaders)
            ->values()
            ->map(fn (string $h, int $i): string => filled($h) ? $h : 'Column_'.($i + 1))
            ->map(fn (string $h): string => preg_replace('/[^a-zA-Z0-9_ \-]/', '', $h))
            ->map(fn (string $h, int $i): string => filled($h) ? $h : 'Column_'.($i + 1))
            ->all();

        throw_if(count($headers) !== count(array_unique($headers)), ImportFileException::class, 'Duplicate column names found.');

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function normalizeRow(array $headers, array $row): array
    {
        $headerCount = count($headers);
        $values = array_values($row);

        return array_slice(array_pad($values, $headerCount, ''), 0, $headerCount);
    }

    private function maxRows(): int
    {
        return (int) config('import-wizard.max_rows', 10_000);
    }

    private function chunkSize(): int
    {
        return (int) config('import-wizard.chunk_size', 500);
    }
}
