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
use SplFileObject;
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

        $rowCount = 0;

        $rows = $reader
            ->getRows()
            ->reject(fn (array $row): bool => array_all($row, blank(...)))
            ->take($this->maxRows() + 1);

        foreach ($rows as $row) {
            if (! $this->isUtf8($row)) {
                throw new ImportFileException(__('Row :row is not UTF-8 text. In Excel, choose File > Save As > CSV UTF-8, then upload the file again.', ['row' => $this->firstNonUtf8Line($path)]));
            }

            $rowCount++;
        }

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
                ->values()
                ->tapEach(function () use (&$rowCount): void {
                    $rowCount++;
                })
                ->map(fn (array $row, int $index): array => [
                    'row_number' => $index + 2,
                    'raw_data' => json_encode(
                        array_combine($headers, $this->normalizeRow($headers, $row)),
                        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                    'validation' => null,
                    'corrections' => null,
                ])
                ->chunk($this->chunkSize())
                ->each(fn (LazyCollection $chunk) => $store->query()->insert($chunk->all()));

            throw_if($rowCount === 0, ImportFileException::class, 'Could not read file. Please re-upload.');

            $store->persist();
        } catch (Throwable $e) {
            $this->discard($import, $store);

            throw $e;
        }

        $store->close();

        $import->update([
            'total_rows' => $rowCount,
            'status' => ImportStatus::Mapping,
        ]);

        return $import;
    }

    /**
     * @param  array<int|string, mixed>  $row
     */
    private function isUtf8(array $row): bool
    {
        return array_all($row, fn (mixed $cell): bool => ! is_string($cell) || mb_check_encoding($cell, 'UTF-8'));
    }

    private function firstNonUtf8Line(string $path): int
    {
        foreach (new SplFileObject($path) as $index => $line) {
            if (is_string($line) && ! mb_check_encoding($line, 'UTF-8')) {
                return $index + 1;
            }
        }

        return 0;
    }

    private function discard(Import $import, ImportStore $store): void
    {
        $store->close();
        ImportStore::delete($import->id);
        $import->delete();
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
