# Import Wizard New - Architecture Decisions

This document contains the finalized architecture decisions for the new Import Wizard.

**Last Updated:** 2026-01-16

---

## Core Principles

- [x] Handle 10,000 rows smoothly (tested: 47ms insert, <1ms queries)
- [x] Simple, maintainable code (no over-engineering)
- [x] No backward compatibility with old ImportWizard

---

## Confirmed Requirements

| Requirement | Status | Notes                       |
|-------------|--------|-----------------------------|
| Review Values step | ✅ Confirmed | Essential for data quality  |
| Smart relationship matching | ✅ Confirmed | By domain, email, phone, ID |
| Custom field validation | ✅ Confirmed | With option creation        |
| Live preview/validation | ✅ Confirmed | Not deferred to job         |

---

## Architecture Decisions

### 1. Storage Architecture

**Decision:** `IMPLEMENTED` - SQLite Only (No PostgreSQL model)

Everything lives in a self-contained folder per import session:

```
storage/app/imports/{ulid}/
├── meta.json           # Status, entity_type, mappings, results
└── data.sqlite         # All rows with validation & corrections
```

**Note:** Original CSV is NOT stored - once parsed into SQLite, it's redundant (YAGNI).

**Rationale:**
- No database migrations needed
- Complete isolation per import
- Easy cleanup (delete folder)
- No naming conflicts with Filament's `imports` table
- Follows Laravel convention (`storage/app/` for application files)

---

### 2. Class Architecture (Storage Layer)

**Decision:** `IMPLEMENTED` - Clean Separation (Store vs Model)

| Class | Responsibility | Lines |
|-------|----------------|-------|
| `ImportStore` | Storage layer (files, connections, lifecycle) | 333 |
| `ImportRow` | Eloquent model (queries, scopes, accessors) | ~130 |

**ImportStore handles:**
- File/folder management (paths)
- Meta.json read/write
- SQLite connection setup (Sushi-inspired)
- Eloquent query builder access via `query()`
- Lifecycle (create, load, destroy)

**ImportRow handles:**
- JSON accessors (data, validation, corrections)
- Query scopes (withErrors, withCorrections, valid)
- Helper methods (hasErrors, getFinalValue, getFinalData)

**Rationale:**
- Single responsibility principle
- ImportStore = storage concerns only
- ImportRow = query concerns only
- Follows Laravel patterns (Model::on($connection))

---

### 3. SQLite Schema

**Decision:** `IMPLEMENTED` - Single Table with JSON

```sql
CREATE TABLE import_rows (
    row_number INTEGER PRIMARY KEY,
    data TEXT NOT NULL,           -- {"Name":"John","Email":"john@..."}
    validation TEXT,              -- {"Email":"Invalid format"} or NULL
    corrections TEXT              -- {"Email":"fixed@example.com"} or NULL
);

CREATE INDEX idx_validation ON import_rows(validation);
```

**Why JSON over EAV:**
| Criteria | JSON | EAV |
|----------|------|-----|
| Records (10K rows) | 10,000 | 150,000 |
| Storage | ~1 MB | ~5-7 MB |
| Insert speed | ~50ms | ~2s |
| Row preview | One query | Pivot needed |
| Code simplicity | Natural | Verbose |

---

### 4. Step Flow

**Decision:** `CONFIRMED` - 4 Steps

```
Step 1: Upload        → Upload CSV, populate SQLite
Step 2: Map Columns   → Auto-map + manual adjustment
Step 3: Review Values → Validate, correct errors
Step 4: Preview/Import → Show counts, execute import
```

**Rationale:** Clear separation of concerns, familiar flow from current wizard

---

### 5. Processing Strategy

**Decision:** `IMPLEMENTED` - Deferred Store Creation

**Two-phase approach:**
1. **On file upload:** Quick validation only (headers, row count) - no SQLite store created
2. **On "Continue to Mapping" click:** Create store, stream data to SQLite

```
User selects file → Quick validation (~10ms) → Show preview
User clicks Continue → Create SQLite → Stream rows → Proceed to mapping
```

**Rationale:**
- No wasted work if user re-uploads or cancels
- User sees instant preview feedback
- Temporary file stays in Livewire temp storage until committed
- Still inline (no background jobs)

---

### 5a. Streaming Insert Strategy

**Decision:** `IMPLEMENTED` - LazyCollection Direct Streaming

Stream directly from SimpleExcelReader's LazyCollection to SQLite without loading all rows into memory:

```php
// GOOD: Stream through chunks - only 500 rows in memory at a time
SimpleExcelReader::create($path)
    ->getRows()              // Returns LazyCollection
    ->reject(...)            // Filter lazily
    ->take($maxRows)         // Limit lazily
    ->map(fn ($row) => [...])  // Transform lazily
    ->chunk(500)             // Process in batches
    ->each(fn ($chunk) => $store->query()->insert($chunk->all()));
```

**Why not load all then chunk:**
```php
// BAD: Loads 10K rows into memory first
$dataRows = $reader->getRows()->all();  // 10K rows in memory!
collect($dataRows)->chunk(500)->each(...);
```

**Benefits:**
- ~99% memory reduction (50KB vs 10MB for 10K rows)
- True streaming: file → transform → SQLite
- No intermediate array conversion

---

### 6. Import Lifecycle

**Decision:** `CONFIRMED` - Ephemeral (No Listing)

- Imports are not listed or queried
- User starts → completes or abandons → folder deleted
- No PostgreSQL record needed
- Simplifies architecture

---

### 7. CSV Parser

**Decision:** `IMPLEMENTED` - Spatie Simple Excel

```php
use Spatie\SimpleExcel\SimpleExcelReader;

$reader = SimpleExcelReader::create($path)
    ->noHeaderRow()
    ->getRows();

$headers = $reader->first();
$dataRows = $reader->skip(1);
```

**Rationale:**
- Lightweight wrapper around OpenSpout
- Handles CSV, XLSX, ODS formats
- Memory efficient (LazyCollection)
- Already in project dependencies

---

### 8. Livewire Component Architecture

**Decision:** `IMPLEMENTED` - Parent + Child Components

```
ImportWizard (Parent)
├── UploadStep (Child)
├── MappingStep (Child)
├── ReviewStep (Child)
└── PreviewStep (Child)
```

**Parent (ImportWizard) responsibilities:**
- Step state management (`$currentStep`)
- Store ID tracking (`$storeId`)
- Navigation methods (`nextStep()`, `goBack()`)
- Cancel/cleanup logic
- Step indicator UI

**Child (Step) responsibilities:**
- Step-specific logic only
- Load from store in `mount()`
- Dispatch completion events
- Call `$parent` for navigation

**Why not alternatives:**
| Option | Rejected Because |
|--------|------------------|
| Single Component + Traits | Large file, hard to test steps |
| Filament Wizard Schema | Less control over transitions |
| Monolithic Component | 1000+ lines, unmaintainable |

---

### 9. Parent-Child Communication

**Decision:** `IMPLEMENTED` - @event Syntax + $parent Magic

#### Child → Parent Events (Completion)
```blade
{{-- In parent view --}}
<livewire:import-wizard-new.steps.upload
    :entity-type="$entityType"
    :store-id="$storeId"
    @completed="onUploadCompleted($event.detail.storeId, $event.detail.rowCount, $event.detail.columnCount)"
/>
```

```blade
{{-- In child view - dispatch without server round-trip --}}
<x-filament::button
    wire:click="$dispatch('completed', { storeId: '{{ $storeId }}', rowCount: {{ $rowCount }} })"
>
    Continue
</x-filament::button>
```

#### Child → Parent Navigation (Direct Calls)
```blade
{{-- In child view --}}
<x-filament::button wire:click="$parent.goBack()">
    Back
</x-filament::button>

<x-filament::button wire:click="$parent.nextStep()">
    Continue
</x-filament::button>
```

**Why this pattern:**
- `@event` = clean event binding, no `#[On()]` attributes needed
- `$dispatch()` client-side = 1 request instead of 2
- `$parent` = direct method calls, no extra events for navigation
- Both patterns are Livewire 4 native features

---

### 10. Back Navigation

**Decision:** `CONFIRMED` - Show Existing File Summary

When user navigates back from Step 2 to Step 1:
- UploadStep remounts with existing `storeId`
- Loads file summary from store (headers, row count)
- Shows "File already uploaded" state
- No re-upload required

```php
public function mount(?string $storeId = null): void
{
    if ($storeId !== null) {
        $this->store = ImportStore::load($storeId);
        $this->headers = $this->store->headers();
        $this->rowCount = $this->store->rowCount();
        $this->isParsed = true;
    }
}
```

**Rationale:**
- Better UX (don't lose work)
- SQLite already has all data
- Simple implementation (just load from store)

---

### 11. Cancel Button Location

**Decision:** `CONFIRMED` - In Parent Component

- Cancel button in parent's view (not in step components)
- Parent handles cleanup (`$store->destroy()`)
- Consistent position across all steps

---

### 12. Step Indicator Location

**Decision:** `CONFIRMED` - In Parent View

- Step indicator rendered in parent's blade template
- Uses partial: `partials/step-indicator.blade.php`
- Parent has `$currentStep` state for active highlighting

---

## Technical Stack

| Component | Technology | Status |
|-----------|------------|--------|
| Backend | Laravel 12, PHP 8.4 | ✅ |
| Frontend | Livewire 4, Alpine.js | ✅ Implemented |
| Main Database | PostgreSQL | ✅ |
| Import Storage | SQLite (per-import) | ✅ Implemented |
| CSV Parsing | Spatie Simple Excel | ✅ Implemented |
| UI Components | Filament 5 | ✅ |

---

## File Structure

```
app-modules/ImportWizardNew/
├── config/
│   └── import-wizard.php
├── routes/
│   └── web.php
├── resources/
│   └── views/
│       ├── filament/pages/
│       │   └── import-page.blade.php
│       └── livewire/
│           ├── import-wizard.blade.php
│           ├── partials/
│           │   └── step-indicator.blade.php
│           └── steps/
│               ├── upload-step.blade.php
│               ├── mapping-step.blade.php
│               ├── review-step.blade.php
│               └── preview-step.blade.php
├── src/
│   ├── ImportWizardNewServiceProvider.php
│   ├── Enums/
│   │   ├── ImportStatus.php          ✅
│   │   └── ImportEntityType.php      ✅
│   ├── Filament/
│   │   └── Pages/
│   │       ├── ImportPage.php        (Base class)
│   │       ├── ImportCompaniesNew.php
│   │       └── ImportTasksNew.php
│   ├── Livewire/
│   │   ├── ImportWizard.php          ✅ (Parent)
│   │   └── Steps/
│   │       ├── UploadStep.php        ✅
│   │       ├── MappingStep.php       ✅ (Placeholder)
│   │       ├── ReviewStep.php        ✅ (Placeholder)
│   │       └── PreviewStep.php       ✅ (Placeholder)
│   └── Store/
│       ├── ImportStore.php           ✅ (333 lines)
│       └── ImportRow.php             ✅ (~130 lines)
├── BRAINSTORMING.md
└── DECISIONS.md
```

---

## Performance Benchmarks

Tested with 10,000 rows:

| Operation | Time | Notes |
|-----------|------|-------|
| Insert 10,000 rows | 47ms | Chunked in 500s |
| Count all rows | <1ms | |
| withErrors() scope | 0.15ms | |
| valid() scope | 0.5ms | |
| Paginated query | 1.44ms | 50 rows |
| find() single row | 0.1ms | By primary key |
| SQLite file size | 984 KB | ~100 bytes/row |

---

## Key Patterns Used

### Sushi-Inspired SQLite Handling
```php
// Create empty file
file_put_contents($this->sqlitePath(), '');

// Register connection in config
app('config')->set("database.connections.{$name}", $config);

// Create connection
app(ConnectionFactory::class)->make($config, $name);

// Race condition handling
try {
    $schema->create('import_rows', ...);
} catch (QueryException $e) {
    if (Str::contains($e->getMessage(), 'already exists')) {
        return;
    }
    throw $e;
}
```

### Dynamic Eloquent Connection
```php
// ImportStore returns Eloquent builder bound to its SQLite
public function query(): EloquentBuilder
{
    $this->connection(); // Ensure registered
    return ImportRow::on($this->connectionName());
}

// Usage
$store->query()->withErrors()->count();
$store->query()->find(5);
```

### Livewire 4 Parent-Child Communication
```blade
{{-- Parent catches child events via @event syntax --}}
<livewire:child-component
    @completed="onChildCompleted($event.detail.data)"
/>

{{-- Child calls parent methods via $parent magic --}}
<button wire:click="$parent.nextStep()">Continue</button>

{{-- Child dispatches events client-side (no server round-trip) --}}
<button wire:click="$dispatch('completed', { data: 'value' })">Done</button>
```

### Laravel `once()` for Config Memoization
```php
// Memoize config reads per request
protected function maxRows(): int
{
    return once(fn (): int => (int) config('import-wizard.max_rows', 10_000));
}

protected function chunkSize(): int
{
    return once(fn (): int => (int) config('import-wizard.chunk_size', 500));
}
```

### LazyCollection Streaming Pattern
```php
// Stream from file to database without loading all into memory
SimpleExcelReader::create($path)
    ->trimHeaderRow()
    ->getRows()                    // LazyCollection
    ->reject(fn ($row) => ...)     // Filter lazily
    ->take($maxRows)               // Limit lazily
    ->map(fn ($row, $i) => [...])  // Transform lazily
    ->chunk($chunkSize)            // Batch lazily
    ->each(fn ($chunk) => $store->query()->insert($chunk->all()));
```

---

## Next Decisions Needed

1. **Auto-mapping algorithm**: How fuzzy should matching be?
2. **Validation approach**: All upfront vs per-column on-demand?
3. **Relationship matching priority**: Domain → Email → ID?
4. **Custom field options**: Auto-create or confirm first?
