<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Store;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Importers\BaseImporter;

final class ImportStore
{
    private ?Connection $connection = null;

    /** @var array<string, mixed>|null */
    private ?array $metaCache = null;

    private ?BaseImporter $importerCache = null;

    public function __construct(
        private readonly string $id,
    ) {}

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

    /** @throws FileNotFoundException */
    public static function load(string $id, string $expectedTeamId): ?self
    {
        $store = new self($id);

        if (! File::exists($store->metaPath())) {
            return null;
        }

        if ($store->teamId() !== $expectedTeamId) {
            return null;
        }

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

    public function refreshMeta(): void
    {
        $this->metaCache = null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function writeMeta(array $data): void
    {
        $data['updated_at'] = now()->toIso8601String();
        $this->metaCache = $data;

        File::put(
            $this->metaPath(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
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

    /**
     * @param  array<string, int>  $results
     */
    public function setResults(array $results): void
    {
        $this->updateMeta(['results' => $results]);
    }

    /**
     * @return array<string, int>|null
     */
    public function results(): ?array
    {
        return $this->meta()['results'] ?? null;
    }

    /**
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
        $headerOrder = array_flip($this->headers());

        return ColumnData::collect($raw, Collection::class)
            ->each(function (ColumnData $col) use ($fields, $entityLinks): void {
                if ($col->isFieldMapping()) {
                    $col->importField = $fields->get($col->target);
                } else {
                    $col->entityLinkField = $entityLinks->get($col->entityLink);
                }
            })
            ->sortBy(fn (ColumnData $col): int => $headerOrder[$col->source] ?? PHP_INT_MAX)
            ->values();
    }

    public function getImporter(): BaseImporter
    {
        return $this->importerCache ??= $this->entityType()->importer($this->teamId());
    }

    /** @param  iterable<int, ColumnData>  $mappings */
    public function setColumnMappings(iterable $mappings): void
    {
        $raw = collect($mappings)
            ->map(fn (ColumnData $m): array => $m->toArray())
            ->values()
            ->all();

        $this->updateMeta(['column_mappings' => $raw]);
    }

    public function getColumnMapping(string $source): ?ColumnData
    {
        return $this->columnMappings()->firstWhere('source', $source);
    }

    public function updateColumnMapping(string $source, ColumnData $newMapping): void
    {
        $mappings = $this->columnMappings()
            ->map(fn (ColumnData $m): ColumnData => $m->source === $source ? $newMapping : $m);

        $this->setColumnMappings($mappings);
    }

    public function connectionName(): string
    {
        return "import_{$this->id}";
    }

    public function connection(): Connection
    {
        return $this->connection ??= $this->createConnection();
    }

    /** @return EloquentBuilder<ImportRow> */
    public function query(): EloquentBuilder
    {
        $this->connection();

        return ImportRow::on($this->connectionName());
    }

    private function createConnection(): Connection
    {
        $name = $this->connectionName();
        $config = [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath(),
            'foreign_key_constraints' => true,
        ];

        resolve(Repository::class)->set("database.connections.{$name}", $config);

        return resolve(ConnectionFactory::class)->make($config, $name);
    }

    private function createDatabase(): void
    {
        file_put_contents($this->sqlitePath(), '');

        $this->createTableSafely();
    }

    private function createTableSafely(): void
    {
        $schema = $this->connection()->getSchemaBuilder();

        if ($schema->hasTable('import_rows')) {
            return;
        }

        $schema->create('import_rows', function (Blueprint $table): void {
            $table->integer('row_number')->primary();
            $table->text('raw_data');
            $table->text('validation')->nullable();
            $table->text('corrections')->nullable();
            $table->text('skipped')->nullable();
            $table->string('match_action')->nullable();
            $table->string('matched_id')->nullable();
            $table->text('relationships')->nullable();
        });

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

        $schema->table('import_rows', function (Blueprint $table): void {
            $table->index('validation');
            $table->index('match_action');
            $table->index('skipped');
        });
    }

    public function destroy(): void
    {
        $this->connection = null;
        File::deleteDirectory($this->path());
    }
}
