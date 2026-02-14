<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Store;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Enums\RowMatchAction;

final class ImportStore
{
    private ?Connection $connection = null;

    public function __construct(
        private readonly string $id,
    ) {}

    public static function create(string $importId): self
    {
        $store = new self($importId);

        File::ensureDirectoryExists($store->path());
        file_put_contents($store->sqlitePath(), '');
        $store->createTableSafely();

        return $store;
    }

    public static function load(string $importId): ?self
    {
        if (! Str::isUlid($importId)) {
            return null;
        }

        $store = new self($importId);

        if (! File::exists($store->sqlitePath())) {
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

    public function sqlitePath(): string
    {
        return $this->path().'/data.sqlite';
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

    public function ensureProcessedColumn(): void
    {
        $schema = $this->connection()->getSchemaBuilder();

        if ($schema->hasColumn('import_rows', 'processed')) {
            return;
        }

        $schema->table('import_rows', function (Blueprint $table): void {
            $table->boolean('processed')->default(false);
        });
    }

    /**
     * @param  array<string, int|string|null>  $resolvedMap
     */
    public function bulkUpdateMatches(string $jsonPath, array $resolvedMap, RowMatchAction $unmatchedAction): void
    {
        $connection = $this->connection();

        $connection->statement('
            CREATE TEMPORARY TABLE IF NOT EXISTS temp_match_results (
                lookup_value TEXT,
                match_action TEXT,
                matched_id TEXT
            )
        ');

        try {
            $inserts = collect($resolvedMap)
                ->map(fn (int|string|null $id, int|string $value): array => [
                    'lookup_value' => $value,
                    'match_action' => $id !== null ? RowMatchAction::Update->value : $unmatchedAction->value,
                    'matched_id' => $id !== null ? (string) $id : null,
                ])
                ->values()
                ->all();

            if ($inserts === []) {
                return;
            }

            foreach (array_chunk($inserts, 5000) as $chunk) {
                $connection->table('temp_match_results')->insert($chunk);
            }

            $connection->statement('
                UPDATE import_rows
                SET match_action = temp.match_action,
                    matched_id = temp.matched_id
                FROM temp_match_results AS temp
                WHERE json_extract(import_rows.raw_data, ?) = temp.lookup_value
                  AND import_rows.match_action IS NULL
            ', [$jsonPath]);
        } finally {
            $connection->statement('DROP TABLE IF EXISTS temp_match_results');
        }
    }

    public function destroy(): void
    {
        $this->connection = null;
        File::deleteDirectory($this->path());
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
            $table->boolean('processed')->default(false);
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
}
