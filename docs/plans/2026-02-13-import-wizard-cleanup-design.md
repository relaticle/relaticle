# ImportWizard Cleanup Design

Production SaaS import wizard used by 2000+ paid users daily. This design covers data model cleanup, job safety hardening, and UX state fixes across three independent, deployable phases.

## Current State

The `imports` table has 21 columns. Six are dead (written but never read), and one needed column (`failed_rows`) is missing. The `ExecuteImportJob` has no `failed()` handler, writes `FailedImportRow` records only on the success path, and silently caps stored errors at 100. Navigation edge cases allow backward movement during async validation.

## Phase 1: Data Model Cleanup

### Migration: Drop Dead Columns, Add `failed_rows`

Drop from `imports` table:

| Column | Why Dead | Replaced By |
|--------|----------|-------------|
| `file_path` | Never read | `Import::storagePath()` derives from ULID |
| `importer` | Never read | `entity_type` enum with `importerClass()` |
| `processed_rows` | Never read | Derivable: `sum(*_rows)` |
| `successful_rows` | Never read | Derivable: `created_rows + updated_rows` |
| `failed_rows_data` | Never read | `FailedImportRow` records cover this |
| `results` | Duplicates `*_rows` columns | Update `*_rows` every chunk instead |

Add to `imports` table:

| Column | Type | Why |
|--------|------|-----|
| `failed_rows` | unsignedInteger, default 0 | Completes `created/updated/skipped/failed` symmetry |

### Final `imports` Schema (15 columns)

```
imports
├── id              ULID, PK
├── team_id         FK → teams, nullable, cascadeOnDelete
├── user_id         FK → users, cascadeOnDelete
├── file_name       string
├── entity_type     string, nullable          (enum: ImportEntityType)
├── status          string, default 'uploading' (enum: ImportStatus)
├── headers         JSON, nullable
├── column_mappings JSON, nullable
├── total_rows      unsignedInteger
├── created_rows    unsignedInteger, default 0
├── updated_rows    unsignedInteger, default 0
├── skipped_rows    unsignedInteger, default 0
├── failed_rows     unsignedInteger, default 0
├── completed_at    timestamp, nullable
└── timestamps      (created_at, updated_at)
```

### `failed_import_rows` Schema (unchanged)

```
failed_import_rows
├── id               ULID, PK
├── team_id          FK → teams, nullable, cascadeOnDelete
├── import_id        FK → imports, cascadeOnDelete
├── data             JSON
├── validation_error text, nullable
└── timestamps
```

### Design Decision: `entity_type` over `importer`

Filament stores `importer` as a FQCN string because it's a generic framework. Our app has a fixed set of entity types (Company, People, Opportunity, Task, Note) that are core product concepts. The `ImportEntityType` enum provides `label()`, `icon()`, `singular()`, and `importerClass()` — all type-safe. Renaming an importer class won't break stored data.

### Design Decision: `file_name` over `file_path`

Our architecture parses CSV into per-import SQLite at upload time. The job reads from SQLite, not the CSV file. There is no stored CSV to reference via `file_path`. `file_name` is needed for display in ImportHistory.

### Design Decision: Drop `results` JSON

The `results` JSON stored `{created, updated, skipped, failed}` — identical to the four `*_rows` integer columns. Instead of maintaining both, update `*_rows` columns every 500-row chunk during job execution. PreviewStep reads individual columns for progress. Zero duplication.

### Code Changes

**ExecuteImportJob:**
- Remove writes to: `processed_rows`, `successful_rows`, `failed_rows_data`, `results`
- Add `failed_rows` write alongside other `*_rows` columns
- Update `persistResults()` to write `*_rows` columns directly
- Delete `failedRowsSummary()` method

**UploadStep:**
- Remove `file_path` and `importer` from Import creation

**Import Model:**
- Remove casts for dropped columns
- Add `'failed_rows' => 'integer'` cast
- Remove `failedRowsSummary`-related code

**PreviewStep:**
- Change progress calculation from `$import->results` to `$import->created_rows + updated_rows + skipped_rows + failed_rows`

**ImportHistory:**
- Add `failed_rows` TextColumn to table

## Phase 2: Job Safety

### Problem: No `failed()` handler

If `ExecuteImportJob` exhausts 3 retries, the import stays permanently stuck in `Importing` status. No user notification, no cleanup.

**Fix:** Add `failed(\Throwable $exception)` method that:
- Marks import as `ImportStatus::Failed`
- Writes accumulated `FailedImportRow` records to DB
- Sends failure notification to user

### Problem: Failed rows only written on success path

`writeFailedRowsToDb()` runs after the main try block. If the job throws, `FailedImportRow` records are never created. User sees "Failed" but has no downloadable error details.

**Fix:** Write `FailedImportRow` records incrementally during execution, flushed every chunk alongside `persistResults()`. This way:
- Failures are persisted as they happen
- Job crash at row 5000 still has rows 1-5000's failures in DB
- `failed()` handler can write any remaining buffered failures

### Problem: Silent 100-error cap

`MAX_STORED_ERRORS = 100` silently discards errors beyond the 100th. `results['failed']` counter shows 500, but only 100 records exist. Users see a count mismatch.

**Fix:** Remove the in-memory cap. With incremental DB writes, memory pressure is no longer a concern — failures are flushed to `FailedImportRow` periodically, not accumulated in memory.

### Problem: Job retry idempotency

The job already queries `where('processed', false)` rows on retry, so data processing is idempotent. With incremental `FailedImportRow` writes, failure tracking is also safe across retries.

## Phase 3: UX / State Fixes

### Problem: Backward navigation during validation

User on ReviewStep can navigate back to MappingStep while validation batches are still running. Old batches continue against stale mappings while new batches start.

**Fix:** Block `goBack()` when ReviewStep validation is active. Check via Import status or batch tracking. Show user feedback: "Validation in progress, please wait."

### Problem: ImportHistory missing failed count

The table shows created/updated/skipped but not failed.

**Fix:** Add `TextColumn::make('failed_rows')` with danger color (enabled by Phase 1's new column).

### Problem: `persistResults()` simplification

With `results` JSON dropped, the method updates individual columns:

```php
private function persistResults(Import $import, array $results): void
{
    $import->update([
        'created_rows' => $results['created'],
        'updated_rows' => $results['updated'],
        'skipped_rows' => $results['skipped'],
        'failed_rows' => $results['failed'],
    ]);
}
```

## Deployment Strategy

Three independent phases, each a separate PR:

1. **Phase 1** (Data Model) — Migration + code changes. Safe to deploy: removes dead writes, adds a column.
2. **Phase 2** (Job Safety) — Behavioral changes to ExecuteImportJob. Requires Phase 1 (`failed_rows` column).
3. **Phase 3** (UX/State) — Navigation guards + ImportHistory improvements. Independent of Phase 2.

Each phase can be tested and rolled back independently. Phase 2 depends on Phase 1. Phase 3 can ship in any order after Phase 1.

## Out of Scope

- Queue worker scaling / concurrency optimization
- Real-time progress via WebSockets (polling is adequate for current scale)
- Import retry/resume from ImportHistory page
- Batch cancellation mechanism
