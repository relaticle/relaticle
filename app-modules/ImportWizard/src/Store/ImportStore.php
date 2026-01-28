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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
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
     * Get all column mappings as a collection with hydrated ImportField/EntityLink.
     *
     * Uses Spatie Data's collect() for automatic deserialization,
     * then hydrates each ColumnData with its ImportField or EntityLink for direct access.
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
        $entityLinks = collect($importer->entityLinks());

        return ColumnData::collect($raw, Collection::class)
            ->each(function (ColumnData $col) use ($fields, $entityLinks): void {
                if ($col->isFieldMapping()) {
                    $col->importField = $fields->get($col->target);
                } else {
                    $col->entityLinkField = $entityLinks->get($col->entityLink);
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
}
