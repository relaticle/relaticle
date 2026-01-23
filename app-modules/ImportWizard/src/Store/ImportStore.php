<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Store;

use App\Models\CustomField;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\NumberFormat;
use Relaticle\ImportWizard\Importers\BaseImporter;

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
     * Get choice options for a column mapping.
     *
     * Loads options from CustomField for custom choice fields.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getChoiceOptions(ColumnData $column): array
    {
        if (! $column->getType()->isChoiceField()) {
            return [];
        }

        $customField = CustomField::query()
            ->forEntity($this->entityType()->value)
            ->where('code', Str::after($column->target, 'custom_fields_'))
            ->first();

        if (! $customField instanceof CustomField) {
            return [];
        }

        return $customField
            ->options()
            ->pluck('name')
            ->map(fn ($option): array => ['label' => $option, 'value' => $option])
            ->toArray();
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
     * Validate all values for all mapped columns.
     * Called once when entering Review Step.
     */
    public function validateAllColumns(): void
    {
        $columns = $this->columnMappings();

        $this->query()->each(function (ImportRow $row) use ($columns): void {
            $validation = collect();

            foreach ($columns as $column) {
                if (! $column->isFieldMapping()) {
                    continue;
                }

                $valueToValidate = $row->corrections?->get($column->source) ?? $row->raw_data->get($column->source);

                $error = $this->validateValue($column, $valueToValidate);

                if ($error !== null) {
                    $validation->put($column->source, $error);
                }
            }

            $row->update(['validation' => $validation->isEmpty() ? null : $validation]);
        });
    }

    /**
     * Re-validate all values for a specific column.
     * Called when format setting changes.
     */
    public function revalidateColumn(ColumnData $column): void
    {
        $this->query()->each(function (ImportRow $row) use ($column): void {
            $skipped = $row->skipped ?? collect();

            if ($skipped->has($column->source)) {
                $validation = $row->validation ?? collect();
                $validation->forget($column->source);
                $row->update(['validation' => $validation->isEmpty() ? null : $validation]);

                return;
            }

            $valueToValidate = $row->corrections?->get($column->source) ?? $row->raw_data->get($column->source);

            $error = $this->validateValue($column, $valueToValidate);

            $validation = $row->validation ?? collect();

            if ($error !== null) {
                $validation->put($column->source, $error);
            } else {
                $validation->forget($column->source);
            }

            $row->update(['validation' => $validation->isEmpty() ? null : $validation]);
        });
    }

    /**
     * Set a correction for a raw value and validate it.
     * Updates all rows with matching raw value.
     */
    public function setCorrection(string $columnSource, string $rawValue, string $newValue): void
    {
        $column = $this->getColumnMapping($columnSource);

        if (! $column instanceof ColumnData) {
            return;
        }

        $error = $this->validateValue($column, $newValue);

        $this->query()
            ->whereRaw('json_extract(raw_data, ?) = ?', ['$.'.$columnSource, $rawValue])
            ->each(function (ImportRow $row) use ($columnSource, $newValue, $error): void {
                $corrections = $row->corrections ?? collect();
                $validation = $row->validation ?? collect();

                $corrections->put($columnSource, $newValue);

                if ($error !== null) {
                    $validation->put($columnSource, $error);
                } else {
                    $validation->forget($columnSource);
                }

                $row->update([
                    'corrections' => $corrections,
                    'validation' => $validation->isEmpty() ? null : $validation,
                ]);
            });
    }

    /**
     * Clear a correction (undo) and re-validate the original value.
     */
    public function clearCorrection(string $columnSource, string $rawValue): void
    {
        $column = $this->getColumnMapping($columnSource);

        if (! $column instanceof ColumnData) {
            return;
        }

        $this->query()
            ->whereRaw('json_extract(raw_data, ?) = ?', ['$.'.$columnSource, $rawValue])
            ->each(function (ImportRow $row) use ($column, $columnSource, $rawValue): void {
                $corrections = $row->corrections ?? collect();
                $validation = $row->validation ?? collect();

                $corrections->forget($columnSource);

                // Re-validate the original raw value
                $error = $this->validateValue($column, $rawValue);

                if ($error !== null) {
                    $validation->put($columnSource, $error);
                } else {
                    $validation->forget($columnSource);
                }

                $row->update([
                    'corrections' => $corrections->isEmpty() ? null : $corrections,
                    'validation' => $validation->isEmpty() ? null : $validation,
                ]);
            });
    }

    /**
     * Mark a value as skipped (will become null during import).
     * Clears validation error since it's intentionally skipped.
     */
    public function setValueSkipped(string $columnSource, string $rawValue): void
    {
        $this->query()
            ->whereRaw('json_extract(raw_data, ?) = ?', ['$.'.$columnSource, $rawValue])
            ->each(function (ImportRow $row) use ($columnSource): void {
                $skipped = $row->skipped ?? collect();
                $validation = $row->validation ?? collect();

                $skipped->put($columnSource, true);
                $validation->forget($columnSource);

                $row->update([
                    'skipped' => $skipped,
                    'validation' => $validation->isEmpty() ? null : $validation,
                ]);
            });
    }

    /**
     * Clear a skipped value (unskip) and re-validate the original value.
     */
    public function clearSkipped(string $columnSource, string $rawValue): void
    {
        $column = $this->getColumnMapping($columnSource);

        if (! $column instanceof ColumnData) {
            return;
        }

        $this->query()
            ->whereRaw('json_extract(raw_data, ?) = ?', ['$.'.$columnSource, $rawValue])
            ->each(function (ImportRow $row) use ($column, $columnSource, $rawValue): void {
                $skipped = $row->skipped ?? collect();
                $validation = $row->validation ?? collect();

                $skipped->forget($columnSource);

                $error = $this->validateValue($column, $rawValue);

                if ($error !== null) {
                    $validation->put($columnSource, $error);
                }

                $row->update([
                    'skipped' => $skipped->isEmpty() ? null : $skipped,
                    'validation' => $validation->isEmpty() ? null : $validation,
                ]);
            });
    }

    /**
     * Validate a single value against the column's type and format settings.
     */
    private function validateValue(ColumnData $column, mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (! is_string($value)) {
            $value = (string) $value;
        }

        $type = $column->getType();

        return match (true) {
            $type->isDateOrDateTime() => $this->validateDate($column, $value),
            $type->isFloat() => $this->validateFloat($column, $value),
            $type->isChoiceField() => $this->validateChoice($column, $value),
            default => null,
        };
    }

    private function validateDate(ColumnData $column, string $value): ?string
    {
        $format = $column->dateFormat ?? DateFormat::ISO;
        $isTimestamp = $column->getType()->isTimestamp();

        return $format->parse($value, $isTimestamp) === null
            ? __('import-wizard-new::validation.invalid_date', ['format' => $format->getLabel()])
            : null;
    }

    private function validateFloat(ColumnData $column, string $value): ?string
    {
        $format = $column->numberFormat ?? NumberFormat::POINT;

        return $format->parse($value) === null
            ? __('import-wizard-new::validation.invalid_number', ['format' => $format->getLabel()])
            : null;
    }

    private function validateChoice(ColumnData $column, string $value): ?string
    {
        $options = $this->getChoiceOptions($column);
        $validValues = collect($options)->pluck('value')->all();

        return in_array($value, $validValues, true)
            ? null
            : __('import-wizard-new::validation.invalid_choice', ['value' => $value]);
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
