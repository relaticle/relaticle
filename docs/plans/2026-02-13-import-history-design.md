# Import Wizard History — Design Document

## Goal

Add a persistent import history system to the ImportWizard module. Users can view past imports, see results (created/updated/skipped/failed counts), review failed rows, and download failed rows as CSV.

## Current State

The ImportWizard uses a filesystem-based `ImportStore` (meta.json + SQLite) for all state during the wizard flow. The `imports` and `failed_import_rows` tables exist (from Filament migrations) but are unused. Temporary files are cleaned up after 2-24 hours.

## Approach: Replace meta.json with Import Model (Level 3)

Promote the `imports` table to be the source of truth for all import metadata. The Import record is created at the start of the wizard (upload step) and persists after completion. ImportStore is slimmed down to manage only the temporary SQLite database for row data.

## Architecture

```
Import model (PostgreSQL `imports` table)
  All metadata: status, headers, mappings, results, entity_type, etc.
  Relations: user(), team(), failedRows()

ImportStore (filesystem) — slimmed down
  data.sqlite only (temporary row data, cleaned up after completion)

ImportHistory (Filament Page)
  Table listing all imports with drill-down detail view
```

## Database Schema

### Migration: Expand `imports` table

Add columns:

| Column | Type | Purpose |
|--------|------|---------|
| `entity_type` | `string` | ImportEntityType enum value |
| `status` | `string`, default `uploading` | ImportStatus enum value |
| `headers` | `json`, nullable | CSV column headers |
| `column_mappings` | `json`, nullable | Array of ColumnData mappings |
| `results` | `json`, nullable | `{created, updated, skipped, failed}` |
| `failed_rows_data` | `json`, nullable | List of `{row, error}` from execution |
| `created_rows` | `unsigned int`, default 0 | For easy querying/display |
| `updated_rows` | `unsigned int`, default 0 | For easy querying/display |
| `skipped_rows` | `unsigned int`, default 0 | For easy querying/display |

Modify existing columns:
- `file_path` — make nullable (unused, we don't persist uploaded files)
- `importer` — make nullable (we use `entity_type` instead)

### `failed_import_rows` table — no changes

Existing schema is sufficient: `data` (JSON), `validation_error` (text), `import_id` (FK).

## Import Model

Location: `app-modules/ImportWizard/src/Models/Import.php`

### Casts
- `entity_type` → `ImportEntityType`
- `status` → `ImportStatus`
- `headers`, `column_mappings`, `results`, `failed_rows_data` → `array`

### Relations
- `user()` → BelongsTo User
- `team()` → BelongsTo Team
- `failedRows()` → HasMany FailedImportRow

### Scopes
- `completed()`, `failed()`, `importing()`, `forTeam()`

### Methods (migrated from ImportStore)
- `transitionToImporting()` — cache-locked status transition
- `columnMappings()` — returns `Collection<int, ColumnData>`, hydrated with importer fields
- `setColumnMappings()`, `getColumnMapping()`, `updateColumnMapping()`
- `getImporter()` — creates BaseImporter from entity_type
- `storagePath()` — returns filesystem path for this import's SQLite file

## ImportStore Refactor

ImportStore becomes a thin SQLite-only wrapper. All metadata methods are removed.

### Remaining methods
- `connection()` — SQLite connection
- `query()` — ImportRow query builder
- `ensureProcessedColumn()`
- `create(importId)` — creates SQLite file
- `load(importId)` — opens existing SQLite
- `destroy()` — deletes SQLite file/directory

### Removed methods
- `meta()`, `writeMeta()`, `updateMeta()`, `refreshMeta()`
- `status()`, `setStatus()`, `entityType()`, `teamId()`, `userId()`
- `headers()`, `setHeaders()`, `rowCount()`, `setRowCount()`
- `results()`, `setResults()`, `failedRows()`
- `columnMappings()`, `setColumnMappings()`, `getColumnMapping()`, `updateColumnMapping()`
- `getImporter()`, `transitionToImporting()`

## Component Changes

### WithImportStore trait → WithImport

Provides both:
- `$this->import` — Import Eloquent model (metadata)
- `$this->store` — ImportStore (SQLite row data)

Both loaded lazily from the same ULID.

### UploadStep
- Before: `ImportStore::create()` writes meta
- After: `Import::create()` in DB, then `ImportStore::create($import->id)` for SQLite

### MappingStep
- Before: `$store->columnMappings()`
- After: `$import->columnMappings()`

### ReviewStep
- Before: `$store->meta()`, `$store->updateMeta()`
- After: `$import->column_mappings`, `$import->update()`

### PreviewStep
- Before: `$store->status()`, `$store->results()`
- After: `$import->status`, `$import->results`

### ExecuteImportJob
- Before: `ImportStore::load()`, `$store->setStatus()`, `$store->setResults()`
- After: `Import::findOrFail()`, `$import->update(...)`, write failed rows to `failed_import_rows` table

### ValidateColumnJob / ResolveMatchesJob
- Before: `$store->teamId()`, `$store->getImporter()`
- After: `$import->team_id`, `$import->getImporter()`

## Filament Import History Page

Location: `app-modules/ImportWizard/src/Filament/Pages/ImportHistory.php`

### Table columns
- Entity type (icon + label)
- Original filename
- Status (color-coded badge)
- Total rows
- Created / Updated / Skipped / Failed counts
- User (avatar + name)
- Date (relative)

### Features
- Filters: entity type, status, date range
- Sort: newest first (default)
- Search: by filename
- Row action: view details (slide-over)

### Detail view (slide-over)
- Import summary card
- Action: download failed rows as CSV (if any failures)

### Navigation
- Sidebar item with `heroicon-o-clock` icon
- Visible to users who can access any import page

## Failed Rows CSV Download

- Signed route registered in ImportWizardNewServiceProvider
- Streams CSV with UTF-8 BOM
- Columns: all data keys from failed row JSON + error column
- Chunked output via `lazyById(100)` for memory efficiency

## Cleanup Command Update

`CleanupImportsCommand` changes:
- Clean up SQLite files for completed/failed imports (filesystem only)
- DB records persist (they are the history)
- `failed_import_rows` auto-prunes via Prunable trait (1 month)
- Optional: configurable retention period for old Import records

## Out of Scope (v1)

- Rollback/undo capability
- Re-import from history
- Delete import history entries
- Row-level tracking (which specific records were created/updated)
- Import scheduling
