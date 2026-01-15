# ImportWizard - Technical Architecture

> Written from a pragmatic senior architect's perspective.
> Focus: Clarity, maintainability, and honest assessment of trade-offs.

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Component Architecture](#2-component-architecture)
3. [Data Flow](#3-data-flow)
4. [State Management](#4-state-management)
5. [Cache Strategy](#5-cache-strategy)
6. [Class Responsibilities](#6-class-responsibilities)
7. [Database Schema](#7-database-schema)
8. [API Contracts](#8-api-contracts)
9. [Background Jobs](#9-background-jobs)
10. [Security Model](#10-security-model)
11. [Performance Optimizations](#11-performance-optimizations)
12. [Extension Points](#12-extension-points)
13. [Known Technical Debt](#13-known-technical-debt)
14. [Testing Strategy](#14-testing-strategy)

---

## 1. System Overview

### Technology Stack

| Layer | Technology | Version |
|-------|------------|---------|
| Frontend | Livewire 4 + Alpine.js | Livewire state + Alpine for heavy lists |
| Backend | Laravel 12 | PHP 8.4 |
| Data Layer | Spatie Laravel Data | Typed DTOs |
| File Parsing | League CSV | Streaming reader |
| Cache | Laravel Cache | Redis/File |
| Queue | Laravel Horizon | Redis-backed |

### Architecture Pattern

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Livewire Component                           │
│                        (ImportWizard.php)                           │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌─────────────┐│
│  │HasCsvParsing │ │HasColumnMap  │ │HasValueAnalys│ │HasImportPrev││
│  │    trait     │ │   trait      │ │    trait     │ │    trait    ││
│  └──────────────┘ └──────────────┘ └──────────────┘ └─────────────┘│
└─────────────────────────────────────────────────────────────────────┘
                              │
          ┌───────────────────┼───────────────────┐
          ▼                   ▼                   ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│   REST API      │ │   Support       │ │   Background    │
│   Controllers   │ │   Services      │ │   Jobs          │
└─────────────────┘ └─────────────────┘ └─────────────────┘
```

### Design Principles Applied

1. **Cache for scale** - Large datasets in Cache, not Livewire state
2. **Streaming over loading** - Never load full CSV into memory
3. **Background for heavy work** - Preview/Import use queued jobs
4. **API for performance** - Step 3 uses REST to bypass Livewire sync

---

## 2. Component Architecture

### Directory Structure

```
app-modules/ImportWizard/
├── config/
│   └── import-wizard.php           # Configuration
├── resources/views/
│   └── livewire/
│       ├── import-wizard.blade.php # Main component view
│       └── partials/               # Step-specific views
├── routes/
│   └── web.php                     # API routes
└── src/
    ├── Console/Commands/           # Cleanup commands
    ├── Data/                       # Spatie Data DTOs
    ├── Enums/                      # DateFormat, TimestampFormat, etc.
    ├── Filament/
    │   ├── Imports/               # Entity importers
    │   └── Pages/                 # Filament pages
    ├── Http/Controllers/          # REST API controllers
    ├── Jobs/                      # Background jobs
    ├── Livewire/
    │   ├── ImportWizard.php      # Main component (898 lines)
    │   └── Concerns/             # Trait-based organization
    ├── Models/                    # Import, FailedImportRow
    └── Support/                   # Services and utilities
```

### Component Responsibilities

| Component | Lines | Responsibility |
|-----------|-------|----------------|
| `ImportWizard` | 898 | Orchestration, step navigation, actions |
| `HasCsvParsing` | 141 | File upload, CSV parsing, persistence |
| `HasColumnMapping` | 472 | Auto-mapping, field detection, relationship setup |
| `HasValueAnalysis` | 582 | Column analysis, corrections, format selection |
| `HasImportPreview` | 206 | Preview generation, counts, row display |

---

## 3. Data Flow

### Step 1: Upload

```
User uploads file
       │
       ▼
┌─────────────────────┐
│ Livewire file       │
│ validation          │
│ (max:51200, mimes)  │
└─────────────────────┘
       │
       ▼
┌─────────────────────┐
│ parseUploadedFile() │
│ - Create session ID │
│ - Parse headers     │
│ - Count rows        │
│ - Validate limits   │
└─────────────────────┘
       │
       ▼
┌─────────────────────┐
│ persistFile()       │
│ - Save to temp      │
│   storage/temp-     │
│   imports/{session} │
└─────────────────────┘
```

### Step 2: Map Columns

```
prepareForMapping()
       │
       ▼
┌─────────────────────┐
│ autoMapColumns()    │
│ Phase 1: Header     │
│   matching          │
│ Phase 2: Relations  │
│ Phase 3: Type       │
│   inference         │
└─────────────────────┘
       │
       ▼
┌─────────────────────┐
│ Store in state:     │
│ - $columnMap        │
│ - $relationshipMap  │
│ - $inferredMappings │
└─────────────────────┘
```

### Step 3: Review Values

```
prepareForReview()
       │
       ▼
┌─────────────────────────────────────────────────────────┐
│ analyzeColumns() → CsvAnalyzer::analyze()               │
│                                                         │
│  CSV File ──► Single-pass read ──► For each column:     │
│                                    │                    │
│                                    ├─► Collect unique   │
│                                    │   values           │
│                                    │                    │
│                                    ├─► Validate per     │
│                                    │   field type       │
│                                    │                    │
│                                    └─► Detect date      │
│                                        format           │
└─────────────────────────────────────────────────────────┘
       │
       ▼
┌─────────────────────────────────────────────────────────┐
│ Store results:                                          │
│                                                         │
│ Livewire State:        Cache:                          │
│ - $columnAnalysesData  - import:{session}:values:{col} │
│   (counts only)        - import:{session}:analysis:{col}│
│                        - import:{session}:corrections   │
└─────────────────────────────────────────────────────────┘
       │
       ▼
┌─────────────────────────────────────────────────────────┐
│ Alpine.js valueReviewer                                 │
│                                                         │
│ User interactions:                                      │
│ - Load values ──► POST /import/values                   │
│ - Correct ──────► POST /import/corrections              │
│ - Skip ─────────► POST /import/corrections (value='')  │
│ - Un-skip ──────► DELETE /import/corrections            │
└─────────────────────────────────────────────────────────┘
```

### Step 4: Preview & Import

```
prepareForPreview()
       │
       ▼
┌─────────────────────────────────────────────────────────┐
│ generateImportPreview()                                 │
│                                                         │
│ Sync (first 50 rows):                                   │
│   PreviewChunkService::processChunk()                   │
│   └─► Apply corrections                                 │
│   └─► Resolve records (create vs update)                │
│   └─► Match relationships                               │
│   └─► Write enriched CSV                                │
│                                                         │
│ Async (remaining rows):                                 │
│   dispatch(ProcessImportPreview job)                    │
└─────────────────────────────────────────────────────────┘
       │
       ▼
┌─────────────────────────────────────────────────────────┐
│ executeImport()                                         │
│                                                         │
│ 1. moveFileToPermanentStorage()                         │
│    └─► Apply corrections if any                         │
│    └─► Save to storage/imports/{uuid}.csv               │
│                                                         │
│ 2. Create Import model                                  │
│                                                         │
│ 3. calculateOptimalChunkSize()                          │
│    └─► Base: 500/250/150/75 by column count             │
│    └─► Custom field penalty: -20% per 5 fields          │
│    └─► Entity penalty: opportunities=0.8x               │
│                                                         │
│ 4. dispatchImportJobs()                                 │
│    └─► Batch of StreamingImportCsv jobs                 │
│    └─► Each job: startRow, rowCount (not data!)         │
└─────────────────────────────────────────────────────────┘
```

---

## 4. State Management

### The Problem

Large imports can have:
- 10,000 rows
- 1,000+ unique values per column
- 100+ validation issues

Serializing this in Livewire state causes `PayloadTooLargeException`.

### The Solution

**Hybrid state model:**

| Data Type | Storage | Why |
|-----------|---------|-----|
| Step navigation | Livewire state | Small, UI-critical |
| Column mappings | Livewire state | Small, needed for rendering |
| Issue counts | Livewire state | Tiny, for badges |
| Unique values | Cache | Can be huge |
| Full issues | Cache | Can be huge |
| Corrections | Both | State for UI, Cache for API |

### State Properties

```php
// ImportWizard.php - Livewire state

// Small, always serialized
public int $currentStep = 1;
public array $csvHeaders = [];           // ~50 items max
public array $columnMap = [];            // ~50 items max
public array $selectedDateFormats = [];  // ~10 items max

// Serialized but minimal (counts only, not full data)
public array $columnAnalysesData = [];   // Contains counts, not values

// UI state
public ?string $expandedColumn = null;
public int $reviewPage = 1;
```

---

## 5. Cache Strategy

### Cache Key Convention

```
import:{sessionId}:{type}:{identifier}
```

| Key Pattern | Contents | TTL |
|-------------|----------|-----|
| `import:{session}:values:{csvColumn}` | `array<string, int>` unique values | 24h |
| `import:{session}:analysis:{csvColumn}` | Full analysis with issues | 24h |
| `import:{session}:corrections:{fieldName}` | `array<string, string>` corrections | 24h |
| `import:{session}:preview` | `ImportSessionData` progress | 24h |

### Session ID Generation

```php
$this->sessionId = Str::uuid()->toString();
```

Generated once per import session, used as cache namespace.

### Cache Access Pattern

```php
// Write (in HasValueAnalysis::analyzeColumns)
Cache::put("import:{$sessionId}:values:{$csvColumn}", $uniqueValues, now()->addHours(24));

// Read (in ImportValuesController)
$values = Cache::get("import:{$sessionId}:values:{$csvColumn}", []);
```

### Known Issue: Duplication

Cache keys are hardcoded strings in multiple files:
- `HasValueAnalysis.php` (3 places)
- `ImportValuesController.php` (3 places)
- `ImportCorrectionsController.php` (3 places)

**Recommendation:** Extract to `ImportCacheKeys` utility class.

---

## 6. Class Responsibilities

### Support Classes

| Class | LOC | Single Responsibility |
|-------|-----|----------------------|
| `CsvAnalyzer` | 527 | Validate all columns in single CSV pass |
| `DateValidator` | 165 | Validate date values against format |
| `TimestampValidator` | 102 | Validate datetime values against format |
| `DataTypeInferencer` | 298 | Guess field type from sample values |
| `PreviewChunkService` | 314 | Process rows for preview |
| `CompanyMatcher` | ~150 | Match companies by domain/name |
| `ImportRecordResolver` | ~200 | Pre-load records for fast lookups |
| `CsvReaderFactory` | ~50 | Create configured CSV readers |

### Importer Classes

| Class | Extends | Unique Features |
|-------|---------|-----------------|
| `BaseImporter` | Filament Importer | ID resolution, custom field hook |
| `CompanyImporter` | BaseImporter | Domain-based duplicate detection |
| `PeopleImporter` | BaseImporter | Email/phone duplicate detection, company relationship |
| `OpportunityImporter` | BaseImporter | Company/contact relationships |
| `TaskImporter` | BaseImporter | Polymorphic entity link |
| `NoteImporter` | BaseImporter | Polymorphic entity link |

### Data Classes (DTOs)

| Class | Purpose |
|-------|---------|
| `ColumnAnalysis` | Analysis results for one column |
| `ValueIssue` | Single validation error/warning |
| `ImportSessionData` | Preview progress tracking |
| `RelationshipField` | Relationship definition |
| `RelationshipMatcher` | How to match a relationship |

---

## 7. Database Schema

### `imports` Table

```sql
CREATE TABLE imports (
    id CHAR(26) PRIMARY KEY,           -- ULID
    team_id CHAR(26) NOT NULL,
    user_id CHAR(26) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,   -- storage/imports/{uuid}.csv
    importer VARCHAR(255) NOT NULL,    -- Class name
    total_rows INT UNSIGNED NOT NULL,
    processed_rows INT UNSIGNED DEFAULT 0,
    successful_rows INT UNSIGNED DEFAULT 0,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### `failed_import_rows` Table

```sql
CREATE TABLE failed_import_rows (
    id CHAR(26) PRIMARY KEY,
    import_id CHAR(26) NOT NULL,
    row_number INT UNSIGNED NOT NULL,
    data JSON NOT NULL,               -- Original row data
    validation_error TEXT NULL,
    created_at TIMESTAMP
);
```

### Relationships

```
Team ──┬── Import ──┬── FailedImportRow
       │            │
       └── User ────┘
```

---

## 8. API Contracts

### POST `/app/import/values`

Fetch paginated values for a column.

**Request:**
```json
{
    "session_id": "uuid",
    "csv_column": "Company Name",
    "field_name": "name",
    "page": 1,
    "per_page": 100,
    "errors_only": false,
    "date_format": "european"
}
```

**Response:**
```json
{
    "values": [
        {
            "value": "Acme Corp",
            "count": 5,
            "issue": null,
            "isSkipped": false,
            "correctedValue": null,
            "parsedDate": null
        }
    ],
    "hasMore": true,
    "total": 150,
    "showing": 100
}
```

### POST `/app/import/corrections`

Store a correction.

**Request:**
```json
{
    "session_id": "uuid",
    "field_name": "custom_fields_date",
    "old_value": "15/05/2024",
    "new_value": "2024-05-15",
    "csv_column": "Date Column"
}
```

**Response:**
```json
{
    "success": true,
    "isSkipped": false,
    "issue": null
}
```

### DELETE `/app/import/corrections`

Remove a correction (unskip).

**Request:**
```json
{
    "session_id": "uuid",
    "field_name": "name",
    "old_value": "Bad Value"
}
```

**Response:**
```json
{
    "success": true
}
```

---

## 9. Background Jobs

### Job: `StreamingImportCsv`

Processes a range of rows from the import CSV.

**Constructor:**
```php
public function __construct(
    public Import $import,
    public int $startRow,
    public int $rowCount,
    public array $columnMap,
    public array $options,
) {}
```

**Key Design Decisions:**

1. **Passes row range, not data** - Reduces payload from ~100KB to ~500 bytes
2. **Reads from persistent file** - File at `$import->file_path`
3. **Uses Laravel Batch** - Parallel processing with progress tracking

**Execution Flow:**
```
Job receives: startRow=100, rowCount=50
       │
       ▼
Open CSV at $import->file_path
       │
       ▼
Skip to row 100 using Statement::offset()
       │
       ▼
Process 50 rows:
  - Create importer instance
  - For each row:
    - remapData()
    - castData()
    - resolveRecord() → find or create
    - fillRecord()
    - saveRecord()
    - afterSave() → custom fields
       │
       ▼
Update $import->processed_rows
```

### Job: `ProcessImportPreview`

Processes remaining rows for preview (after sync first 50).

**Uses ImportSessionData for progress:**
```php
ImportSessionData::updateOrCreate($sessionId, [
    'processedRows' => $count,
    'creates' => $creates,
    'updates' => $updates,
]);
```

---

## 10. Security Model

### Team Isolation

Every query includes `team_id`:

```php
// In BaseImporter::resolveById()
$record = $modelClass::query()
    ->where('id', $id)
    ->where('team_id', $this->import->team_id)  // Always!
    ->first();
```

### Session Ownership

Cache keys namespaced by session ID (UUID).
No cross-session access possible.

### File Access

- Temp files: `storage/temp-imports/{session}/`
- Import files: `storage/imports/{uuid}.csv`
- Auto-cleanup after 24 hours

### Input Validation

```php
// Controller validation
$validated = $request->validate([
    'session_id' => ['required', 'string'],
    'csv_column' => ['required', 'string'],
    // ...
]);
```

### CSRF Protection

All API routes require CSRF token:
```javascript
headers: {
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
}
```

---

## 11. Performance Optimizations

### 1. Single-Pass CSV Reading

```php
// CsvAnalyzer::analyze() - One iteration, multiple validations
foreach ($csvReader->getRecords() as $record) {
    foreach ($mappedColumns as $fieldName => $csvColumn) {
        $value = $record[$csvColumn] ?? '';
        $this->collectValue($fieldName, $value);
        $this->validateValue($fieldName, $value);
    }
}
```

### 2. Pre-Loaded Record Resolver

For preview mode, pre-load all potential matches:

```php
// ImportRecordResolver::loadForTeam()
$this->companies = Company::where('team_id', $teamId)
    ->get()
    ->keyBy('id');

$this->companyDomains = // Index by domain...
$this->peopleEmails = // Index by email...
```

Then O(1) lookups during preview instead of O(n) queries.

### 3. Chunked Job Processing

Dynamic chunk sizing based on complexity:

| Column Count | Base Chunk Size |
|--------------|-----------------|
| ≤ 5 | 500 rows |
| ≤ 10 | 250 rows |
| ≤ 20 | 150 rows |
| > 20 | 75 rows |

Additional penalties:
- Custom fields: -20% per 5 fields
- Opportunities: 0.8x multiplier

### 4. Pagination with Infinite Scroll

Values loaded on-demand, not all at once:
- Initial load: 100 values
- Scroll trigger: Load 100 more
- Total available: Shown in UI

### 5. Streaming CSV Writing

Enriched preview CSV written incrementally:
```php
fputcsv($handle, $row);  // Write immediately, don't buffer
```

---

## 12. Extension Points

### Adding a New Entity Importer

1. Create importer class:
```php
final class NewEntityImporter extends BaseImporter
{
    protected static ?string $model = NewEntity::class;
    protected static array $uniqueIdentifierColumns = ['id'];

    public static function getColumns(): array { ... }
    public function resolveRecord(): NewEntity { ... }
}
```

2. Register in `ImportWizard::getEntities()`:
```php
'new_entities' => [
    'label' => 'New Entities',
    'importer' => NewEntityImporter::class,
],
```

3. Create Filament page extending `BaseImportPage`.

### Adding a New Relationship Matcher

1. Define in `RelationshipField`:
```php
public static function newRelation(): self
{
    return new self(
        name: 'relation',
        matchers: RelationshipMatcher::collection([
            new RelationshipMatcher(key: 'id', ...),
            new RelationshipMatcher(key: 'custom', ...),
        ]),
    );
}
```

2. Create `HasNewRelationColumns` trait with `ImportColumn` definitions.

### Adding a New Date Format

1. Add case to `DateFormat` enum
2. Implement `getPhpFormats()`, `getPattern()`, etc.
3. Update `DataTypeInferencer::detectDateFormat()`

---

## 13. Known Technical Debt

### Critical

| Issue | Location | Impact |
|-------|----------|--------|
| **Incomplete API controller** | `ImportValuesController` | Missing `validateValue()`, `parseDatePreview()` methods |

### High

| Issue | Location | Impact |
|-------|----------|--------|
| Duplicated validation logic | 4+ files | Maintenance burden |
| Hardcoded cache keys | 6+ places | Error-prone changes |
| Large trait files | `HasValueAnalysis` 582 LOC | Hard to navigate |

### Medium

| Issue | Location | Impact |
|-------|----------|--------|
| Two value fetching implementations | Livewire + API | Divergent behavior risk |
| Corrections in state AND cache | Multiple | Sync complexity |
| No service layer | Throughout | Direct coupling |

### Recommended Refactoring

1. **Create `ImportSessionService`** - Centralize cache operations
2. **Create `ValueValidationService`** - Single validation implementation
3. **Extract cache keys to constants** - `ImportCacheKeys` class
4. **Consolidate value fetching** - One implementation, two entry points

---

## 14. Testing Strategy

### Unit Tests

| Target | Coverage Goal |
|--------|---------------|
| `DateFormat` enum | 100% - parse, format, ambiguity detection |
| `TimestampFormat` enum | 100% - parse, format |
| `DateValidator` | 100% - all formats, edge cases |
| `TimestampValidator` | 100% - all formats |
| `DataTypeInferencer` | 90% - type detection |

### Feature Tests

| Scenario | Priority |
|----------|----------|
| Full import flow (happy path) | P0 |
| File upload validation | P0 |
| Auto-mapping accuracy | P1 |
| Correction application | P1 |
| Duplicate detection per entity | P1 |
| Relationship matching | P1 |
| Background job processing | P1 |

### Integration Tests

| Scenario | Priority |
|----------|----------|
| API endpoint responses | P0 |
| Cache read/write | P1 |
| Filament page rendering | P1 |

### Test Data Strategy

```php
// Use factories with known data
$company = Company::factory()
    ->for($team)
    ->withDomain('acme.com')
    ->create();

$person = People::factory()
    ->for($team)
    ->withEmail('test@acme.com')
    ->for($company)
    ->create();
```

### Browser Tests (Pest 4)

```php
it('can complete import wizard', function () {
    $this->actingAs($user);

    visit('/import/companies')
        ->attach('uploadedFile', $csvPath)
        ->waitFor('[data-step="map"]')
        ->click('Next')
        ->waitFor('[data-step="review"]')
        // ...
});
```

---

## Appendix A: File Inventory

| File | Lines | Purpose |
|------|-------|---------|
| `ImportWizard.php` | 898 | Main Livewire component |
| `HasValueAnalysis.php` | 582 | Value analysis trait |
| `CsvAnalyzer.php` | 527 | CSV validation service |
| `HasColumnMapping.php` | 472 | Column mapping trait |
| `PreviewChunkService.php` | 314 | Preview processing |
| `DataTypeInferencer.php` | 298 | Type detection |
| `ColumnAnalysis.php` | 228 | Analysis DTO |
| `HasImportPreview.php` | 206 | Preview trait |
| `DateFormat.php` | 207 | Date format enum |
| `TimestampFormat.php` | 185 | Timestamp format enum |
| `DateValidator.php` | 165 | Date validation |
| `RelationshipField.php` | 157 | Relationship DTO |
| `ImportValuesController.php` | 149 | Values API |
| `ImportCorrectionsController.php` | 154 | Corrections API |
| `HasCsvParsing.php` | 141 | CSV parsing trait |
| `TimestampValidator.php` | 102 | Timestamp validation |

**Total:** ~8,700 lines

---

## Appendix B: Configuration Reference

```php
// config/import-wizard.php

return [
    'max_rows_per_file' => 10000,
    'session_ttl_hours' => 24,
    'public_email_domains' => [
        'enabled' => true,
        'path' => null,  // Custom domain list path
    ],
];
```

---

## Appendix C: Quick Reference

### Cache Keys
```
import:{session}:values:{csvColumn}
import:{session}:analysis:{csvColumn}
import:{session}:corrections:{fieldName}
import:{session}:preview
```

### File Paths
```
storage/temp-imports/{session}/original.csv
storage/imports/{uuid}.csv
```

### API Routes
```
POST   /app/import/values
POST   /app/import/corrections
DELETE /app/import/corrections
GET    /app/import/{session}/status
GET    /app/import/{session}/rows
```
