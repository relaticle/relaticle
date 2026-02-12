# Import History Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the filesystem-based ImportStore metadata with a database-backed Import model, then build a Filament history page for viewing past imports and downloading failed rows.

**Architecture:** The `imports` table becomes the source of truth for all import metadata (status, headers, column mappings, results). ImportStore is slimmed down to only manage the temporary SQLite database for row data. A new Filament page lists all imports with detail views and CSV download for failed rows.

**Tech Stack:** Laravel 12, PHP 8.4, Filament 5, Livewire 4, PostgreSQL, Pest

---

### Task 1: Migration — Expand `imports` table

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_expand_imports_table.php`

**Step 1: Create the migration**

```bash
php artisan make:migration expand_imports_table --table=imports
```

**Step 2: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->string('entity_type')->nullable()->after('user_id');
            $table->string('status')->default('uploading')->after('entity_type');
            $table->json('headers')->nullable()->after('status');
            $table->json('column_mappings')->nullable()->after('headers');
            $table->json('results')->nullable()->after('column_mappings');
            $table->json('failed_rows_data')->nullable()->after('results');
            $table->unsignedInteger('created_rows')->default(0)->after('successful_rows');
            $table->unsignedInteger('updated_rows')->default(0)->after('created_rows');
            $table->unsignedInteger('skipped_rows')->default(0)->after('updated_rows');
            $table->string('file_path')->nullable()->change();
            $table->string('importer')->nullable()->change();
        });
    }
};
```

**Step 3: Run migration**

```bash
php artisan migrate
```

Expected: Migration runs successfully.

**Step 4: Commit**

```bash
git add database/migrations/*expand_imports*
git commit -m "feat: expand imports table with metadata columns"
```

---

### Task 2: Import model

**Files:**
- Create: `app-modules/ImportWizard/src/Models/Import.php`
- Create: `app-modules/ImportWizard/src/Models/FailedImportRow.php`

**Step 1: Create Import model**

Create `app-modules/ImportWizard/src/Models/Import.php`:

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Models;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Importers\BaseImporter;

final class Import extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'entity_type' => ImportEntityType::class,
            'status' => ImportStatus::class,
            'headers' => 'array',
            'column_mappings' => 'array',
            'results' => 'array',
            'failed_rows_data' => 'array',
            'completed_at' => 'datetime',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'successful_rows' => 'integer',
            'created_rows' => 'integer',
            'updated_rows' => 'integer',
            'skipped_rows' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<FailedImportRow, $this> */
    public function failedRows(): HasMany
    {
        return $this->hasMany(FailedImportRow::class);
    }

    /** @param Builder<Import> $query */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', ImportStatus::Completed);
    }

    /** @param Builder<Import> $query */
    public function scopeFailed(Builder $query): void
    {
        $query->where('status', ImportStatus::Failed);
    }

    /** @param Builder<Import> $query */
    public function scopeForTeam(Builder $query, string $teamId): void
    {
        $query->where('team_id', $teamId);
    }

    public function storagePath(): string
    {
        return storage_path("app/imports/{$this->id}");
    }

    private ?BaseImporter $importerCache = null;

    public function getImporter(): BaseImporter
    {
        return $this->importerCache ??= $this->entity_type->importer($this->team_id);
    }

    /**
     * @return Collection<int, ColumnData>
     */
    public function columnMappings(): Collection
    {
        $raw = $this->column_mappings ?? [];
        $importer = $this->getImporter();
        $fields = $importer->allFields();
        $entityLinks = collect($importer->entityLinks());
        $headerOrder = array_flip($this->headers ?? []);

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

    /** @param iterable<int, ColumnData> $mappings */
    public function setColumnMappings(iterable $mappings): void
    {
        $raw = collect($mappings)
            ->map(fn (ColumnData $m): array => $m->toArray())
            ->values()
            ->all();

        $this->update(['column_mappings' => $raw]);
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

    public function transitionToImporting(): bool
    {
        $lock = Cache::lock("import-{$this->id}-start", 10);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->refresh();

            if (in_array($this->status, [ImportStatus::Importing, ImportStatus::Completed, ImportStatus::Failed], true)) {
                return false;
            }

            $this->update(['status' => ImportStatus::Importing]);

            return true;
        } finally {
            $lock->release();
        }
    }
}
```

**Step 2: Create FailedImportRow model**

Create `app-modules/ImportWizard/src/Models/FailedImportRow.php`:

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FailedImportRow extends Model
{
    use HasUlids;
    use MassPrunable;

    protected $guarded = [];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    /** @return BelongsTo<Import, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<static> */
    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('created_at', '<=', now()->subMonth());
    }
}
```

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Models/
git commit -m "feat: add Import and FailedImportRow models"
```

---

### Task 3: Slim down ImportStore to SQLite-only

**Files:**
- Modify: `app-modules/ImportWizard/src/Store/ImportStore.php`

**Step 1: Rewrite ImportStore**

Replace ImportStore with a SQLite-only wrapper. Remove all metadata methods (`meta()`, `writeMeta()`, `updateMeta()`, `refreshMeta()`, `status()`, `setStatus()`, `entityType()`, `teamId()`, `userId()`, `headers()`, `setHeaders()`, `rowCount()`, `setRowCount()`, `results()`, `setResults()`, `failedRows()`, `columnMappings()`, `setColumnMappings()`, `getColumnMapping()`, `updateColumnMapping()`, `getImporter()`, `transitionToImporting()`).

Keep only:
- `create(string $importId)` — creates SQLite file at `storage/app/imports/{importId}/data.sqlite`
- `load(string $importId)` — opens existing SQLite connection
- `connection()` — returns SQLite Connection
- `query()` — returns ImportRow query builder
- `ensureProcessedColumn()` — schema migration helper
- `destroy()` — deletes the directory
- `id()` — returns the import ID
- `path()` / `sqlitePath()` — path helpers

The constructor takes just `string $id`. No team ID validation (that's now done via the Import model). The `create()` static method no longer writes meta.json — it only creates the SQLite database file and schema.

```php
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
```

**Step 2: Run existing tests to see what breaks**

```bash
php artisan test tests/Feature/ImportWizard/ --stop-on-failure 2>&1 | head -50
```

Expected: Many failures — this is the starting point for the remaining tasks.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Store/ImportStore.php
git commit -m "refactor: slim ImportStore to SQLite-only wrapper"
```

---

### Task 4: Refactor WithImportStore trait

**Files:**
- Modify: `app-modules/ImportWizard/src/Livewire/Concerns/WithImportStore.php`

**Step 1: Rewrite the trait**

The trait now provides both `$this->import` (Import model) and `$this->store` (ImportStore for SQLite). It loads both from the same ULID. Rename or keep the trait name — keeping `WithImportStore` is fine since it still provides store access.

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;

trait WithImportStore
{
    #[Locked]
    public string $storeId;

    #[Locked]
    public ImportEntityType $entityType;

    private ?Import $import = null;

    private ?ImportStore $store = null;

    public function mountWithImportStore(string $storeId, ImportEntityType $entityType): void
    {
        $this->storeId = $storeId;
        $this->entityType = $entityType;
    }

    protected function import(): Import
    {
        if ($this->import === null) {
            $this->import = Import::query()
                ->forTeam($this->getCurrentTeamId() ?? '')
                ->findOrFail($this->storeId);
        }

        return $this->import;
    }

    protected function store(): ImportStore
    {
        $store = $this->store ??= ImportStore::load($this->storeId);

        if ($store === null) {
            abort(404, 'Import session not found or expired.');
        }

        return $store;
    }

    private function getCurrentTeamId(): ?string
    {
        $tenant = filament()->getTenant();

        return $tenant instanceof Model ? (string) $tenant->getKey() : null;
    }

    /** @return list<string> */
    protected function headers(): array
    {
        return $this->import()->headers ?? [];
    }

    protected function rowCount(): int
    {
        return $this->import()->total_rows;
    }
}
```

**Step 2: Commit**

```bash
git add app-modules/ImportWizard/src/Livewire/Concerns/WithImportStore.php
git commit -m "refactor: update WithImportStore to use Import model"
```

---

### Task 5: Refactor UploadStep

**Files:**
- Modify: `app-modules/ImportWizard/src/Livewire/Steps/UploadStep.php`

**Step 1: Update UploadStep to create Import record + ImportStore**

Key changes:
- `mount()`: Load Import model instead of ImportStore for restoring state
- `continueToMapping()`: Create `Import::create()` in DB first, then `ImportStore::create($import->id)` for SQLite. Stream CSV to SQLite via store.
- `removeFile()` / error paths: Destroy both Import record and ImportStore
- Remove `private ?ImportStore $store` — use local variable in `continueToMapping()` only

Replace `$this->store = ImportStore::create(...)` pattern with:

```php
$import = Import::create([
    'team_id' => $teamId,
    'user_id' => (string) auth()->id(),
    'entity_type' => $this->entityType,
    'file_name' => $this->uploadedFile->getClientOriginalName(),
    'status' => ImportStatus::Uploading,
    'total_rows' => 0,
    'headers' => $this->headers,
]);

$store = ImportStore::create($import->id);
```

Then after streaming rows:

```php
$import->update([
    'total_rows' => $rowCount,
    'status' => ImportStatus::Mapping,
]);
```

Dispatch event with `$import->id` as the store ID (it's the same ULID).

On error, delete both: `$import->delete()` and `$store->destroy()`.

**Step 2: Run UploadStep tests**

```bash
php artisan test tests/Feature/ImportWizard/Livewire/UploadStepTest.php --stop-on-failure
```

Fix any failures.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Livewire/Steps/UploadStep.php
git commit -m "refactor: update UploadStep to create Import record"
```

---

### Task 6: Refactor ImportWizard main component

**Files:**
- Modify: `app-modules/ImportWizard/src/Livewire/ImportWizard.php`

**Step 1: Replace ImportStore references with Import model**

Key changes:
- `restoreFromStore()`: Load `Import` model via `Import::where('id', $this->storeId)->forTeam($teamId)->first()` instead of `ImportStore::load()`
- Read `$import->status`, `$import->total_rows`, `count($import->headers)` instead of store meta
- `cancelImport()`: Delete the Import record (`$import->delete()`) and destroy the ImportStore
- `startOver()`: Same — delete Import + destroy store
- `syncStepStatus()`: Update `$import->update(['status' => ...])` instead of `$store->setStatus()`
- Remove `use Relaticle\ImportWizard\Store\ImportStore;`

**Step 2: Run wizard tests**

```bash
php artisan test tests/Feature/ImportWizard/Livewire/ImportWizardTest.php --stop-on-failure
```

Fix any failures.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Livewire/ImportWizard.php
git commit -m "refactor: update ImportWizard to use Import model"
```

---

### Task 7: Refactor MappingStep

**Files:**
- Modify: `app-modules/ImportWizard/src/Livewire/Steps/MappingStep.php`

**Step 1: Replace store metadata calls with Import model**

Key changes:
- `loadMappings()`: Use `$this->import()->columnMappings()` instead of `$this->store()->columnMappings()`
- `saveMappings()`: Use `$this->import()->setColumnMappings($mappings)` instead of `$store->setColumnMappings()`
- `previewValues()`: Still uses `$this->store()->query()` for SQLite data — no change
- `continueAction()`: Use `$this->import()->update(['status' => ImportStatus::Reviewing])` instead of `$this->store()?->setStatus()`
- `getImporter()`: Use `$this->import()->getImporter()` instead of `$this->entityType->importer($this->store()?->teamId())`
- `inferDataTypes()`: Use `$this->import()->team_id` instead of `$this->store()?->teamId()`

**Step 2: Run mapping tests**

```bash
php artisan test tests/Feature/ImportWizard/Livewire/MappingStepTest.php --stop-on-failure
```

Fix any failures.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Livewire/Steps/MappingStep.php
git commit -m "refactor: update MappingStep to use Import model"
```

---

### Task 8: Refactor ReviewStep

**Files:**
- Modify: `app-modules/ImportWizard/src/Livewire/Steps/ReviewStep.php`

**Step 1: Replace store metadata calls with Import model**

Key changes:
- `mount()`: Use `$this->import()->columnMappings()` for columns. Use `$this->import()->column_mappings` for hash calculation.
- `hydrate()`: Use `$this->import()->columnMappings()` and `$this->import()->getColumnMapping()`
- `validateEntityLinkValue()`: Use `$this->import()->team_id` and `$this->import()->getImporter()`
- `setColumnFormat()`: Use `$this->import()->updateColumnMapping()` instead of `$this->store()->updateColumnMapping()`
- `continueToPreview()`: Use `$this->import()->update(['status' => ImportStatus::Previewing])` instead of `$this->store()->setStatus()`
- `currentMappingsHash()`: Use `$this->import()->column_mappings ?? []` instead of `$this->store()->meta()['column_mappings']`
- `hasMappingsChanged()`: Read `$this->import()->mappings_hash` — NOTE: we need to add a `mappings_hash` column to the migration, OR store it in the `column_mappings` JSON, OR compute from the `column_mappings` JSON column directly.

**Decision on mappings_hash:** Rather than adding another column, compute the hash from `$this->import()->column_mappings`. The hash is already derived from column_mappings, so just compute it when needed:

```php
private function currentMappingsHash(): string
{
    return hash('xxh128', (string) json_encode($this->import()->column_mappings ?? []));
}
```

Store the hash comparison value as a simple property on mount. When mappings change (via `setColumnFormat`), update `column_mappings` on the Import model (already done by `updateColumnMapping`).

- `validateColumnAsync()`: Pass `$this->import()->id` and `$this->import()->team_id` to ValidateColumnJob
- `dispatchMatchResolution()`: Pass `$this->import()->id` and `$this->import()->team_id`
- Save mappings_hash: Use `$this->import()->update(['mappings_hash' => ...])` — BUT we don't have this column. Instead, just recompute the hash each time. The hash was an optimization to avoid re-validating when mappings haven't changed. We can track this as a simple Livewire property `$previousMappingsHash` set on mount.

**Step 2: Run review tests**

```bash
php artisan test tests/Feature/ImportWizard/Livewire/ReviewStepTest.php --stop-on-failure
```

Fix any failures.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Livewire/Steps/ReviewStep.php
git commit -m "refactor: update ReviewStep to use Import model"
```

---

### Task 9: Refactor PreviewStep

**Files:**
- Modify: `app-modules/ImportWizard/src/Livewire/Steps/PreviewStep.php`

**Step 1: Replace store metadata calls with Import model**

Key changes:
- `isImporting`: Use `$this->import()->status === ImportStatus::Importing`
- `columns()`: Use `$this->import()->columnMappings()`
- `matchField()`: Use `$this->import()->getImporter()`
- `results()`: Use `$this->import()->results`
- `startImport()`: Use `$this->import()->transitionToImporting()` instead of `$store->transitionToImporting()`
- Dispatch `ExecuteImportJob` with `$this->import()->id` and `$this->import()->team_id`
- `checkImportProgress()`: Use `$this->import()->refresh()` instead of `$store->refreshMeta()`
- `syncCompletionState()`: Use `$this->import()->status`
- `downloadFailedRows()`: Use `$this->import()->headers` for CSV headers, `$this->import()->failed_rows_data` for error lookup (or `$this->import()->failedRows()` from the `failed_import_rows` table once we refactor ExecuteImportJob in Task 10)

**Step 2: Run preview tests**

```bash
php artisan test tests/Feature/ImportWizard/Livewire/PreviewStepTest.php --stop-on-failure
```

Fix any failures.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Livewire/Steps/PreviewStep.php
git commit -m "refactor: update PreviewStep to use Import model"
```

---

### Task 10: Refactor ExecuteImportJob

**Files:**
- Modify: `app-modules/ImportWizard/src/Jobs/ExecuteImportJob.php`

**Step 1: Replace ImportStore metadata with Import model**

Key changes:
- `handle()`: Load `Import::findOrFail($this->importId)` and `ImportStore::load($this->importId)` separately
- Use `$import->getImporter()`, `$import->columnMappings()`, `$import->results` for metadata
- Use `$store->query()`, `$store->ensureProcessedColumn()` for SQLite row data
- `persistResults()`: Update the Import model: `$import->update(['results' => $results])`
- On completion: `$import->update(['status' => ImportStatus::Completed, 'completed_at' => now(), 'created_rows' => $results['created'], 'updated_rows' => $results['updated'], 'skipped_rows' => $results['skipped'], 'successful_rows' => $results['created'] + $results['updated'], 'processed_rows' => array_sum($results)])`
- On failure: `$import->update(['status' => ImportStatus::Failed, ...])`
- `recordFailedRow()`: Write to `failed_import_rows` table via `$import->failedRows()->create([...])` in addition to the in-memory list. Store raw row data from the ImportStore SQLite.
- `notifyUser()`: Use `$import->user_id` and `$import->entity_type`
- Also persist `failed_rows_data` on the Import model for quick access: `$import->update(['failed_rows_data' => $this->failedRows])`

**Step 2: Run job tests**

```bash
php artisan test tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php --stop-on-failure
```

Fix any failures.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Jobs/ExecuteImportJob.php
git commit -m "refactor: update ExecuteImportJob to use Import model"
```

---

### Task 11: Refactor ValidateColumnJob and ResolveMatchesJob

**Files:**
- Modify: `app-modules/ImportWizard/src/Jobs/ValidateColumnJob.php`
- Modify: `app-modules/ImportWizard/src/Jobs/ResolveMatchesJob.php`
- Modify: `app-modules/ImportWizard/src/Support/MatchResolver.php`

**Step 1: Update ValidateColumnJob**

Key changes:
- Load `Import::findOrFail($this->importId)` and `ImportStore::load($this->importId)` separately
- Use `$import->team_id` instead of `$store->teamId()`
- Use `$import->getImporter()` instead of `$store->getImporter()`
- Use `$store->connection()` and `$store->query()` for SQLite operations (unchanged)
- `hydrateColumnField()`: Use `$import->getImporter()` instead of `$store->getImporter()`
- Constructor still takes `importId` and `teamId` (for queue serialization), but loads Import model in `handle()`

**Step 2: Update ResolveMatchesJob**

Key changes:
- Load `Import::findOrFail($this->importId)` and `ImportStore::load($this->importId)`
- Use `$import->getImporter()` instead of `$store->getImporter()`
- Pass `$store` (for SQLite) and `$import` (for metadata) to MatchResolver

**Step 3: Update MatchResolver**

Change constructor to accept both ImportStore and Import (or just the pieces it needs):
- `$store->columnMappings()` → `$import->columnMappings()`
- `$store->connection()` → `$store->connection()` (still SQLite)
- `$store->query()` → `$store->query()` (still SQLite)

```php
public function __construct(
    private ImportStore $store,
    private Import $import,
    private BaseImporter $importer,
) {}

public function resolve(): void
{
    $this->resetPreviousResolutions();
    $mappings = $this->import->columnMappings();
    // ... rest uses $this->store for SQLite operations
}
```

**Step 4: Run job tests**

```bash
php artisan test tests/Feature/ImportWizard/Jobs/ --stop-on-failure
```

Fix any failures.

**Step 5: Commit**

```bash
git add app-modules/ImportWizard/src/Jobs/ app-modules/ImportWizard/src/Support/MatchResolver.php
git commit -m "refactor: update jobs and MatchResolver to use Import model"
```

---

### Task 12: Update CleanupImportsCommand

**Files:**
- Modify: `app-modules/ImportWizard/src/Commands/CleanupImportsCommand.php`

**Step 1: Rewrite cleanup to work with DB records**

The command now:
1. Queries Import records with terminal status (completed/failed) older than `--completed-hours`
2. Queries Import records with non-terminal status older than `--hours` (abandoned)
3. For each: deletes the SQLite directory via `ImportStore::load($id)?->destroy()`, then deletes the Import record
4. Also cleans up orphaned directories (directories without a matching Import record)

```php
public function handle(): void
{
    $staleHours = (int) $this->option('hours');
    $completedHours = (int) $this->option('completed-hours');
    $deleted = 0;

    // Clean up completed/failed imports
    $terminal = Import::query()
        ->whereIn('status', [ImportStatus::Completed, ImportStatus::Failed])
        ->where('updated_at', '<', now()->subHours($completedHours))
        ->get();

    foreach ($terminal as $import) {
        $this->info("Cleaning up import {$import->id} (status: {$import->status->value})");
        ImportStore::load($import->id)?->destroy();
        $deleted++;
    }

    // Clean up abandoned imports (non-terminal, stale)
    $abandoned = Import::query()
        ->whereNotIn('status', [ImportStatus::Completed, ImportStatus::Failed])
        ->where('updated_at', '<', now()->subHours($staleHours))
        ->get();

    foreach ($abandoned as $import) {
        $this->info("Cleaning up abandoned import {$import->id} (status: {$import->status->value})");
        ImportStore::load($import->id)?->destroy();
        $import->delete();
        $deleted++;
    }

    // Clean up orphaned directories
    $importsPath = storage_path('app/imports');
    if (File::isDirectory($importsPath)) {
        foreach (File::directories($importsPath) as $directory) {
            $id = basename($directory);
            if (! Import::where('id', $id)->exists()) {
                File::deleteDirectory($directory);
                $deleted++;
            }
        }
    }

    $this->comment("Cleaned up {$deleted} import(s).");
}
```

Note: Terminal imports keep their DB record (that's the history!) but we delete their SQLite files. Abandoned imports get fully deleted.

**Step 2: Run cleanup tests**

```bash
php artisan test tests/Feature/ImportWizard/Commands/CleanupImportsCommandTest.php --stop-on-failure
```

Fix any failures.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Commands/CleanupImportsCommand.php
git commit -m "refactor: update CleanupImportsCommand for DB-backed imports"
```

---

### Task 13: Update all tests

**Files:**
- Modify: All files in `tests/Feature/ImportWizard/`

**Step 1: Update test helpers**

The `createImportReadyStore()` helper in `ExecuteImportJobTest.php` needs to create an Import record + ImportStore:

```php
function createImportReadyStore(
    object $context,
    array $headers,
    array $rows,
    array $mappings,
    ImportEntityType $entityType = ImportEntityType::People,
): array {
    $import = Import::create([
        'team_id' => (string) $context->team->id,
        'user_id' => (string) $context->user->id,
        'entity_type' => $entityType,
        'file_name' => 'test.csv',
        'status' => ImportStatus::Importing,
        'total_rows' => count($rows),
        'headers' => $headers,
        'column_mappings' => collect($mappings)->map(fn (ColumnData $m) => $m->toArray())->all(),
    ]);

    $store = ImportStore::create($import->id);
    $store->query()->insert($rows);

    $context->import = $import;
    $context->store = $store;

    return [$import, $store];
}
```

Similar updates needed in UploadStepTest, MappingStepTest, ReviewStepTest, PreviewStepTest — each test that creates an ImportStore needs to also create an Import record.

**Step 2: Run full test suite**

```bash
php artisan test tests/Feature/ImportWizard/ --stop-on-failure
```

Fix all failures iteratively.

**Step 3: Commit**

```bash
git add tests/Feature/ImportWizard/
git commit -m "test: update all import wizard tests for DB-backed model"
```

---

### Task 14: Filament Import History Page

**Files:**
- Create: `app-modules/ImportWizard/src/Filament/Pages/ImportHistory.php`

**Step 1: Create the history page**

This is a Filament Page with a manual table (using `HasTable` trait). It auto-discovers via the panel provider since it's in `app-modules/ImportWizard/src/Filament/Pages/`.

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;

final class ImportHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'import-wizard-new::filament.pages.import-history';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Import History';

    protected static ?string $title = 'Import History';

    protected static ?int $navigationSort = 100;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Import::query()
                    ->forTeam((string) filament()->getTenant()?->getKey())
                    ->latest()
            )
            ->columns([
                TextColumn::make('entity_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (ImportEntityType $state): string => $state->label())
                    ->icon(fn (ImportEntityType $state): string => $state->icon()),

                TextColumn::make('file_name')
                    ->label('File')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ImportStatus $state): string => match ($state) {
                        ImportStatus::Completed => 'success',
                        ImportStatus::Failed => 'danger',
                        ImportStatus::Importing => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('total_rows')
                    ->label('Total')
                    ->numeric(),

                TextColumn::make('created_rows')
                    ->label('Created')
                    ->numeric()
                    ->color('success'),

                TextColumn::make('updated_rows')
                    ->label('Updated')
                    ->numeric()
                    ->color('info'),

                TextColumn::make('skipped_rows')
                    ->label('Skipped')
                    ->numeric()
                    ->color('gray'),

                TextColumn::make('user.name')
                    ->label('User'),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->options(ImportEntityType::class),

                SelectFilter::make('status')
                    ->options([
                        ImportStatus::Completed->value => 'Completed',
                        ImportStatus::Failed->value => 'Failed',
                        ImportStatus::Importing->value => 'Importing',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('10s');
    }
}
```

**Step 2: Create the Blade view**

Create `app-modules/ImportWizard/resources/views/filament/pages/import-history.blade.php`:

```blade
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
```

**Step 3: Verify it renders**

Run the app and navigate to the Import History page in the sidebar.

**Step 4: Commit**

```bash
git add app-modules/ImportWizard/src/Filament/Pages/ImportHistory.php
git add app-modules/ImportWizard/resources/views/filament/pages/import-history.blade.php
git commit -m "feat: add Import History Filament page"
```

---

### Task 15: Failed rows CSV download

**Files:**
- Create: `app-modules/ImportWizard/src/Http/Controllers/DownloadFailedRowsController.php`
- Modify: `app-modules/ImportWizard/routes/web.php`
- Modify: `app-modules/ImportWizard/src/ImportWizardNewServiceProvider.php`

**Step 1: Create the controller**

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Http\Controllers;

use Illuminate\Http\Request;
use League\Csv\Bom;
use League\Csv\Writer;
use Relaticle\ImportWizard\Models\FailedImportRow;
use Relaticle\ImportWizard\Models\Import;
use SplTempFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadFailedRowsController
{
    public function __invoke(Request $request, Import $import): StreamedResponse
    {
        abort_unless(
            (string) $import->team_id === (string) filament()->getTenant()?->getKey(),
            403,
        );

        $firstFailedRow = $import->failedRows()->first();
        $columnHeaders = $firstFailedRow ? array_keys($firstFailedRow->data) : [];
        $columnHeaders[] = 'Import Error';

        return response()->streamDownload(function () use ($import, $columnHeaders): void {
            $csv = Writer::createFromFileObject(new SplTempFileObject);
            $csv->setOutputBOM(Bom::Utf8);
            $csv->insertOne($columnHeaders);

            $import->failedRows()
                ->lazyById(100)
                ->each(function (FailedImportRow $row) use ($csv): void {
                    $csv->insertOne([
                        ...$row->data,
                        $row->validation_error ?? 'System error',
                    ]);
                });

            echo $csv->toString();
        }, "failed-rows-{$import->id}.csv", ['Content-Type' => 'text/csv']);
    }
}
```

**Step 2: Register the route**

Update `app-modules/ImportWizard/routes/web.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Relaticle\ImportWizard\Http\Controllers\DownloadFailedRowsController;

Route::get('imports/{import}/failed-rows/download', DownloadFailedRowsController::class)
    ->name('import-history.failed-rows.download')
    ->middleware('signed');
```

**Step 3: Add download action to ImportHistory page**

Add a table action to the ImportHistory page that generates a signed URL:

```php
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\URL;

// In table() method, add:
->actions([
    Action::make('downloadFailedRows')
        ->label('Download Failed Rows')
        ->icon('heroicon-o-arrow-down-tray')
        ->color('danger')
        ->url(fn (Import $record): string => URL::signedRoute(
            'import-history.failed-rows.download',
            ['import' => $record],
        ), shouldOpenInNewTab: true)
        ->visible(fn (Import $record): bool => $record->failedRows()->exists()),
])
```

**Step 4: Commit**

```bash
git add app-modules/ImportWizard/src/Http/Controllers/DownloadFailedRowsController.php
git add app-modules/ImportWizard/routes/web.php
git add app-modules/ImportWizard/src/Filament/Pages/ImportHistory.php
git commit -m "feat: add failed rows CSV download via signed route"
```

---

### Task 16: Update PreviewStep failed rows download

**Files:**
- Modify: `app-modules/ImportWizard/src/Livewire/Steps/PreviewStep.php`

**Step 1: Update downloadFailedRows**

The PreviewStep's `downloadFailedRows()` method currently reads from ImportStore meta. After the refactor, failed rows are stored in the `failed_import_rows` table. Update to read from `$this->import()->failedRows()` and `$this->import()->failed_rows_data` for the error messages.

During execution, the in-memory `failed_rows_data` (list of `{row, error}`) is available, and detailed row data is in `failed_import_rows` table. Use the database records for the download since they have the full row data.

```php
public function downloadFailedRows(): StreamedResponse
{
    $import = $this->import();
    $headers = $import->headers ?? [];

    return response()->streamDownload(function () use ($import, $headers): void {
        $handle = fopen('php://output', 'w');
        fputcsv($handle, [...$headers, 'Import Error'], escape: '\\');

        $import->failedRows()
            ->lazyById(100)
            ->each(function (FailedImportRow $row) use ($handle, $headers): void {
                $values = [];
                foreach ($headers as $header) {
                    $values[] = $row->data[$header] ?? '';
                }
                $values[] = $row->validation_error ?? '';
                fputcsv($handle, $values, escape: '\\');
            });

        fclose($handle);
    }, 'failed-rows.csv', ['Content-Type' => 'text/csv']);
}
```

If no `failed_import_rows` exist yet (execution still in progress, errors stored in `failed_rows_data`), fall back to the SQLite-based approach. But this is an edge case — the download button only shows after completion.

**Step 2: Commit**

```bash
git add app-modules/ImportWizard/src/Livewire/Steps/PreviewStep.php
git commit -m "refactor: update PreviewStep failed rows download"
```

---

### Task 17: Final integration test

**Files:**
- Modify: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php`

**Step 1: Add test for Import record persistence**

Add a test that verifies the full flow: Import record is created, ExecuteImportJob runs, Import record is updated with results, and failed rows are persisted in `failed_import_rows`.

```php
it('persists results to Import model on completion', function (): void {
    $headers = ['Name', 'Email'];
    $rows = [
        makeRow(2, ['Name' => 'John', 'Email' => 'john@test.com'], ['match_action' => RowMatchAction::Create->value]),
    ];
    $mappings = [
        ColumnData::toField('Name', 'name'),
        ColumnData::toField('Email', 'email'),
    ];

    [$import, $store] = createImportReadyStore($this, $headers, $rows, $mappings);

    runImportJob($this);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->completed_at)->not->toBeNull()
        ->and($import->created_rows)->toBe(1)
        ->and($import->results)->toBe(['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0]);
});
```

**Step 2: Run the full test suite**

```bash
php artisan test tests/Feature/ImportWizard/ --stop-on-failure
```

All tests should pass.

**Step 3: Commit**

```bash
git add tests/Feature/ImportWizard/
git commit -m "test: add integration test for Import model persistence"
```

---

### Task 18: Delete meta.json references and cleanup

**Step 1: Search for any remaining meta.json references**

```bash
grep -r "meta.json\|metaPath\|writeMeta\|updateMeta\|refreshMeta\|meta()" app-modules/ImportWizard/src/ --include="*.php"
```

Fix any remaining references.

**Step 2: Run full test suite one final time**

```bash
php artisan test tests/Feature/ImportWizard/
```

All tests pass.

**Step 3: Commit**

```bash
git add -A
git commit -m "chore: remove remaining meta.json references"
```

---

### Task 19: Run linting and static analysis

**Step 1: Run Pint**

```bash
./vendor/bin/pint app-modules/ImportWizard/src/ tests/Feature/ImportWizard/
```

**Step 2: Run PHPStan (if configured)**

```bash
./vendor/bin/phpstan analyse app-modules/ImportWizard/src/ --level=max
```

Fix any issues.

**Step 3: Commit**

```bash
git add -A
git commit -m "chore: fix code style and static analysis issues"
```
