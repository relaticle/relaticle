# Import Wizard New - Architecture Decisions

This document contains the finalized architecture decisions for the new Import Wizard.

**Last Updated:** 2026-01-22

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
| `ImportStore` | Storage layer (files, connections, lifecycle) | ~425 |
| `ImportRow` | Eloquent model (queries, scopes, accessors) | ~180 |

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
│       ├── components/
│       │   └── field-select.blade.php
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
│   ├── Data/
│   │   ├── ColumnMapping.php         ✅
│   │   ├── ImportField.php           ✅
│   │   ├── ImportFieldCollection.php ✅
│   │   ├── InferenceResult.php       ✅
│   │   ├── MatchableField.php        ✅
│   │   ├── RelationshipField.php     ✅
│   │   └── RelationshipMatch.php     ✅
│   ├── Enums/
│   │   ├── DateFormat.php            ✅
│   │   ├── ImportEntityType.php      ✅
│   │   ├── ImportStatus.php          ✅
│   │   └── RowMatchAction.php        ✅
│   ├── Filament/
│   │   └── Pages/
│   │       ├── ImportPage.php        (Base class)
│   │       ├── ImportCompaniesNew.php
│   │       └── ImportTasksNew.php
│   ├── Importers/
│   │   ├── Contracts/
│   │   │   └── ImporterContract.php  ✅
│   │   ├── BaseImporter.php          ✅
│   │   ├── CompanyImporter.php       ✅
│   │   ├── NoteImporter.php          ✅
│   │   ├── OpportunityImporter.php   ✅
│   │   ├── PeopleImporter.php        ✅
│   │   └── TaskImporter.php          ✅
│   ├── Livewire/
│   │   ├── Concerns/
│   │   │   └── WithImportStore.php   ✅
│   │   ├── ImportWizard.php          ✅ (Parent)
│   │   └── Steps/
│   │       ├── UploadStep.php        ✅
│   │       ├── MappingStep.php       ✅ (~443 lines)
│   │       ├── ReviewStep.php        ✅ (~450 lines)
│   │       └── PreviewStep.php       ⏳ (Placeholder)
│   ├── Rules/
│   │   ├── ImportDateRule.php        ✅
│   │   └── ImportChoiceRule.php      ✅
│   ├── Store/
│   │   ├── ImportStore.php           ✅ (~425 lines)
│   │   └── ImportRow.php             ✅ (~180 lines)
│   └── Support/
│       ├── ColumnAnalyzer.php        ✅
│       ├── DataTypeInferencer.php    ✅
│       └── ValueValidator.php        ✅
├── resources/
│   └── lang/
│       └── en/
│           └── validation.php        ✅
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

## 13. Importer Architecture

### Decision: Framework-Agnostic Importers

**Status:** `IMPLEMENTED`

Replace Filament importers with new framework-agnostic Importer classes that serve as the single source of truth for all import configuration.

**Rationale:**
1. Filament's `Importer` class is tightly coupled to modal UI flow
2. New wizard needs preview, validation display, corrections - Filament doesn't support
3. One source of truth prevents sync issues between two import systems
4. Framework-agnostic design allows reuse (API imports, CLI, etc.)

**Design Principles:**
1. **Explicit over implicit** - Each importer writes its own relationship logic
2. **Minimal shared utilities** - BaseImporter provides optional helper methods
3. **No traits** - Keep importers self-contained and easy to understand

**File Structure:**
```
src/
├── Data/
│   ├── ImportField.php            # Single field definition (immutable)
│   ├── ImportFieldCollection.php  # Collection with auto-mapping
│   ├── RelationshipField.php      # Relationship field definition
│   ├── MatchableField.php         # Field matching configuration
│   └── ...
└── Importers/
    ├── Contracts/
    │   └── ImporterContract.php   # Interface
    ├── BaseImporter.php           # Shared logic + optional helpers
    ├── CompanyImporter.php
    ├── PeopleImporter.php
    ├── OpportunityImporter.php
    ├── TaskImporter.php
    └── NoteImporter.php
```

---

### 14. Field Definition Pattern

**Status:** `IMPLEMENTED`

Immutable `ImportField` class with fluent builder pattern:

```php
ImportField::make('name')
    ->label('Name')
    ->required()
    ->rules(['required', 'string', 'max:255'])
    ->guess(['name', 'company_name', 'organization'])
    ->example('Acme Corporation')
    ->type('text');
```

**Properties:**
| Property | Description |
|----------|-------------|
| `key` | Database column or custom field key |
| `label` | Display label |
| `required` | Whether field is required |
| `rules` | Laravel validation rules |
| `guesses` | Column name aliases for auto-mapping |
| `example` | Example value for display |
| `type` | Field type hint (text, email, number, date, etc.) |
| `isCustomField` | Whether this is a custom field |

**Pre-configured Factory:**
```php
ImportField::id()  // Pre-configured ID field with ULID validation
```

---

### 15. Relationship Handling

**Status:** `IMPLEMENTED`

**Decision:** Explicit in each importer with minimal shared utilities.

Each importer writes its own relationship resolution logic. BaseImporter provides optional helper methods but importers choose whether to use them.

**RelationshipField Definition:**
```php
RelationshipField::belongsTo('company', Company::class)
    ->matchableFields([
        MatchableField::id(),
        MatchableField::domain('custom_fields_domains'),
        MatchableField::name(),
    ])
    ->foreignKey('company_id')
    ->guess(['company', 'company_name', 'organization']);
```

**Pre-configured Factories:**
```php
RelationshipField::company()              // BelongsTo Company
RelationshipField::contact()              // BelongsTo People
RelationshipField::polymorphicCompanies() // MorphToMany Company
RelationshipField::polymorphicPeople()    // MorphToMany People
RelationshipField::polymorphicOpportunities() // MorphToMany Opportunity
```

**Relationship Types:**
| Type | Description | Example |
|------|-------------|---------|
| `belongsTo` | Single foreign key | People → Company |
| `morphToMany` | Polymorphic many-to-many | Task → Companies/People/Opportunities |

---

### 16. Matching Logic

**Decision:** `CONFIRMED` - Single Field by Priority

Record matching uses **ONE field only** - the highest priority mapped field.

```
User maps: email, name columns
Available matchable fields: ID (100), Email (90), Name (10)
→ Highest mapped = Email (90)
→ Match ONLY by email. If no match, create new record.
```

**MatchableField Priority:**
| Field | Priority | updateOnly |
|-------|----------|------------|
| ID | 100 | true (skip if not found) |
| Email | 90 | false |
| Domain | 80 | false |
| Phone | 70 | false |
| Name | 10 | false |

**Relationship Resolution:** Same single-field logic applies.

---

### 17. Custom Fields Integration

**Status:** `IMPLEMENTED`

Custom fields are automatically discovered and appended to standard fields.

```php
// In BaseImporter
public function allFields(): FieldCollection
{
    return $this->fields()->merge($this->customFields());
}
```

**Property Mapping:**
| CustomField Property | ImportField Property |
|---------------------|---------------------|
| `name` | `label` |
| `code` | `key` (prefixed with `custom_fields_`) |
| validation_rules contains REQUIRED | `required` |
| `type` | `type` (mapped to text, email, number, etc.) |

---

### 18. Entity Summary

| Entity | Standard Fields | Relationships | Matchable By |
|--------|-----------------|---------------|--------------|
| Company | id, name, account_owner_email | None | ID, Domain |
| People | id, name | company (belongsTo) | ID, Email, Phone |
| Opportunity | id, name | company, contact (belongsTo) | ID only |
| Task | id, title, description, assignee_email | companies, people, opportunities (morphToMany) | ID only |
| Note | id, title, content | companies, people, opportunities (morphToMany) | None (always create) |

---

### 19. ImportEntityType Integration

**Status:** `IMPLEMENTED`

Updated enum with importer factory methods:

```php
use Relaticle\ImportWizardNew\Enums\ImportEntityType;

// Get importer class
$class = ImportEntityType::Company->importerClass();

// Create importer instance
$importer = ImportEntityType::Company->importer($teamId);
```

---

### 20. Date Format Handling

**Status:** `IMPLEMENTED`

**Decision:** User-selectable date format (ISO, European, American) per column in ReviewStep with parsed previews and error highlighting.

#### Design Choices

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Format detection | Manual selection | Auto-detection is unreliable for ambiguous dates (05/06/2024 could be May 6 or June 5) |
| Default format | ISO | Unambiguous, internationally recognized standard |
| Format scope | Per-column | Different columns may have different formats in same CSV |
| Preview display | ISO normalized | Consistent display regardless of input format |
| Unparseable handling | Show error count + highlight | Users can switch format or manually correct |

#### Date Format Enum

Single enum handles both date-only and datetime fields:

```php
enum DateFormat: string implements HasLabel
{
    case ISO = 'iso';        // 2024-05-15 or 2024-05-15T16:00
    case EUROPEAN = 'european'; // 15-05-2024, 15/05/2024, 15 May 2024
    case AMERICAN = 'american'; // 05-15-2024, 05/15/2024, May 15th 2024

    public function parse(string $value): ?Carbon;
    public function format(Carbon $date, bool $includeTime = false): string;
    public function getLabel(): string;
}
```

**Note:** HTML5 date pickers always output ISO format, so corrections are stored in ISO regardless of the user's selected input format.

#### Data Flow

```
CSV Upload → MappingStep (field gets type from FieldDataType)
    ↓
ReviewStep mounts → loads analyses with dataType from ImportField
    ↓
Date columns initialized with ISO format (default)
    ↓
User selects date column → format dropdown appears
    ↓
User changes format → setDateFormat() called
    ↓
ColumnMapping.dateFormat updated → meta.json saved
    ↓
Values reloaded with new format previews
    ↓
Import uses stored format for parsing
```

#### Modified Data Classes

**ImportField** - Added `type` property:
```php
public function __construct(
    // ... existing
    public readonly ?string $type = null,  // 'date', 'date_time', 'string', etc.
) {}

public function isDateType(): bool;
public function isDateTimeType(): bool;
public function isDateOrDateTime(): bool;
```

**ColumnMapping** - Added `dateFormat` property:
```php
public function __construct(
    public readonly string $source,
    public readonly string $target,
    public readonly ?string $relationship = null,
    public readonly ?string $dateFormat = null,  // 'iso', 'european', 'american'
) {}

public function withDateFormat(?string $dateFormat): self;
```

**ColumnAnalysisResult** - Added date-related properties:
```php
public function __construct(
    // ... existing
    public readonly ?string $dataType = null,      // 'date', 'date_time', null
    public readonly ?string $dateFormat = null,    // Currently selected format
    public readonly int $parseErrorCount = 0,      // Unparseable values count
) {}

public function isDateOrDateTime(): bool;
```

#### ColumnAnalyzer Enhancements

New methods for date handling:

```php
// Count values that cannot be parsed with selected format
public function getDateParseErrorCount(
    string $csvColumn,
    ?string $dataType,
    string $dateFormat
): int;

// getUniqueValuesPaginated() now accepts date parameters
public function getUniqueValuesPaginated(
    string $csvColumn,
    // ... existing params
    ?string $dataType = null,    // NEW
    ?string $dateFormat = null,  // NEW
): array;
// Returns ['values' => [..., 'parsed' => '2024-05-15'], ...]
```

#### BaseImporter Integration

Custom fields automatically get their `type` from `FieldDataType`:

```php
// In customFields() method
return ImportField::make($key)
    ->label($customField->name)
    ->asCustomField()
    ->type($customField->typeData->dataType->value);  // 'date', 'date_time', etc.
```

#### ReviewStep Component Changes

**New properties:**
```php
public array $columnDateFormats = [];  // CSV column => format value
```

**New methods:**
```php
public function setDateFormat(string $csvColumn, string $format): void
{
    // Validate format, update local state
    // Update ColumnMapping in store
    // Refresh analysis and reload values
}
```

**New computed properties:**
```php
#[Computed]
public function isSelectedColumnDateType(): bool;

#[Computed]
public function selectedColumnDateFormat(): string;

#[Computed]
public function dateFormatOptions(): array;
```

#### UI Changes (review-step.blade.php)

**Format selector in column header:**
```blade
@if ($this->isSelectedColumnDateType)
    <x-select-menu
        :options="$this->dateFormatOptions"
        wire:model.live="columnDateFormats.{{ $selectedColumn }}"
    />
@endif
```

**Value display for date columns:**
- **Valid dates:** Bordered container with visible date text and invisible date input overlay
- **Invalid dates:** Neutral bordered container + warning icon + pencil button with invisible date input overlay
- Uses `type="date"` for date fields, `type="datetime-local"` for datetime fields
- **Styling:** Both valid and invalid use neutral gray borders/backgrounds; warning icon alone signals invalid state
- Picker value pre-formatted to HTML5 standard (YYYY-MM-DD or YYYY-MM-DDTHH:MM)
- Corrections stored via `updateMappedValue()` like other columns
- Undo button appears when correction exists

**Key implementation detail:** The invisible date input uses opacity-0 and is positioned absolutely over the clickable area. The `::-webkit-calendar-picker-indicator` pseudo-element is stretched to cover the full input area for Chrome/Safari compatibility (see BF-3).

#### Files Modified for Date Handling

| File | Changes |
|------|---------|
| `src/Enums/DateFormat.php` | NEW - Date parsing/formatting enum |
| `src/Data/ImportField.php` | Added `type` property |
| `src/Data/ColumnMapping.php` | Added `dateFormat` property |
| `src/Data/ColumnAnalysisResult.php` | Added `dataType`, `dateFormat`, `parseErrorCount` |
| `src/Support/ColumnAnalyzer.php` | Added date parsing methods |
| `src/Importers/BaseImporter.php` | Added `type` from custom fields |
| `src/Store/ImportStore.php` | Added `updateMapping()` method |
| `src/Livewire/Steps/ReviewStep.php` | Added date format selection UI |

---

## Key Patterns: Importer Architecture

### Immutable Value Objects
```php
$field = ImportField::make('name');
$required = $field->required();  // New instance, $field unchanged
```

### Explicit Relationship Resolution
```php
// In PeopleImporter::prepareForSave()
private function resolveCompanyRelationship(array $data, array $context): array
{
    $companyValue = $data['company'] ?? null;
    unset($data['company']);

    if (blank($companyValue)) {
        return $data;
    }

    $matchField = $context['company_match_field'] ?? 'name';
    $companyId = $this->resolveBelongsTo(Company::class, $matchField, $companyValue);

    if ($companyId !== null) {
        $data['company_id'] = $companyId;
    }

    return $data;
}
```

### Polymorphic Sync in afterSave
```php
// In TaskImporter::afterSave()
foreach (['companies', 'people', 'opportunities'] as $relation) {
    $ids = $context["{$relation}_ids"] ?? null;
    if (filled($ids) && is_array($ids)) {
        $this->syncMorphToMany($record, $relation, $ids);
    }
}
```

---

## Updated File Structure

See [File Structure](#file-structure) section above for the complete, up-to-date directory layout.

**Implementation Status Summary:**

| Component | Status |
|-----------|--------|
| Data classes | ✅ Complete |
| Enums | ✅ Complete |
| Importers | ✅ Complete |
| UploadStep | ✅ Complete |
| MappingStep | ✅ Complete (~443 lines) |
| ReviewStep | ✅ Complete (~450 lines) |
| PreviewStep | ⏳ Placeholder (needs implementation) |

---

## Bug Fixes

### BF-1. Date Correction Not Saving (2026-01-21)

**Problem:** Selecting a date from the picker to correct an invalid date did NOT save the correction. The picker closed and the value reverted to "Invalid date". Text inputs worked correctly.

**Root Cause:** `x-if` destroys the input element before Livewire can process the `wire:change` event.

The date input was wrapped in `<template x-if="editing">`. When user selected a date:
1. Browser fires `change` event
2. `wire:change` tries to queue Livewire request
3. Date picker closes → input loses focus → `blur` event fires
4. `@blur="editing = false"` executes synchronously
5. **`x-if="editing"` evaluates to false → input is removed from DOM**
6. Livewire's pending request fails or loses context

**Why text inputs worked:** They weren't inside `x-if`, so they stayed in DOM and `wire:change` completed normally.

**Fix:** Replace `x-if` with `x-show` for the date editing UI.

```blade
{{-- BEFORE: x-if removes element from DOM --}}
<template x-if="editing">
    <input wire:change="updateMappedValue(...)" @blur="editing = false" />
</template>

{{-- AFTER: x-show hides with CSS, element stays in DOM --}}
<input x-show="editing" wire:change="updateMappedValue(...)" @blur="editing = false" />
```

| `x-if` | `x-show` |
|--------|----------|
| Removes element from DOM | Hides with `display: none` |
| Event handlers lost on toggle | Event handlers persist |
| Livewire bindings break | Livewire bindings work |

For inline editing where events must fire after blur, `x-show` is the correct choice.

---

### BF-2. Corrected Dates Still Show "Invalid date" (2026-01-21)

**Problem:** After correcting an invalid date using the date picker, the value still displayed "Invalid date" instead of the corrected date.

**Root Cause:** HTML5 date picker outputs ISO format, but corrections were being parsed with the user's selected input format.

In `review-step.blade.php`:
```php
// BEFORE: Always used user's selected format
$valueToFormat = $mappedValue ?? $rawValue;
$parsed = $dateFormatEnum->parse($valueToFormat);
```

When a correction was saved:
1. HTML5 date picker returns ISO format (`2024-05-15`)
2. Stored as `$mappedValue = '2024-05-15'`
3. User's selected input format is European (`d/m/Y`)
4. European format tries to parse `2024-05-15` → **fails** (expects `15/05/2024`)
5. `$parsed = null` → displays "Invalid date" even though correction exists

**Fix:** Detect when a correction exists and use ISO format to parse it:
```php
// AFTER: Use ISO for corrections, user's format for raw values
if ($hasCorrection && $mappedValue !== null) {
    $parseFormat = \Relaticle\ImportWizardNew\Enums\DateFormat::ISO;
    $valueToFormat = $mappedValue;
} else {
    $parseFormat = $dateFormatEnum;
    $valueToFormat = $rawValue;
}
$parsed = $parseFormat->parse($valueToFormat);
```

**UX Improvement (same commit):** Changed date column interaction pattern:
- **Valid dates:** Now show in an editable input field (direct click to change)
- **Invalid dates:** Show badge + "Fix" button (opens picker)
- **Undo:** "Undo correction" button appears when correction exists

---

### BF-3. Date Picker Click Only Working on Calendar Icon (2026-01-21)

**Problem:** Clicking on the date display area did not open the date picker in Chrome/Safari. Only clicking the tiny calendar icon opened it.

**Root Cause:** WebKit browsers (Chrome, Safari) only make the `::-webkit-calendar-picker-indicator` pseudo-element clickable by default - not the entire input area. The invisible date input had `opacity: 0` but the clickable area was still just the small icon.

**Fix:** Stretch the webkit picker indicator to cover the full input area using Tailwind's arbitrary selector syntax:

```blade
<input
    type="date"
    class="absolute left-0 top-0 w-full h-full opacity-0 cursor-pointer
           [&::-webkit-calendar-picker-indicator]:absolute
           [&::-webkit-calendar-picker-indicator]:left-0
           [&::-webkit-calendar-picker-indicator]:top-0
           [&::-webkit-calendar-picker-indicator]:w-full
           [&::-webkit-calendar-picker-indicator]:h-full
           [&::-webkit-calendar-picker-indicator]:m-0
           [&::-webkit-calendar-picker-indicator]:p-0
           [&::-webkit-calendar-picker-indicator]:cursor-pointer"
/>
```

**Browser Notes:**
- Firefox makes the entire date input clickable by default (no fix needed)
- The fix specifically targets WebKit browsers via the `-webkit-` prefix
- Native picker toggle behavior is standard: opens on click, closes on select/blur/Escape (not on second click)

Applied to both:
- Valid date input (clickable date display area)
- Invalid date input (clickable pencil button area)

---

### 21. Choice Field Handling

**Status:** `IMPLEMENTED`

**Decision:** Choice fields (single_choice, multi_choice) get specialized dropdown UI in ReviewStep with case-insensitive matching and invalid option detection.

#### Design Choices

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Value matching | By option name (case-insensitive) | Human-readable CSVs, no need to know internal IDs |
| Invalid handling | Warning badge + select dropdown to fix | Consistent with date handling pattern |
| Multi-choice delimiter | Comma-separated | Most common CSV format |
| Options source | CustomField.options relationship | Single source of truth |

#### Data Flow

```
CSV Upload → MappingStep (field gets type 'single_choice' or 'multi_choice')
    ↓
ReviewStep mounts → detects choice type via dataType
    ↓
Choice columns show select-menu instead of text input
    ↓
Invalid values show warning badge + dropdown to select valid option
    ↓
User selects new value → updateMappedValue() saves correction
    ↓
Import uses corrected value for database storage
```

#### ReviewStep Component Changes

**New computed properties:**
```php
#[Computed]
public function isSelectedColumnChoiceType(): bool
{
    return in_array($this->selectedColumnAnalysis()?->dataType, ['single_choice', 'multi_choice'], true);
}

#[Computed]
public function isSelectedColumnMultiChoice(): bool
{
    return $this->selectedColumnAnalysis()?->dataType === 'multi_choice';
}

#[Computed]
public function selectedColumnOptions(): array
{
    // Returns [{value: 'Option Name', label: 'Option Name'}, ...]
    // Loaded from CustomField::with('options') by field code
}
```

#### ColumnAnalysisResult Enhancements

```php
public function isChoiceField(): bool
{
    return in_array($this->dataType, ['single_choice', 'multi_choice'], true);
}

public function isMultiChoice(): bool
{
    return $this->dataType === 'multi_choice';
}
```

#### UI Changes (review-step.blade.php)

**Valid choice values:**
- Bordered container with select-menu dropdown
- Pre-selected to current value
- Undo + Skip action buttons

**Invalid choice values:**
- Neutral bordered container + warning icon (subtle indicator)
- Select dropdown to choose valid replacement
- Skip button to exclude value
- **Styling:** Same neutral gray borders/backgrounds as valid rows; warning icon alone signals invalid state

**Multi-choice handling:**
- Input: Comma-separated values (e.g., "Option1, Option2")
- Storage: Individual values joined with comma
- Display: Multi-select dropdown

#### Key Implementation Details

1. **Options loaded via CustomFields facade:**
   ```php
   CustomFields::customFieldModel()::query()
       ->withoutGlobalScopes()
       ->where('tenant_id', $teamId)
       ->where('code', $fieldCode)
       ->with('options')
       ->first();
   ```

2. **Field key extraction:** Custom field keys are prefixed with `custom_fields_`, so code is extracted with `Str::after($fieldKey, 'custom_fields_')`

3. **Alpine.js bridge for select-menu:** Uses `x-data` with `$watch` to call `$wire.updateMappedValue()` when selection changes

4. **Searchable dropdown:** Enabled when options count > 5 for better UX

#### Files Modified for Choice Field Handling

| File | Changes |
|------|---------|
| `src/Livewire/Steps/ReviewStep.php` | Added choice field computed properties and validation |
| `src/Data/ColumnAnalysisResult.php` | Added `isChoiceField()` and `isMultiChoice()` helpers |
| `resources/views/livewire/steps/review-step.blade.php` | Added choice field UI block with select-menu |

---

### BF-4. Select-Menu x-model Binding Not Working (2026-01-21)

**Problem:** Choice field dropdowns showed the correct pre-selected value, but selecting a new option did not trigger the backend `updateMappedValue()` call. The UI updated but no correction was saved.

**Root Cause:** Alpine's `x-model` directive on a Blade component binds to the LOCAL scope, not the parent scope.

When using:
```blade
<x-select-menu x-model="selected" />
```

Blade merges `x-model="selected"` onto the select-menu's root div, which has its own `x-data`:
```html
<div x-data="{ state: null, get selected() { return this.state; }, ... }" x-model="selected">
```

Alpine evaluates `x-model="selected"` in the LOCAL scope of that element. Since select-menu defines a `selected` getter/setter internally, x-model binds to THAT, not the parent's `selected` property.

**Result:**
1. User selects new value
2. select-menu's internal `state` updates (UI shows new value)
3. x-model updates select-menu's LOCAL `selected` (which just sets `state`)
4. Parent's `selected` is never touched
5. Parent's `$watch('selected', ...)` never fires
6. `$wire.updateMappedValue()` never called

**Fix (3 parts):**

1. **Exclude x-model from merged attributes** in `select-menu.blade.php`:
   ```blade
   {{-- BEFORE --}}
   {{ $attributes->whereDoesntStartWith('wire:model')->merge([...]) }}

   {{-- AFTER --}}
   {{ $attributes->whereDoesntStartWith(['wire:model', 'x-model'])->merge([...]) }}
   ```

2. **Add `$dispatch('input')` in select-menu** when value changes (already done):
   ```js
   select(value) {
       this.selected = value;
       this.$dispatch('input', value);  // Notify parent
   }
   ```

3. **Use explicit `@input` listener** in parent instead of x-model:
   ```blade
   {{-- BEFORE --}}
   <x-select-menu x-model="selected" />

   {{-- AFTER --}}
   <x-select-menu @input="selected = $event.detail; updateValue($event.detail)" />
   ```

**Why this works:**
- `$dispatch('input', value)` creates a CustomEvent with `detail = value`
- `@input` listener on parent catches the event
- `$event.detail` contains the selected value
- Parent's `updateValue()` is called directly, triggering `$wire.updateMappedValue()`

**Files Modified:**
| File | Changes |
|------|---------|
| `resources/views/components/select-menu.blade.php` | Exclude x-model from attributes, add `$dispatch('input')` |
| `app-modules/ImportWizardNew/.../review-step.blade.php` | Replace `x-model` with `@input` handler |

**Key Takeaway:** When using Alpine components inside Blade components, avoid `x-model` on the component invocation. Use explicit event dispatching and `@input` listeners instead.

---

### 22. Unified Validation Architecture

**Status:** `IMPLEMENTED`

**Decision:** Use custom validation rules for imports that leverage custom-fields' ValidationService while adding import-specific rules for dates and choices.

#### Why Import Validation Differs from Form Validation

| Context | Value Type | Validation Mechanism |
|---------|------------|---------------------|
| **Filament Forms** | Option **IDs**, Laravel date strings | Filament auto-validates via `in()` rule; ValidationService rules apply |
| **CSV Import** | Option **names**, ambiguous date formats | Custom rules needed for name matching, format-specific parsing |

**Key insight from research:** Filament's Select component automatically applies `in()` validation against option IDs. But imports receive option **names** (e.g., "High Priority") not IDs (e.g., "01KCCF..."), requiring different validation logic.

#### Architecture

```
BaseImporter.customFields()
    ↓
Uses ValidationService::getValidationRules() → Full Laravel rules (email, max:255, etc.)
    ↓
ImportField stores rules in ->rules property
    ↓
ReviewStep calls ValueValidator::validate()
    ↓
ValueValidator combines:
  - ImportField->rules (from ValidationService)
  - ImportDateRule (format-specific parsing)
  - ImportChoiceRule (case-insensitive name matching)
    ↓
Returns localized error message or null
```

#### New Classes

**ValueValidator** (`src/Support/ValueValidator.php`)
```php
final readonly class ValueValidator
{
    public function validate(
        ImportField $field,
        string $value,
        ?DateFormat $dateFormat = null,
        ?array $choiceOptions = null,
    ): ?string;  // Returns error message or null
}
```

**ImportDateRule** (`src/Rules/ImportDateRule.php`)
```php
// Uses DateFormat::parse() which handles:
// - Ambiguous formats (European vs American)
// - 2-digit years
// - Multiple format variations per type
// Laravel's built-in 'date' rule uses strtotime() which is too permissive
```

**ImportChoiceRule** (`src/Rules/ImportChoiceRule.php`)
```php
// Validates option NAMES (not IDs) with:
// - Case-insensitive matching
// - Multi-select comma-separated value support
// Filament's built-in 'in' rule validates IDs, not names
```

#### BaseImporter Changes

**Before:**
```php
// Only set required/nullable - ignored ValidationService completely
return ImportField::make($key)
    ->rules($isRequired ? ['required'] : ['nullable']);
```

**After:**
```php
// Use full ValidationService rules (email, max:255, etc.)
$rules = $validationService->getValidationRules($customField);
$stringRules = array_filter($rules, fn ($r) => is_string($r)); // Filter object rules

return ImportField::make($key)
    ->rules($stringRules);
```

#### Why Not Just Use ValidationService Directly?

| What ValidationService Provides | What Import Needs Instead |
|--------------------------------|---------------------------|
| `date` rule (strtotime, accepts ANY format) | `ImportDateRule` (format-specific parsing) |
| `in:id1,id2` (validates IDs) | `ImportChoiceRule` (validates names) |
| Email, max, min rules | ✅ Used directly from ValidationService |

#### Translation Support

Added `resources/lang/en/validation.php`:
```php
return [
    'invalid_date' => 'Cannot parse as :format date format.',
    'invalid_choice' => '":value" is not a valid option.',
];
```

#### Files Added/Modified

| File | Action | Purpose |
|------|--------|---------|
| `src/Support/ValueValidator.php` | NEW | Central validation orchestration |
| `src/Rules/ImportDateRule.php` | NEW | Format-specific date validation |
| `src/Rules/ImportChoiceRule.php` | NEW | Case-insensitive option validation |
| `resources/lang/en/validation.php` | NEW | Localized error messages |
| `src/ImportWizardNewServiceProvider.php` | MODIFIED | Register translations |
| `src/Importers/BaseImporter.php` | MODIFIED | Use ValidationService rules |
| `src/Livewire/Steps/ReviewStep.php` | MODIFIED | Use ValueValidator |
| `views/.../value-row-date.blade.php` | MODIFIED | Use backend validation error |
| `views/.../value-row-choice.blade.php` | MODIFIED | Use backend validation error |

---

### 23. Livewire Serialization and Code Simplification

**Status:** `IMPLEMENTED`

**Decision:** Explicit serialization boundary for Spatie Data objects and improved code organization.

#### Problem: Livewire Serializes Data Objects to Arrays

Livewire serializes all public properties between requests. When using Spatie Data objects (like `ColumnAnalysisResult`) as public properties, they become plain arrays after serialization. This caused defensive `instanceof` checks throughout the code:

```php
// BEFORE: Defensive check indicates serialization problem
return $analysis instanceof ColumnAnalysisResult
    ? $analysis
    : ColumnAnalysisResult::from($analysis);
```

#### Solution: Explicit Serialization Boundary

Store arrays in public property, hydrate via computed property:

```php
// Store raw data for Livewire serialization
public array $columnAnalysesData = [];

// Hydrate on access via computed property
#[Computed]
public function columnAnalyses(): array
{
    return array_map(
        fn (array $data): ColumnAnalysisResult => ColumnAnalysisResult::from($data),
        $this->columnAnalysesData
    );
}
```

**Benefits:**
- Explicit about what gets serialized (arrays) vs what gets used (objects)
- No defensive `instanceof` checks needed
- Cleaner `selectedColumnAnalysis()` computed property
- Type-safe throughout the codebase

#### Code Simplifications Applied

| Change | Before | After | Benefit |
|--------|--------|-------|---------|
| Choice field type check | `str_starts_with($fieldKey, 'custom_fields_')` | `$field->isCustomField` | Uses existing property |
| Choice options | Re-queried on every access | Cached in `$cachedChoiceOptions` | Performance |
| Validation rule empty check | `$value === ''` in rules | Removed (ValueValidator handles) | Single responsibility |

#### Files Modified

| File | Changes |
|------|---------|
| `src/Livewire/Steps/ReviewStep.php` | Renamed `$columnAnalyses` → `$columnAnalysesData`, added computed property, caching for choice options, used `ImportField::isCustomField` |
| `src/Rules/ImportDateRule.php` | Removed redundant `$value === ''` check |
| `src/Rules/ImportChoiceRule.php` | Removed redundant `$value === ''` check |

---

### 24. Eliminated ColumnAnalysisResult Data Class

**Status:** `IMPLEMENTED`

**Decision:** Remove the `ColumnAnalysisResult` data class entirely. Access data directly from source objects (`ColumnMapping`, `ImportField`) and fetch stats on-demand.

#### Problem: Data Duplication

`ColumnAnalysisResult` was a "Frankenstein" class that duplicated data from multiple sources:

| Property | Source | Duplication |
|----------|--------|-------------|
| `csvColumn` | `ColumnMapping.source` | Redundant |
| `fieldKey` | `ColumnMapping.target` | Redundant |
| `relationship` | `ColumnMapping.relationship` | Redundant |
| `dateFormat` | `ColumnMapping.dateFormat` | Redundant |
| `fieldLabel` | `ImportField.label` | Redundant |
| `fieldType` | `ImportField.isCustomField` | Redundant |
| `dataType` | `ImportField.type` | Redundant |
| `isRequired` | `ImportField.required` | Redundant |
| `uniqueCount` | Computed from SQLite | **Only unique data** |
| `blankCount` | Computed from SQLite | **Only unique data** |

**Only 2 properties were truly unique:** `uniqueCount` and `blankCount` - these can be fetched on-demand.

#### Solution: Direct Access Pattern

| What | Old Access | New Access |
|------|------------|------------|
| Field label | `$analysis->fieldLabel` | `$this->selectedField?->label` |
| Is date type | `$analysis->isDateOrDateTime()` | `$this->selectedField?->type?->isDateOrDateTime()` |
| Unique count | `$analysis->uniqueCount` | `$this->selectedColumnStats['uniqueCount']` |
| Date format | `$analysis->dateFormat` | `$this->selectedMapping?->dateFormat` |
| CSV column | `$analysis->csvColumn` | `$mapping->source` |
| Target field | `$analysis->fieldKey` | `$mapping->target` |

#### Key Changes

**ColumnAnalyzer simplified:**
```php
// BEFORE: 2 methods returning ColumnAnalysisResult
public function analyzeAllColumns(): Collection<ColumnAnalysisResult>
public function analyzeColumn(ColumnMapping $mapping): ColumnAnalysisResult

// AFTER: 1 method returning simple array
public function getColumnStats(string $csvColumn): array
// Returns: ['uniqueCount' => int, 'blankCount' => int]
```

**ReviewStep simplified:**
```php
// BEFORE: Cached all analyses upfront
public array $columnAnalysesData = [];
public array $columnDateFormats = [];  // Duplicated from mappings

// AFTER: No caching, direct access
#[Computed]
public function selectedMapping(): ?ColumnMapping
#[Computed]
public function selectedField(): ?ImportField
#[Computed]
public function selectedColumnStats(): array  // Fetched on-demand
```

**Blade template simplified:**
```blade
{{-- BEFORE --}}
@foreach ($this->columnAnalyses as $csvColumn => $analysis)
    {{ $analysis->fieldLabel }}
@endforeach

{{-- AFTER --}}
@foreach ($this->mappedColumns as $column)
    {{ $column['label'] }}
@endforeach
```

#### Benefits

1. **Zero data duplication** - Single source of truth for each piece of data
2. **Simpler code** - No intermediate object to construct and maintain
3. **Better Livewire compatibility** - Stats fetched fresh on each render
4. **Fewer lines of code** - ~100 lines removed
5. **Cleaner architecture** - Each class has one responsibility

#### Files Changed

| File | Change |
|------|--------|
| `src/Data/ColumnAnalysisResult.php` | **DELETED** |
| `src/Support/ColumnAnalyzer.php` | Removed `analyzeAllColumns()`, `analyzeColumn()`, importer dependency |
| `src/Livewire/Steps/ReviewStep.php` | Replaced with direct access via computed properties |
| `views/livewire/steps/review-step.blade.php` | Updated to use `mappedColumns` and `selectedColumnStats` |

---

## Next Decisions Needed

1. ~~**Date format handling**: Per-column selection in ReviewStep (ISO, European, American)~~ ✅ IMPLEMENTED (Decision #20)
2. ~~**Unknown choice options**: Show error + select dropdown to fix in ReviewStep~~ ✅ IMPLEMENTED (Decision #21)
3. **Relationship creation**: Should unknown companies be created when importing People?
4. ~~**Date value editing**: Should users be able to manually correct unparseable dates via date picker?~~ ✅ IMPLEMENTED + BF-1 fix
5. **Create option on-the-fly**: Allow users to create new options for unknown values (future enhancement)
6. ~~**Unified validation**: Use ValidationService rules + import-specific rules~~ ✅ IMPLEMENTED (Decision #22)
7. ~~**Livewire serialization**: Explicit boundary for Spatie Data objects~~ ✅ IMPLEMENTED (Decision #23)
