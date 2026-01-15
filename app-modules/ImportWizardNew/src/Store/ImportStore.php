<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Store;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Relaticle\ImportWizardNew\Enums\ImportEntityType;
use Relaticle\ImportWizardNew\Enums\ImportStatus;

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

    /**
     * @return array<string, string>
     */
    public function columnMappings(): array
    {
        return $this->meta()['column_mappings'] ?? [];
    }

    /**
     * @param  array<string, string>  $mappings
     */
    public function setColumnMappings(array $mappings): void
    {
        $this->updateMeta(['column_mappings' => $mappings]);
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
        if ($this->connection === null) {
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
        app('config')->set("database.connections.{$name}", $config);

        return app(ConnectionFactory::class)->make($config, $name);
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
                $table->text('data');
                $table->text('validation')->nullable();
                $table->text('corrections')->nullable();
            });

            // Add index for validation queries
            $schema->table('import_rows', function ($table): void {
                $table->index('validation');
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

    /**
     * Check if this import belongs to a team.
     */
    public function belongsToTeam(string $teamId): bool
    {
        return $this->teamId() === $teamId;
    }
}
