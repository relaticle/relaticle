# Import Wizard New - Brainstorming Session

This document tracks the entire brainstorming process for rebuilding the Import Wizard from scratch.

---

## Session Start: 2026-01-15

### Goal
Build a brand new import wizard that:
- Handles 10,000 rows smoothly without performance issues
- Is simple and maintainable (no over-engineering)
- Replaces the old ImportWizard completely (no backward compatibility)

---

## Initial Requirements Discussion

**Core requirements confirmed by Dr. Level:**
1. ✅ Keep the Review Values step (Step 3) - essential for data quality
2. ✅ Keep smart relationship matching (by domain, by email, by ID)
3. ✅ Keep custom field validation with option creation
4. ✅ Live preview with validation (not deferred to job results)

---

## Architecture Options Explored

### Phase 1: Storage Architecture

#### Option A: Database Model with JSON Columns
Store everything in an `imports` table with JSON columns.

**Pros:** Simple queries, familiar pattern, easy to resume later
**Cons:** JSON columns can get large (1-5MB), harder to search within

#### Option B: File-Based Sessions
```
storage/app/imports/{session-id}/
├── meta.json
└── data.sqlite
```

**Pros:** No DB bloat, unlimited size, easy cleanup
**Cons:** Can't query across imports, slightly more I/O

**Note:** Original CSV not stored - redundant once parsed into SQLite.

#### Option C: Lazy Analysis (No Upfront Processing)
Analyze columns on-demand when user clicks them.

**Pros:** Instant upload, only compute what's needed
**Cons:** Slower per-column clicks, repeated file reads

#### Option D: Temp Database Tables
Create actual temp tables per import in main database.

**Pros:** Real SQL queries, indexed search
**Cons:** DB management complexity, cleanup needed

#### Option E: SQLite Per Import ⭐ CHOSEN
Each import session gets its own SQLite file.

**Pros:** Full SQL power, isolated, easy cleanup, no main DB bloat
**Cons:** Slightly unfamiliar pattern, need SQLite driver

#### Option F: Streaming + Minimal State
No value review - errors shown during import.

**Pros:** Simplest, fastest
**Cons:** Errors discovered late, can't fix before importing

---

### Phase 2: Livewire Component Architecture

Four options were explored for structuring the wizard UI:

#### Option A: Single Component + Step Traits
One monolithic component with traits for each step.

**Pros:** Simple mental model, all state in one place
**Cons:** Large file, step logic mixed together, hard to test individual steps

#### Option B: Parent + Child Components ⭐ CHOSEN
Parent orchestrator + separate Livewire components per step.

**Pros:**
- Clean separation of concerns
- Each step independently testable
- Smaller, focused files
- Natural Livewire pattern

**Initial Concern:** "Complex communication between parent/children"

**Resolution:** Complexity was overstated. Just passing `storeId` is sufficient since:
- SQLite store lives on disk
- Each child loads from store in `mount()`
- Events bubble up naturally with `@event` syntax

#### Option C: Filament Wizard Schema
Use Filament's built-in Wizard schema component.

**Pros:** Pre-built navigation, Filament integration
**Cons:** Less control over step transitions, harder to customize

#### Option D: Single Monolithic Component
Everything in one massive component.

**Pros:** No communication overhead
**Cons:** 1000+ line file, untestable, unmaintainable

---

## Livewire 4 Research

### Key Patterns Discovered

After researching Livewire 4 documentation, found elegant patterns for parent-child communication:

#### 1. `@event` Syntax on Component Tags
```blade
<livewire:import-wizard-new.steps.upload
    :entity-type="$entityType"
    :store-id="$storeId"
    @completed="onUploadCompleted($event.detail.storeId, $event.detail.rowCount, $event.detail.columnCount)"
/>
```
- Event parameters accessible via `$event.detail.paramName`
- No `#[On()]` attributes needed on parent methods
- Clean, declarative event binding

#### 2. `$parent` Magic Variable
```blade
{{-- In child component view --}}
<x-filament::button wire:click="$parent.goBack()">
    Back
</x-filament::button>
```
- Direct parent method calls from child views
- No dispatch/listen overhead
- Works via Alpine.js under the hood

#### 3. Client-Side `$dispatch()` for Navigation
```blade
{{-- Fire event without round-trip --}}
<x-filament::button
    wire:click="$dispatch('completed', { storeId: '{{ $storeId }}', rowCount: {{ $rowCount }} })"
>
    Continue
</x-filament::button>
```
- 1 request instead of 2
- Event fires client-side, caught by parent's `@completed`
- Better UX (faster navigation)

---

## SQLite Deep Dive

### Sushi Package Research (calebporzio/sushi)

Explored using Sushi for Eloquent-style SQLite access.

**How Sushi works:**
- Creates cached SQLite file per MODEL CLASS
- Uses `static::$sushiConnection` (shared by all instances)
- `file_put_contents($path, '')` for creating empty SQLite
- Schema Builder for table creation
- Race condition handling (catch "already exists" errors)
- Register connection in config: `app('config')->set("database.connections.{$name}", $config)`
- Chunked inserts for performance

**Why Sushi doesn't fit our needs:**
- Static connection = all imports share ONE SQLite (broken)
- Cache path based on class name, not instance
- Designed for fixtures, not dynamic import data

**Patterns we adopted from Sushi:**
- `file_put_contents($path, '')` for creating empty SQLite file
- `app(ConnectionFactory::class)->make($config, $name)` for connections
- Register connection in config for Laravel integration
- Schema Builder with Blueprint callback
- Race condition handling with try/catch
- `Model::on($connectionName)` for dynamic connection binding

---

## Decisions Made

### 1. Data Storage Strategy ✅
**Decision: SQLite Only - No PostgreSQL model**

Everything lives in a self-contained folder per import session:
```
storage/app/imports/{ulid}/
├── meta.json       # Status, headers, mappings
└── data.sqlite     # All row data + validation + corrections
```

**Note:** Original CSV is NOT stored - once parsed into SQLite, it's redundant.

### 2. No Import Listing ✅
**Decision: Imports are ephemeral**
- User starts import → completes or abandons → folder deleted
- No history, no listing needed

### 3. SQLite Schema ✅
**Decision: Single table with JSON (not EAV)**

```sql
CREATE TABLE import_rows (
    row_number INTEGER PRIMARY KEY,
    data TEXT NOT NULL,           -- {"Name":"John","Email":"john@..."}
    validation TEXT,              -- {"email":"Invalid format"} or NULL
    corrections TEXT              -- {"email":"fixed@example.com"} or NULL
);
```

### 4. Step Flow ✅
**Decision: 4 Steps**
```
Upload → Map Columns → Review Values → Preview/Import
```

### 5. Job Strategy ✅
**Decision: Inline during upload**
- No background jobs
- User waits ~50ms for 10K rows while SQLite is populated

### 6. Class Architecture ✅
**Decision: Clean separation - Store vs Model**
- `ImportStore` = Storage layer (files, connections, lifecycle)
- `ImportRow` = Eloquent model (queries, scopes, accessors)

### 7. CSV Parser ✅
**Decision: Spatie Simple Excel**
- Lightweight wrapper around OpenSpout
- Handles CSV, XLSX, ODS formats
- Memory efficient (LazyCollection)
- Already in project dependencies

### 8. Component Architecture ✅
**Decision: Parent + Child Components (Option B)**
- `ImportWizard` = Pure orchestrator (step state, navigation)
- `UploadStep`, `MappingStep`, `ReviewStep`, `PreviewStep` = Focused step logic

### 9. Parent-Child Communication ✅
**Decision: @event syntax + $parent magic**
- Children dispatch events → parent catches via `@completed="..."`
- Children call parent methods directly via `$parent.nextStep()`
- No custom event listeners or #[On()] attributes needed

### 10. Back Navigation ✅
**Decision: Show existing file summary**
- When user goes back from Step 2 to Step 1, show parsed file info
- Child re-mounts, loads existing data from store
- No re-upload required

---

## Implementation Progress

### Phase 1: Core Storage ✅ COMPLETE

#### ImportStore (Storage Layer) - ~320 lines
Pure storage management, no query logic:

```php
// Factory methods
ImportStore::create($teamId, $userId, $entityType, $filename)
ImportStore::load($id)

// Path management
$store->id(), $store->path()
$store->metaPath(), $store->sqlitePath()

// Meta operations (JSON file)
$store->meta(), $store->writeMeta(), $store->updateMeta()
$store->status(), $store->setStatus()
$store->headers(), $store->setHeaders()
$store->rowCount(), $store->setRowCount()
$store->columnMappings(), $store->setColumnMappings()
$store->entityType(), $store->teamId(), $store->userId()

// SQLite connection (Sushi-inspired)
$store->connectionName()
$store->connection()
$store->query()  // Returns Eloquent Builder for ImportRow

// Lifecycle
$store->destroy()
$store->belongsToTeam($teamId)
```

#### ImportRow (Eloquent Model)
Full Eloquent power with dynamic connection binding:

```php
// Accessors (auto JSON encode/decode)
$row->data           // array
$row->validation     // array|null
$row->corrections    // array|null

// Query Scopes
$store->query()->withErrors()      // Rows with validation errors
$store->query()->withCorrections() // Rows with corrections
$store->query()->valid()           // Rows without errors

// Helpers
$row->hasErrors()
$row->hasCorrections()
$row->getFinalValue($column)  // Correction or original
$row->getFinalData()          // All values with corrections applied
```

### Performance Test Results (10,000 rows)

| Operation | Time |
|-----------|------|
| Insert 10,000 rows | **47ms** |
| withErrors() count | **0.15ms** |
| valid() count | **0.5ms** |
| Paginated query (50 rows) | **1.44ms** |
| find() single row | **0.1ms** |
| SQLite file size | **984 KB** |

---

### Phase 2: Livewire UI 🔄 IN PROGRESS

#### ImportWizard (Parent Orchestrator) ✅
Pure orchestration - no CSV parsing, no validation logic:

```php
final class ImportWizard extends Component
{
    public const int STEP_UPLOAD = 1;
    public const int STEP_MAP = 2;
    public const int STEP_REVIEW = 3;
    public const int STEP_PREVIEW = 4;

    public int $currentStep = self::STEP_UPLOAD;
    public ?string $storeId = null;
    public int $rowCount = 0;
    public int $columnCount = 0;

    // Called via @completed event from UploadStep
    public function onUploadCompleted(string $storeId, int $rowCount, int $columnCount): void
    {
        $this->storeId = $storeId;
        $this->rowCount = $rowCount;
        $this->columnCount = $columnCount;
        $this->nextStep();
    }

    // Called via $parent.nextStep() from child views
    public function nextStep(): void
    public function goBack(): void
    public function cancelImport(): void
}
```

#### UploadStep ✅
All CSV parsing logic lives here with deferred store creation:

**Two-phase approach:**
1. `validateFile()` - Quick validation on upload (headers, row count preview)
2. `continueToMapping()` - Create store and stream data on user commitment

```php
final class UploadStep extends Component
{
    // Key patterns used:
    // - SimpleExcelReader::create($path)->trimHeaderRow()
    // - blank($rawHeaders) instead of === null || === []
    // - array_all($row, blank(...)) for empty row check (PHP 8.4)
    // - LazyCollection streaming: getRows()->chunk()->each() (no ->all()!)
    // - once() for config memoization
    // - Deferred store creation (only on "Continue" click)
}
```

**Why deferred store creation?**
- Explored: Create store immediately on file upload
- Problem: Wasted work if user re-uploads or navigates away
- Solution: Only create store when user commits by clicking "Continue"

**Livewire protected property gotcha:**
- Protected properties don't persist between Livewire requests
- Can't store `$dataRows` as protected property then use in `continueToMapping()`
- Solution: Re-parse file in `continueToMapping()` using LazyCollection streaming

#### MappingStep, ReviewStep, PreviewStep ✅
Placeholder components created, ready for implementation.

---

## Next Steps - Brainstorming Needed

### Phase 3: Column Mapping
- [ ] Auto-mapping logic (fuzzy matching)
- [ ] Entity field definitions
- [ ] Custom field integration
- [ ] Relationship field handling

### Phase 4: Value Validation
- [ ] Email validation
- [ ] Phone validation
- [ ] Date parsing
- [ ] Relationship matching (by domain, email, ID)
- [ ] Custom field option validation

### Phase 6: Import Execution
- [ ] Record creation logic
- [ ] Error handling
- [ ] Rollback strategy
- [ ] Results summary

---

## Open Questions

1. **Auto-mapping**: How fuzzy should matching be?
2. **Validation timing**: Validate all upfront or on-demand per column?
3. **Relationship matching**: Priority order for domain vs email vs ID?
4. **Custom field options**: Create automatically or require confirmation?

---

## Notes & Ideas

- SQLite handles millions of rows easily with proper indexes
- Each import isolated = no cross-contamination, easy cleanup
- Ephemeral imports = no listing needed, simpler architecture
- Eloquent `on($connection)` pattern works perfectly for dynamic SQLite binding
- Empty arrays saved as null (edge case handled)
- Livewire 4's `@event` syntax is cleaner than `#[On()]` attributes
- `$parent` magic simplifies navigation without extra events
- **Don't store original CSV** - once parsed into SQLite, it's redundant (YAGNI)
- **Validate-first pattern** - validate row count BEFORE creating store (no wasted work)
- **PHP 8.4 `array_all()`** + first-class callable `blank(...)` = elegant empty check
- **SimpleExcel `trimHeaderRow()`** - eliminates manual trim loops
- **Storage location** - `storage/app/imports/` follows Laravel conventions
- **Deferred store creation** - only create SQLite on user commitment (Continue click)
- **Livewire protected properties** - don't persist between requests! Use LazyCollection streaming instead
- **LazyCollection streaming** - `getRows()->chunk()->each()` instead of `getRows()->all()` then `collect()->chunk()`
- **`once()` helper** - memoize config reads per request
- **Memory efficiency** - streaming reduces memory from ~10MB to ~50KB for 10K rows

---

## Session: 2026-01-16 - Simplification Round

### Storage Location Discussion
- Original: `storage/imports/{ulid}/`
- Changed to: `storage/app/imports/{ulid}/`
- Rationale: Follows Laravel convention for application files

### Deferred Store Creation Discussion
**Question:** Should we create store immediately on upload or wait for "Continue" click?

**Option A: Immediate (on upload)**
- Pros: Data already in SQLite when Continue clicked
- Cons: Wasted work if user re-uploads, SQLite created even for abandoned uploads

**Option B: Deferred (on Continue)** ✅ CHOSEN
- Pros: No wasted work, user commits before processing
- Cons: Slight delay on Continue click

### Livewire Protected Property Bug
**Problem:** Stored `$dataRows` in protected property during `validateFile()`, but it was empty `[]` when `continueToMapping()` was called.

**Cause:** Protected properties don't persist between Livewire requests - only public properties are serialized.

**Options explored:**
- A) Make `$dataRows` public (BAD - serializes 10K rows to frontend)
- B) Re-parse file on Continue (GOOD - LazyCollection makes this efficient) ✅
- C) Store in cache/session (overcomplicated)

### LazyCollection Streaming Discovery
**Insight:** `SimpleExcelReader::getRows()` returns a `LazyCollection` - we can stream directly to SQLite!

**Before (wasteful):**
```php
$dataRows = $reader->getRows()->all();  // Load ALL 10K rows into memory
collect($dataRows)->chunk(500)->each(...);  // Re-wrap in collection
```

**After (streaming):**
```php
$reader->getRows()  // LazyCollection - never loads all rows
    ->map(...)      // Transform lazily
    ->chunk(500)    // Only 500 rows in memory
    ->each(...);    // Insert and discard
```

**Result:** ~99% memory reduction (50KB vs 10MB for 10K rows)
