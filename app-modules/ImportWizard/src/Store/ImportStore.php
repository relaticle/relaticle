<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Store;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Importers\BaseImporter;
use Relaticle\ImportWizard\Jobs\ValidateColumnJob;
use Relaticle\ImportWizard\Support\ImportValueValidator;

/**
 * Manages a single import session with SQLite storage.
 *
 * Inspired by Sushi package patterns for SQLite handling.
 *
 * Each import gets its own folder:
 * storage/app/imports/{id}/
 * ├── meta.json      # Status, headers, mappings
 * └── data.sqlite    # Row data with validation/corrections
 */
final class ImportStore
{
    private ?Connection $connection = null;

    /** @var array<string, mixed>|null */
    private ?array $metaCache = null;

    private ?ImportValueValidator $validator = null;

    public function __construct(
        private readonly string $id,
    ) {}

    /**
     * Create a new import session.
     */
    public static function create(
        string $teamId,
        string $userId,
        ImportEntityType $entityType,
        string $originalFilename,
    ): self {
        $id = (string) Str::ulid();
        $store = new self($id);

        File::ensureDirectoryExists($store->path());

        $store->writeMeta([
            'id' => $id,
            'team_id' => $teamId,
            'user_id' => $userId,
            'entity_type' => $entityType->value,
            'status' => ImportStatus::Uploading->value,
            'original_filename' => $originalFilename,
            'headers' => [],
            'row_count' => 0,
            'column_mappings' => [],
            'results' => null,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        $store->createDatabase();

        return $store;
    }

    /**
     * Load an existing import session.
     */
    public static function load(string $id): ?self
    {
        $store = new self($id);

        if (! File::exists($store->metaPath())) {
            return null;
        }

        // Ensure SQLite connection is registered (critical for queue workers)
        $store->connection();

        return $store;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function path(): string
    {
        return storage_path("app/imports/{$this->id}");
    }

    public function metaPath(): string
    {
        return $this->path().'/meta.json';
    }

    public function sqlitePath(): string
    {
        return $this->path().'/data.sqlite';
    }

    // =========================================================================
    // META.JSON OPERATIONS
    // =========================================================================

    /**
     * @return array<string, mixed>
     *
     * @throws FileNotFoundException
     */
    public function meta(): array
    {
        if ($this->metaCache === null) {
            $content = File::get($this->metaPath());
            $this->metaCache = json_decode($content, true);
        }

        return $this->metaCache;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function writeMeta(array $data): void
    {
        $data['updated_at'] = now()->toIso8601String();
        $this->metaCache = $data;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json !== false) {
            File::put($this->metaPath(), $json);
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function updateMeta(array $updates): void
    {
        $this->writeMeta(array_merge($this->meta(), $updates));
    }

    public function status(): ImportStatus
    {
        return ImportStatus::from($this->meta()['status']);
    }

    public function setStatus(ImportStatus $status): void
    {
        $this->updateMeta(['status' => $status->value]);
    }

    public function entityType(): ImportEntityType
    {
        return ImportEntityType::from($this->meta()['entity_type']);
    }

    public function teamId(): string
    {
        return $this->meta()['team_id'];
    }

    public function userId(): string
    {
        return $this->meta()['user_id'];
    }

    /**
     * @return list<string>
     *
     * @throws FileNotFoundException
     */
    public function headers(): array
    {
        return $this->meta()['headers'];
    }

    /**
     * @param  list<string>  $headers
     */
    public function setHeaders(array $headers): void
    {
        $this->updateMeta(['headers' => $headers]);
    }

    public function rowCount(): int
    {
        return $this->meta()['row_count'];
    }

    public function setRowCount(int $count): void
    {
        $this->updateMeta(['row_count' => $count]);
    }

    // =========================================================================
    // COLUMN MAPPINGS (unified ColumnData DTO approach)
    // =========================================================================

    /**
     * Get all column mappings as a collection with hydrated ImportField/RelationshipField.
     *
     * Uses Spatie Data's collect() for automatic deserialization,
     * then hydrates each ColumnData with its ImportField or RelationshipField for direct access.
     *
     * @return Collection<int, ColumnData>
     *
     * @throws FileNotFoundException
     */
    public function columnMappings(): Collection
    {
        $raw = $this->meta()['column_mappings'] ?? [];
        $importer = $this->getImporter();
        $fields = $importer->allFields();
        $relationships = collect($importer->relationships());

        return ColumnData::collect($raw, Collection::class)
            ->each(function (ColumnData $col) use ($fields, $relationships): void {
                if ($col->isFieldMapping()) {
                    $col->importField = $fields->get($col->target);
                } else {
                    $col->relationshipField = $relationships->get($col->relationship);
                }
            });
    }

    /**
     * Get the importer instance for this import session.
     */
    public function getImporter(): BaseImporter
    {
        return $this->entityType()->importer($this->teamId());
    }

    /**
     * Get the validator instance for this import session.
     */
    private function validator(): ImportValueValidator
    {
        if ($this->validator === null) {
            $this->validator = new ImportValueValidator($this->entityType()->value);
        }

        return $this->validator;
    }

    /**
     * Get choice options for a column mapping.
     * Delegates to ImportValueValidator for consistency.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getChoiceOptions(ColumnData $column): array
    {
        if (! $column->getType()->isChoiceField()) {
            return [];
        }

        return $this->validator()->getChoiceOptions($column);
    }

    /**
     * Set column mappings.
     *
     * @param  iterable<int, ColumnData>  $mappings
     */
    public function setColumnMappings(iterable $mappings): void
    {
        /** @var array<int, ColumnData> $mappingsArray */
        $mappingsArray = is_array($mappings) ? $mappings : iterator_to_array($mappings);

        $raw = collect($mappingsArray)
            ->map(fn (ColumnData $m): array => $m->toArray())
            ->values()
            ->all();

        $this->updateMeta(['column_mappings' => $raw]);
    }

    /**
     * Get a single column mapping by source (CSV column).
     */
    public function getColumnMapping(string $source): ?ColumnData
    {
        return $this->columnMappings()->firstWhere('source', $source);
    }

    /**
     * Update a single column mapping.
     */
    public function updateColumnMapping(string $source, ColumnData $newMapping): void
    {
        $mappings = $this->columnMappings()
            ->map(fn (ColumnData $m): ColumnData => $m->source === $source ? $newMapping : $m);

        $this->setColumnMappings($mappings);
    }

    /**
     * Get row data transformed to entity field keys.
     *
     * Applies corrections and remaps CSV columns to field names.
     *
     * @return array<string, mixed>
     *
     * @throws FileNotFoundException
     */
    public function getFieldData(ImportRow $row): array
    {
        $data = $row->getFinalData();

        return $this->columnMappings()
            ->filter(fn (ColumnData $m): bool => $m->isFieldMapping())
            ->mapWithKeys(fn (ColumnData $m): array => [$m->target => $data[$m->source] ?? null])
            ->all();
    }

    /**
     * Get relationship mappings.
     *
     * @return Collection<int, ColumnData>
     *
     * @throws FileNotFoundException
     */
    public function getRelationshipMappings(): Collection
    {
        return $this->columnMappings()->filter(fn (ColumnData $m): bool => $m->isRelationshipMapping());
    }

    // =========================================================================
    // SQLITE CONNECTION (Sushi-inspired patterns)
    // =========================================================================

    /**
     * Get the connection name for this import session.
     * Pattern from Sushi: unique connection name per instance.
     */
    public function connectionName(): string
    {
        return "import_{$this->id}";
    }

    /**
     * Get the SQLite connection for this import.
     */
    public function connection(): Connection
    {
        if (! $this->connection instanceof Connection) {
            $this->connection = $this->createConnection();
        }

        return $this->connection;
    }

    /**
     * Get an Eloquent query builder for ImportRow model.
     * Pattern from Sushi: use on() to bind model to dynamic connection.
     *
     * @return EloquentBuilder<ImportRow>
     */
    public function query(): EloquentBuilder
    {
        // Ensure connection is created and registered
        $this->connection();

        return ImportRow::on($this->connectionName());
    }

    /**
     * Create SQLite connection and register in config.
     * Pattern from Sushi: register connection for Laravel integration.
     */
    private function createConnection(): Connection
    {
        $name = $this->connectionName();
        $config = [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath(),
            'foreign_key_constraints' => true,
        ];

        // Register connection in config (Sushi pattern)
        resolve(Repository::class)->set("database.connections.{$name}", $config);

        return resolve(ConnectionFactory::class)->make($config, $name);
    }

    /**
     * Create the SQLite database file and schema.
     * Pattern from Sushi: file_put_contents for empty file, Schema Builder for tables.
     */
    private function createDatabase(): void
    {
        // Create empty SQLite file (Sushi pattern)
        file_put_contents($this->sqlitePath(), '');

        $this->createTableSafely();
    }

    /**
     * Create table with race condition handling.
     * Pattern from Sushi: catch "already exists" errors gracefully.
     */
    private function createTableSafely(): void
    {
        $schema = $this->connection()->getSchemaBuilder();

        try {
            $schema->create('import_rows', function ($table): void {
                $table->integer('row_number')->primary();
                $table->text('raw_data');  // NOT NULL - SQLite will reject if missing
                $table->text('validation')->nullable();
                $table->text('corrections')->nullable();
                $table->text('skipped')->nullable();  // JSON: {"column_name": true, ...}
                $table->text('value_hash')->nullable();  // SHA-256 hash for validation deduplication
                $table->string('match_action')->nullable();
                $table->string('matched_id')->nullable();
                $table->text('relationships')->nullable();
            });

            // Enforce raw_data is never empty (catches silent insert failures)
            $this->connection()->statement('
                CREATE TRIGGER validate_raw_data_insert
                BEFORE INSERT ON import_rows
                BEGIN
                    SELECT CASE
                        WHEN NEW.raw_data IS NULL OR NEW.raw_data = \'\' OR NEW.raw_data = \'{}\'
                        THEN RAISE(ABORT, \'raw_data cannot be null or empty\')
                    END;
                END
            ');

            // Add indexes for common queries
            $schema->table('import_rows', function ($table): void {
                $table->index('validation');
                $table->index('match_action');
                $table->index('skipped');
                $table->index('value_hash');
            });
        } catch (QueryException $e) {
            // Handle race condition (Sushi pattern)
            if (Str::contains($e->getMessage(), ['already exists', 'table "import_rows" already exists'])) {
                return;
            }

            throw $e;
        }
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * Re-validate all values for a specific column.
     * Called when format setting changes (e.g., date/number format).
     *
     * CRITICAL: This method ONLY updates the `validation` column.
     * It NEVER modifies `corrections` or `raw_data`.
     *
     * For date fields:
     * - Raw values are validated against the newly selected format
     * - Corrections (stored in ISO from the date picker) are validated
     *   using logic that accepts both ISO and the selected format
     *
     * Uses temporary table approach for optimal performance.
     */
    public function revalidateColumn(ColumnData $column): void
    {
        $jsonPath = '$.'.$column->source;

        // Step 1: Create temporary table
        $this->connection()->statement('
            CREATE TEMPORARY TABLE temp_revalidation (
                row_number INTEGER PRIMARY KEY,
                new_validation_error TEXT NULL
            )
        ');

        // Step 2-4: Stream rows, compute validation, batch insert
        $batchSize = 500;
        $batch = [];

        $this->query()->cursor()->each(function (ImportRow $row) use ($column, &$batch, $batchSize): void {
            $skipped = $row->skipped ?? collect();

            // If value is skipped, validation should be null (no error)
            if ($skipped->has($column->source)) {
                $batch[] = [
                    'row_number' => $row->row_number,
                    'new_validation_error' => null,
                ];
            } else {
                $hasCorrection = $row->corrections?->has($column->source) ?? false;

                // Date corrections are validated against ISO (not the selected format),
                // so they remain valid regardless of format changes - skip revalidation
                if ($hasCorrection && $column->getType()->isDateOrDateTime()) {
                    $batch[] = ['row_number' => $row->row_number, 'new_validation_error' => null];
                } else {
                    $valueToValidate = $hasCorrection
                        ? $row->corrections->get($column->source)
                        : $row->raw_data->get($column->source);

                    $error = $this->validator()->validate($column, $valueToValidate);
                    $batch[] = ['row_number' => $row->row_number, 'new_validation_error' => $error];
                }
            }

            // Batch insert when we hit batch size
            if (count($batch) >= $batchSize) {
                $this->connection()->table('temp_revalidation')->insert($batch);
                $batch = [];
            }
        });

        // Insert remaining rows
        if (! empty($batch)) {
            $this->connection()->table('temp_revalidation')->insert($batch);
        }

        // Step 5: Single UPDATE with JOIN (SQLite uses UPDATE...FROM syntax)
        $this->connection()->statement("
            UPDATE import_rows
            SET validation = CASE
                WHEN temp.new_validation_error IS NULL
                    THEN json_remove(validation, ?)
                ELSE
                    json_set(COALESCE(validation, '{}'), ?, temp.new_validation_error)
            END
            FROM temp_revalidation AS temp
            WHERE import_rows.row_number = temp.row_number
        ", [$jsonPath, $jsonPath]);

        // Step 6: Cleanup
        $this->connection()->statement('DROP TABLE IF EXISTS temp_revalidation');
    }

    /**
     * Set a correction for a raw value and validate it.
     * Updates all rows with matching raw value using batch SQL UPDATE.
     *
     * IMPORTANT - Date Correction Storage:
     * For date fields, corrections from the HTML date picker are stored in ISO format
     * (YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS) regardless of the user's selected date format.
     *
     * This is intentional because:
     * - HTML date/datetime inputs always output ISO format (browser constraint)
     * - Laravel/Eloquent expects ISO format for database storage
     * - The selected format only affects how ambiguous raw CSV data is interpreted
     *
     * Date corrections are validated against ISO format directly (not the selected format),
     * since date pickers always output ISO. When the user changes the date format setting,
     * existing corrections remain valid because they're stored and validated as ISO.
     */
    public function setCorrection(string $columnSource, string $rawValue, string $newValue): void
    {
        $column = $this->getColumnMapping($columnSource);

        if (! $column instanceof ColumnData) {
            return;
        }

        // Date corrections: validate against ISO (date picker output)
        // Other fields: validate against column format
        if ($column->getType()->isDateOrDateTime()) {
            $withTime = $column->getType()->isTimestamp();
            $parsed = DateFormat::ISO->parse($newValue, $withTime);
            $error = $parsed === null ? 'Invalid date format' : null;
        } else {
            $error = $this->validator()->validate($column, $newValue);
        }

        $jsonPath = '$.'.$columnSource;

        // Batch update corrections
        $this->connection()->statement("
            UPDATE import_rows
            SET corrections = json_set(COALESCE(corrections, '{}'), ?, ?)
            WHERE json_extract(raw_data, ?) = ?
        ", [$jsonPath, $newValue, $jsonPath, $rawValue]);

        // Batch update validation
        if ($error !== null) {
            $this->connection()->statement("
                UPDATE import_rows
                SET validation = json_set(COALESCE(validation, '{}'), ?, ?)
                WHERE json_extract(raw_data, ?) = ?
            ", [$jsonPath, $error, $jsonPath, $rawValue]);
        } else {
            $this->connection()->statement('
                UPDATE import_rows
                SET validation = json_remove(validation, ?)
                WHERE json_extract(raw_data, ?) = ?
            ', [$jsonPath, $jsonPath, $rawValue]);
        }
    }

    /**
     * Clear a correction (undo) and re-validate the original value.
     * Uses batch SQL UPDATE for performance.
     */
    public function clearCorrection(string $columnSource, string $rawValue): void
    {
        $column = $this->getColumnMapping($columnSource);

        if (! $column instanceof ColumnData) {
            return;
        }

        $error = $this->validator()->validate($column, $rawValue);
        $jsonPath = '$.'.$columnSource;

        // Batch remove correction
        $this->connection()->statement('
            UPDATE import_rows
            SET corrections = json_remove(corrections, ?)
            WHERE json_extract(raw_data, ?) = ?
        ', [$jsonPath, $jsonPath, $rawValue]);

        // Batch update validation
        if ($error !== null) {
            $this->connection()->statement("
                UPDATE import_rows
                SET validation = json_set(COALESCE(validation, '{}'), ?, ?)
                WHERE json_extract(raw_data, ?) = ?
            ", [$jsonPath, $error, $jsonPath, $rawValue]);
        } else {
            $this->connection()->statement('
                UPDATE import_rows
                SET validation = json_remove(validation, ?)
                WHERE json_extract(raw_data, ?) = ?
            ', [$jsonPath, $jsonPath, $rawValue]);
        }
    }

    /**
     * Mark a value as skipped (will become null during import).
     * Clears validation error since it's intentionally skipped.
     * Uses batch SQL UPDATE for performance.
     */
    public function setValueSkipped(string $columnSource, string $rawValue): void
    {
        $jsonPath = '$.'.$columnSource;

        // Batch set skipped flag and remove validation error in one query
        $this->connection()->statement("
            UPDATE import_rows
            SET skipped = json_set(COALESCE(skipped, '{}'), ?, json('true')),
                validation = json_remove(validation, ?)
            WHERE json_extract(raw_data, ?) = ?
        ", [$jsonPath, $jsonPath, $jsonPath, $rawValue]);
    }

    /**
     * Clear a skipped value (unskip) and re-validate the original value.
     * Uses batch SQL UPDATE for performance.
     */
    public function clearSkipped(string $columnSource, string $rawValue): void
    {
        $column = $this->getColumnMapping($columnSource);

        if (! $column instanceof ColumnData) {
            return;
        }

        $error = $this->validator()->validate($column, $rawValue);
        $jsonPath = '$.'.$columnSource;

        // Batch remove skipped flag
        $this->connection()->statement('
            UPDATE import_rows
            SET skipped = json_remove(skipped, ?)
            WHERE json_extract(raw_data, ?) = ?
        ', [$jsonPath, $jsonPath, $rawValue]);

        // Batch update validation if the raw value has an error
        if ($error !== null) {
            $this->connection()->statement("
                UPDATE import_rows
                SET validation = json_set(COALESCE(validation, '{}'), ?, ?)
                WHERE json_extract(raw_data, ?) = ?
            ", [$jsonPath, $error, $jsonPath, $rawValue]);
        }
    }

    /**
     * Dispatch a single background job to validate all unique values for a column.
     * Returns the batch ID for progress tracking.
     */
    public function validateColumnAsync(ColumnData $column): string
    {
        $batch = Bus::batch([
            new ValidateColumnJob($this->id, $column),
        ])
            ->name("Validate {$column->source}")
            ->dispatch();

        $this->updateMeta(['validation_batch_id' => $batch->id]);

        return $batch->id;
    }

    // =========================================================================
    // LIFECYCLE
    // =========================================================================

    /**
     * Delete this import session and all its files.
     */
    public function destroy(): void
    {
        $this->connection = null;
        File::deleteDirectory($this->path());
    }

    /**
     * Count unique values for each filter type in a single query.
     *
     * @return array<string, int>
     */
    public function countUniqueValuesByFilter(string $column): array
    {
        return ImportRow::countUniqueValuesByFilter($this->query(), $column);
    }
}
