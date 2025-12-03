# Import Center - Deep Analysis

## Overview

The Import Center is a data migration system that allows users to import records from CSV or Excel files into Relaticle CRM. It supports five entity types: Companies, People, Opportunities, Tasks, and Notes.

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              Import Center Page                              │
│                        (app/Filament/Pages/ImportCenter.php)                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐  ┌──────────────────┐  ┌─────────────────────────────┐   │
│  │ Quick Import │  │  Import History  │  │     Migration Wizard        │   │
│  │     Tab      │  │       Tab        │  │          Tab                │   │
│  └──────┬───────┘  └────────┬─────────┘  └──────────────┬──────────────┘   │
│         │                   │                           │                   │
└─────────┼───────────────────┼───────────────────────────┼───────────────────┘
          │                   │                           │
          ▼                   ▼                           ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────────────┐
│ EnhancedImport  │  │  Import Table   │  │       MigrationWizard           │
│     Action      │  │ (Filament's     │  │ (Livewire Component)            │
│                 │  │  Import model)  │  │                                 │
└────────┬────────┘  └─────────────────┘  └────────────────┬────────────────┘
         │                                                  │
         ▼                                                  ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Importer Classes                                   │
│  ┌─────────────┐ ┌─────────────┐ ┌──────────────────┐ ┌──────┐ ┌──────┐   │
│  │   Company   │ │   People    │ │   Opportunity    │ │ Task │ │ Note │   │
│  │  Importer   │ │  Importer   │ │    Importer      │ │Imptr │ │Imptr │   │
│  └──────┬──────┘ └──────┬──────┘ └────────┬─────────┘ └──┬───┘ └──┬───┘   │
│         └───────────────┴─────────────────┴──────────────┴────────┘        │
│                                    │                                        │
│                                    ▼                                        │
│                          ┌─────────────────┐                                │
│                          │  BaseImporter   │                                │
│                          │ (shared logic)  │                                │
│                          └─────────────────┘                                │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Custom Fields Integration                             │
│  ┌──────────────────┐    ┌────────────────────┐    ┌───────────────────┐   │
│  │ ImporterBuilder  │───▶│ ImportColumn       │───▶│ ImportDataStorage │   │
│  │ (generates cols) │    │ Configurator       │    │   (WeakMap)       │   │
│  └──────────────────┘    └────────────────────┘    └───────────────────┘   │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## The Three Tabs Explained

### Tab 1: Quick Import

**Purpose**: Fast, single-entity imports for users who know exactly what they want to import.

**How it works**:
1. User sees 5 cards (Companies, People, Opportunities, Tasks, Notes)
2. Each card has an "Import" button
3. Clicking opens Filament's ImportAction modal
4. User uploads file → maps columns → chooses duplicate handling → imports

**Why this design**:
- Most users import one entity at a time
- Direct access without navigating through a wizard
- Visual cards show what can be imported at a glance

### Tab 2: Import History

**Purpose**: Track all past imports, monitor status, and download failed rows.

**How it works**:
1. Displays a Filament Table querying the `imports` table
2. Shows: Date, Type, File name, Success count, Failed count, Status
3. Table polls every 5 seconds to update status in real-time
4. "Download Failed Rows" action appears when failures exist

**Why this design**:
- Users need visibility into import progress
- Failed rows need to be recoverable for fixing and re-importing
- Real-time updates (polling) show progress without page refresh

### Tab 3: Migration Wizard

**Purpose**: Guide users through importing multiple entity types in the correct order (for CRM migrations).

**How it works**:
1. **Step 1**: Select which entities to import (with dependency enforcement)
2. **Step 2**: Import each entity one by one (in dependency order)
3. **Step 3**: Summary of all imports

**Why this design**:
- CRM migrations require importing data in order (Companies first, then People who belong to Companies, etc.)
- Dependencies prevent errors (can't import People without Companies to link them to)
- Tracks overall migration progress in a `MigrationBatch`

---

## Entity Dependencies

```
Companies (no dependencies)
    │
    ▼
People (depends on Companies)
    │
    ▼
Opportunities (depends on Companies + People)
    │
    ├────────────────────┐
    ▼                    ▼
Tasks                  Notes
(depends on all)      (depends on all)
```

**Why these dependencies?**
- People belong to Companies (company_id foreign key)
- Opportunities reference Companies and People (company_id, contact_id)
- Tasks/Notes can link to any entity via polymorphic relationships

---

## File Processing Flow

```
┌─────────────────┐
│  User uploads   │
│  .csv or .xlsx  │
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────────────────┐
│           EnhancedImportAction              │
│                                             │
│  Is it an Excel file?                       │
│  (.xlsx, .xls, .ods)                        │
│                                             │
│     YES                    NO               │
│      │                      │               │
│      ▼                      │               │
│  ┌───────────────────┐      │               │
│  │ ExcelToCsvConverter│      │               │
│  │                   │      │               │
│  │ 1. Load with      │      │               │
│  │    PhpSpreadsheet │      │               │
│  │ 2. Get active     │      │               │
│  │    sheet          │      │               │
│  │ 3. Write as CSV   │      │               │
│  │ 4. Return new     │      │               │
│  │    UploadedFile   │      │               │
│  └─────────┬─────────┘      │               │
│            │                │               │
│            └───────┬────────┘               │
│                    │                        │
│                    ▼                        │
│            ┌───────────────┐                │
│            │ CSV Reader    │                │
│            │ (league/csv)  │                │
│            └───────────────┘                │
└─────────────────────────────────────────────┘
```

**Why pre-convert Excel to CSV?**
- Filament's import system only understands CSV
- Converting first lets us reuse all existing CSV logic
- PhpSpreadsheet handles all Excel formats (.xls, .xlsx, .ods)
- Simple approach: one conversion point, no changes to importers

---

## Column Mapping Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                   When file is uploaded                         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  1. Read CSV headers: ["Company Name", "Phone", "Address"]      │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  2. Get importer columns and their "guesses"                    │
│                                                                 │
│     ImportColumn::make('name')                                  │
│         ->guess(['name', 'company_name', 'company'])            │
│                                                                 │
│     ImportColumn::make('phone')                                 │
│         ->guess(['phone', 'phone_number', 'telephone'])         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  3. Auto-match: lowercase comparison                            │
│                                                                 │
│     CSV "Company Name" → matches guess "company_name" → 'name'  │
│     CSV "Phone"        → matches guess "phone"        → 'phone' │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  4. User sees pre-filled mapping, can adjust                    │
│                                                                 │
│     [name]  ────────────▶ [Company Name ▼]                      │
│     [phone] ────────────▶ [Phone ▼       ]                      │
└─────────────────────────────────────────────────────────────────┘
```

**Why this approach?**
- Guessing reduces manual work for users
- Case-insensitive matching handles "Company Name" vs "company_name"
- User can always override bad guesses

---

## Database vs Custom Fields

This is a **critical concept** to understand:

### Database Columns (defined in importers)
These are actual columns in the database table:

| Entity      | Database Columns                                      |
|-------------|-------------------------------------------------------|
| Companies   | `name`, `account_owner_id`                            |
| People      | `name`, `company_id`                                  |
| Opportunities| `name`, `company_id`, `contact_id`                   |
| Tasks       | `title`                                               |
| Notes       | `title`                                               |

### Custom Fields (auto-generated)
These are stored in `custom_field_values` table, NOT in the entity table:

```
Companies might have:
  - address (custom field)
  - phone (custom field)
  - country (custom field)
  - industry (custom field)
  - website (custom field)
```

### How Custom Fields Work in Import

```
┌─────────────────────────────────────────────────────────────────┐
│  Importer defines:                                              │
│                                                                 │
│    ImportColumn::make('name')      ← Real DB column             │
│        ->fillRecordUsing(...)                                   │
│                                                                 │
│    ...CustomFields::importer()     ← Auto-generates columns     │
│        ->forModel(Company::class)      for all custom fields    │
│        ->columns()                                              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Custom field columns are prefixed: custom_fields_address       │
│                                                                 │
│  These use fillRecordUsing that stores in ImportDataStorage     │
│  (a WeakMap) instead of setting model attributes                │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  In afterSave() hook:                                           │
│                                                                 │
│    CustomFields::importer()                                     │
│        ->forModel($this->record)                                │
│        ->saveValues();  ← Pulls from WeakMap, saves to          │
│                           custom_field_values table             │
└─────────────────────────────────────────────────────────────────┘
```

**Why this design?**
- Filament tries to set `$model->address = $value` which fails (no column)
- WeakMap stores values temporarily during import
- After record is saved (has ID), custom fields can be saved with correct entity_id
- WeakMap auto-cleans memory when model is garbage collected

---

## Duplicate Handling

```
┌─────────────────────────────────────────────────────────────────┐
│  User MUST choose one (no default):                             │
│                                                                 │
│  ○ Skip duplicates                                              │
│    → If company "Acme" exists, skip this row                    │
│                                                                 │
│  ○ Update existing records                                      │
│    → If company "Acme" exists, update it with new values        │
│                                                                 │
│  ○ Create new records anyway                                    │
│    → Always create, even if "Acme" already exists               │
└─────────────────────────────────────────────────────────────────┘
```

**Why require user choice?**
- No safe default: Skip loses data, Update overwrites data, Create makes duplicates
- User knows their data best
- Prevents accidental data loss

### How duplicates are detected:

```php
// In CompanyImporter::resolveRecord()
$existing = Company::query()
    ->where('team_id', $this->import->team_id)  // Same workspace
    ->where('name', trim($name))                 // Same name
    ->first();
```

Each entity type detects duplicates differently:
- **Companies**: by name
- **People**: by email (custom field lookup)
- **Opportunities**: by name
- **Tasks**: by title
- **Notes**: by title

---

## Team Isolation (Multi-tenancy)

Every import is scoped to a team (workspace):

```
┌─────────────────────────────────────────────────────────────────┐
│                        Team 1 (Acme Corp)                       │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │ Companies    │  │ People       │  │ Imports      │          │
│  │ team_id = 1  │  │ team_id = 1  │  │ team_id = 1  │          │
│  └──────────────┘  └──────────────┘  └──────────────┘          │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                        Team 2 (Beta Inc)                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │ Companies    │  │ People       │  │ Imports      │          │
│  │ team_id = 2  │  │ team_id = 2  │  │ team_id = 2  │          │
│  └──────────────┘  └──────────────┘  └──────────────┘          │
└─────────────────────────────────────────────────────────────────┘
```

**Why?**
- Users should only see/import their own data
- Duplicate detection only considers same-team records
- Import history only shows team's imports

---

## Relationship Resolution

When importing, relationships are resolved by name:

```
CSV Row: "John Doe", "Acme Corp"
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Look up: Company where name = "Acme Corp" AND team_id = 1      │
│                                                                 │
│  Found?                                                         │
│    YES → Use existing company_id                                │
│    NO  → Create new company, use its ID                         │
└─────────────────────────────────────────────────────────────────┘
```

This is implemented in `BaseImporter`:
- `resolveCompanyByName()` - Find only
- `resolveOrCreateCompany()` - Find or create
- `resolvePersonByName()` - Find only
- `resolveTeamMemberByEmail()` - Find team member by email

---

## Failed Rows Handling

```
┌─────────────────────────────────────────────────────────────────┐
│  During import, each row is validated:                          │
│                                                                 │
│  Row 1: "Acme", "valid@email.com"  → ✓ Pass → Import            │
│  Row 2: "Beta", "invalid-email"    → ✗ Fail → Store error       │
│  Row 3: "", "test@test.com"        → ✗ Fail → "name required"   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Failed rows stored in import_row_failures table:               │
│                                                                 │
│  {                                                              │
│    import_id: 1,                                                │
│    data: {"name": "Beta", "email": "invalid-email"},            │
│    validation_error: "email must be valid"                      │
│  }                                                              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  User can download failed rows as CSV:                          │
│                                                                 │
│  Route: filament.imports.failed-rows.download                   │
│  Controller: Filament\Actions\Imports\Http\Controllers\         │
│              DownloadImportFailureCsv                           │
└─────────────────────────────────────────────────────────────────┘
```

**Why?**
- Users shouldn't lose failed data
- They can fix errors in the CSV and re-import
- Clear error messages help identify issues

---

## Migration Wizard State Machine

```
                    ┌─────────────┐
                    │   Step 1    │
                    │   Select    │
                    │  Entities   │
                    └──────┬──────┘
                           │ nextStep()
                           │ (creates MigrationBatch)
                           ▼
                    ┌─────────────┐
              ┌────▶│   Step 2    │◀────┐
              │     │   Import    │     │
              │     │  [Entity]   │     │
              │     └──────┬──────┘     │
              │            │            │
     skipCurrentEntity()   │    moveToNextEntity()
              │            │            │
              │            ▼            │
              │     ┌─────────────┐     │
              │     │ More        │     │
              └─────│ entities?   │─────┘
                    └──────┬──────┘
                           │ NO
                           ▼
                    ┌─────────────┐
                    │   Step 3    │
                    │  Complete   │
                    │  (Summary)  │
                    └─────────────┘
```

---

## Critical Analysis: Is This The Right Approach?

### What's Good

| Aspect | Why It's Good |
|--------|---------------|
| **Excel Support via Conversion** | Simple, non-invasive. Reuses all CSV logic. |
| **Custom Fields Integration** | WeakMap is clever - prevents memory leaks, clean API. |
| **Required Duplicate Choice** | Forces user to think about their data. |
| **Dependency Enforcement** | Prevents broken imports (orphan records). |
| **Team Isolation** | Multi-tenancy done correctly at every level. |
| **Failed Row Recovery** | Users don't lose data on partial failures. |

### What Could Be Better

| Issue | Current State | Better Approach |
|-------|---------------|-----------------|
| **No Preview Before Import** | User imports blind | Show first 5-10 rows preview with mapped columns |
| **No Dry Run** | Can't test without importing | Add "validate only" option |
| **Synchronous Processing** | UI blocks for large files | Already uses job batches, but UX could show real progress bar |
| **OpportunityImporter Duplicate Logic** | Always updates existing (ignores strategy) | Should respect DuplicateHandlingStrategy like CompanyImporter |
| **MigrationBatch Not Linking Imports** | `migration_batch_id` exists but may not be used correctly | Verify all imports in wizard set this field |
| **No Rollback** | Can't undo an import | Add soft-delete tracking or batch delete option |

### Code Quality Issues Found

1. **OpportunityImporter::resolveRecord()** ignores duplicate handling strategy:
```php
// Current - always updates
if ($opportunity) {
    return $opportunity;  // No check for SKIP strategy
}
```

2. **Import status logic in ImportCenter is redundant**:
```php
// Lines 238-239 are identical
return $import->getFailedRowsCount() > 0 ? 'Completed' : 'Completed';
```

---

## Data Flow Summary

```
User uploads file
        │
        ▼
EnhancedImportAction
(converts Excel if needed)
        │
        ▼
Column Mapping UI
(auto-guesses, user confirms)
        │
        ▼
Duplicate Strategy Selection
(REQUIRED - user chooses)
        │
        ▼
Job Batching
(Laravel Queue - processes in chunks)
        │
        ▼
For each row:
├─► Validate → Failed? → Store in import_row_failures
│
├─► resolveRecord() → Find or create based on strategy
│
├─► fillRecordUsing() → Set DB columns + store custom fields in WeakMap
│
├─► Save record to database
│
└─► afterSave() → Pull from WeakMap, save to custom_field_values
        │
        ▼
Import Complete
(notification sent to user)
```

---

## Recommendations

1. **Add import preview** - Show mapped data before committing
2. **Fix OpportunityImporter** - Respect duplicate handling strategy
3. **Add progress indicator** - Real progress bar using job batch progress
4. **Add import templates** - Downloadable CSV templates with correct headers
5. **Add batch rollback** - Ability to undo an import (soft delete all created records)

---

## Files Reference

| File | Purpose |
|------|---------|
| `app/Filament/Pages/ImportCenter.php` | Main page with 3 tabs |
| `app/Livewire/Import/MigrationWizard.php` | Multi-entity wizard |
| `app/Filament/Actions/EnhancedImportAction.php` | Excel + CSV support |
| `app/Filament/Imports/BaseImporter.php` | Shared importer logic |
| `app/Filament/Imports/CompanyImporter.php` | Company import logic |
| `app/Filament/Imports/PeopleImporter.php` | People import logic |
| `app/Filament/Imports/OpportunityImporter.php` | Opportunity import logic |
| `app/Filament/Imports/TaskImporter.php` | Task import logic |
| `app/Filament/Imports/NoteImporter.php` | Note import logic |
| `app/Services/Import/ExcelToCsvConverter.php` | Excel → CSV conversion |
| `app/Enums/DuplicateHandlingStrategy.php` | Duplicate options enum |
| `app/Models/MigrationBatch.php` | Tracks multi-entity migrations |
| `custom-fields/.../ImporterBuilder.php` | Generates custom field columns |
| `custom-fields/.../ImportDataStorage.php` | WeakMap for custom field data |
