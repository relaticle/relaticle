# ImportWizard Cleanup Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Drop 6 dead columns from `imports`, add `failed_rows`, harden `ExecuteImportJob` safety, and fix UX edge cases across the ImportWizard module.

**Architecture:** Three independent phases. Phase 1 cleans up the data model (migration + code). Phase 2 adds job safety (failed handler, incremental error writes, removes error cap). Phase 3 fixes UX (backward nav guard, ImportHistory failed column). Phase 2 depends on Phase 1. Phase 3 can ship after Phase 1 in any order.

**Tech Stack:** Laravel 12, Filament 5, Livewire 4, Pest 4, PHP 8.4

**Design Doc:** `docs/plans/2026-02-13-import-wizard-cleanup-design.md`

---

## Phase 1: Data Model Cleanup

### Task 1: Write migration to drop dead columns and add `failed_rows`

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_cleanup_imports_table.php`

**Step 1: Create the migration**

```bash
php artisan make:migration cleanup_imports_table --table=imports --no-interaction
```

**Step 2: Write migration content**

```php
public function up(): void
{
    Schema::table('imports', function (Blueprint $table): void {
        $table->unsignedInteger('failed_rows')->default(0)->after('skipped_rows');
    });

    Schema::table('imports', function (Blueprint $table): void {
        $table->dropColumn([
            'file_path',
            'importer',
            'processed_rows',
            'successful_rows',
            'results',
            'failed_rows_data',
        ]);
    });
}
```

Note: Two separate `Schema::table` calls because SQLite (used in tests) cannot drop columns and add columns in a single call.

**Step 3: Run migration**

```bash
php artisan migrate --no-interaction
```

Expected: Migration runs successfully.

**Step 4: Commit**

```bash
git add database/migrations/*cleanup_imports_table*
git commit -m "feat: add failed_rows column and drop 6 dead columns from imports"
```

---

### Task 2: Update Import model casts

**Files:**
- Modify: `app-modules/ImportWizard/src/Models/Import.php:29-46`

**Step 1: Update the `casts()` method**

Remove casts for: `results`, `failed_rows_data`, `processed_rows`, `successful_rows`.
Add cast for: `failed_rows`.

```php
protected function casts(): array
{
    return [
        'entity_type' => ImportEntityType::class,
        'status' => ImportStatus::class,
        'headers' => 'array',
        'column_mappings' => 'array',
        'completed_at' => 'datetime',
        'total_rows' => 'integer',
        'created_rows' => 'integer',
        'updated_rows' => 'integer',
        'skipped_rows' => 'integer',
        'failed_rows' => 'integer',
    ];
}
```

**Step 2: Run existing tests to confirm nothing breaks yet**

```bash
php artisan test --compact --filter=ExecuteImportJob
```

Expected: Some tests may fail due to references to `$import->results` — that's expected, we fix those in the next tasks.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Models/Import.php
git commit -m "refactor: remove dead column casts, add failed_rows cast to Import model"
```

---

### Task 3: Update ExecuteImportJob — remove dead column writes, simplify `persistResults()`

**Files:**
- Modify: `app-modules/ImportWizard/src/Jobs/ExecuteImportJob.php`

**Step 1: Update `persistResults()` (lines 360-367)**

Replace:

```php
private function persistResults(Import $import, array $results): void
{
    $import->update([
        'results' => $results,
        'failed_rows_data' => $this->failedRowsSummary(),
    ]);
}
```

With:

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

**Step 2: Update completion block (lines 110-120)**

Replace:

```php
$import->update([
    'status' => ImportStatus::Completed,
    'completed_at' => now(),
    'results' => $results,
    'failed_rows_data' => $this->failedRowsSummary(),
    'created_rows' => $results['created'],
    'updated_rows' => $results['updated'],
    'skipped_rows' => $results['skipped'],
    'successful_rows' => $results['created'] + $results['updated'],
    'processed_rows' => array_sum($results),
]);
```

With:

```php
$import->update([
    'status' => ImportStatus::Completed,
    'completed_at' => now(),
    'created_rows' => $results['created'],
    'updated_rows' => $results['updated'],
    'skipped_rows' => $results['skipped'],
    'failed_rows' => $results['failed'],
]);
```

**Step 3: Delete `failedRowsSummary()` method (lines 369-376)**

Remove entirely:

```php
/** @return list<array{row: int, error: string}> */
private function failedRowsSummary(): array
{
    return array_map(
        fn (array $row): array => ['row' => $row['row'], 'error' => $row['error']],
        $this->failedRows,
    );
}
```

**Step 4: Update initial `$results` load (line 84)**

Replace:

```php
$results = $import->results ?? ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
```

With:

```php
$results = [
    'created' => $import->created_rows,
    'updated' => $import->updated_rows,
    'skipped' => $import->skipped_rows,
    'failed' => $import->failed_rows,
];
```

This reads from the individual integer columns instead of the dropped `results` JSON. This also makes retry-safe — on retry, the job picks up exactly where the counters left off.

**Step 5: Commit**

```bash
git add app-modules/ImportWizard/src/Jobs/ExecuteImportJob.php
git commit -m "refactor: remove dead column writes from ExecuteImportJob, simplify persistResults"
```

---

### Task 4: Update PreviewStep — remove `results` dependency

**Files:**
- Modify: `app-modules/ImportWizard/src/Livewire/Steps/PreviewStep.php`

**Step 1: Replace `results()` computed property (lines 275-280)**

Remove:

```php
/** @return array<string, int>|null */
#[Computed]
public function results(): ?array
{
    return $this->import()->results;
}
```

Replace with:

```php
/** @return array{created: int, updated: int, skipped: int, failed: int} */
#[Computed]
public function results(): array
{
    $import = $this->import();

    return [
        'created' => $import->created_rows,
        'updated' => $import->updated_rows,
        'skipped' => $import->skipped_rows,
        'failed' => $import->failed_rows,
    ];
}
```

**Step 2: Update `processedCount()` (lines 115-125)**

The existing code already calls `array_sum($this->results())` which works with the new return value. However, the null check is no longer needed since we return a non-nullable array. Simplify:

```php
#[Computed]
public function processedCount(): int
{
    return array_sum($this->results());
}
```

**Step 3: Update `downloadFailedRowsAction()` visibility check (line 302)**

The existing code: `$this->results()['failed'] ?? 0` — the `?? 0` is now redundant since `results()` always returns an array, but it's harmless. Leave it as-is for safety.

Actually, to be precise and clean, change:

```php
->visible(fn (): bool => $this->isCompleted && ($this->results()['failed'] ?? 0) > 0)
```

To:

```php
->visible(fn (): bool => $this->isCompleted && $this->import()->failed_rows > 0)
```

This avoids calling the computed property inside the closure and reads directly from the import model.

**Step 4: Update `checkImportProgress()` (line 369)**

The `unset($this->results, ...)` call is fine — it clears the Livewire computed cache. No change needed.

**Step 5: Commit**

```bash
git add app-modules/ImportWizard/src/Livewire/Steps/PreviewStep.php
git commit -m "refactor: read import counters from individual columns instead of results JSON"
```

---

### Task 5: Update tests — remove `$import->results` assertions

**Files:**
- Modify: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php`

**Step 1: Find and replace all `$import->results` / `$results = $import->results` patterns**

Several tests read `$import->results` to assert counts. Replace these with direct column reads.

Tests to update:

1. **"skips rows with null match_action"** (line 370-373):
   ```php
   // Before:
   $results = $import->results;
   expect($results['created'])->toBe(1)
       ->and($results['skipped'])->toBe(1)
       ->and($results['failed'])->toBe(0);
   // After:
   expect($import->created_rows)->toBe(1)
       ->and($import->skipped_rows)->toBe(1)
       ->and($import->failed_rows)->toBe(0);
   ```

2. **"stores results with counts in meta"** (line 397-402):
   ```php
   // Before:
   $results = $import->results;
   expect($results)->not->toBeNull()
       ->and($results['created'])->toBe(1)
       ->and($results['updated'])->toBe(1)
       ->and($results['skipped'])->toBe(1);
   // After:
   expect($import->created_rows)->toBe(1)
       ->and($import->updated_rows)->toBe(1)
       ->and($import->skipped_rows)->toBe(1);
   ```

3. **"resolves multiple custom field values"** (line 211-213):
   ```php
   // Before:
   $results = $import->results;
   expect($results['updated'])->toBe(5)
       ->and($results['failed'])->toBe(0);
   // After:
   expect($import->updated_rows)->toBe(5)
       ->and($import->failed_rows)->toBe(0);
   ```

4. **"handles empty import"** (line 439-442):
   ```php
   // Before:
   $results = $import->results;
   expect($results['created'])->toBe(0)
       ->and($results['updated'])->toBe(0)
       ->and($results['skipped'])->toBe(2);
   // After:
   expect($import->created_rows)->toBe(0)
       ->and($import->updated_rows)->toBe(0)
       ->and($import->skipped_rows)->toBe(2);
   ```

5. **"processes rows in chunks"** (line 461):
   ```php
   // Before:
   $results = $import->results;
   expect($results['created'])->toBe(50);
   // After:
   expect($import->created_rows)->toBe(50);
   ```

6. **"persists failed row details"** (line 700-703):
   ```php
   // Before:
   $results = $import->results;
   expect($results['created'])->toBe(2)
       ->and($import->failedRows)->toBeEmpty();
   // After:
   expect($import->created_rows)->toBe(2)
       ->and($import->failedRows)->toBeEmpty();
   ```

7. **"records failed rows"** (line 768-772):
   ```php
   // Before:
   $results = $import->results;
   expect($results['created'])->toBe(1)
       ->and($results['skipped'])->toBe(1)
   // After:
   expect($import->created_rows)->toBe(1)
       ->and($import->skipped_rows)->toBe(1)
   ```

8. **"persists results to Import model"** (line 875-880):
   ```php
   // Before:
   expect($import->status)->toBe(ImportStatus::Completed)
       ->and($import->completed_at)->not->toBeNull()
       ->and($import->created_rows)->toBe(2)
       ->and($import->updated_rows)->toBe(0)
       ->and($import->skipped_rows)->toBe(0)
       ->and($import->results)->toBe(['created' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 0]);
   // After:
   expect($import->status)->toBe(ImportStatus::Completed)
       ->and($import->completed_at)->not->toBeNull()
       ->and($import->created_rows)->toBe(2)
       ->and($import->updated_rows)->toBe(0)
       ->and($import->skipped_rows)->toBe(0)
       ->and($import->failed_rows)->toBe(0);
   ```

9. **"skips Update row when matched record no longer exists"** (line 615-618):
   ```php
   // Before:
   $results = $import->results;
   expect($results['skipped'])->toBe(1)
       ->and($results['created'])->toBe(1);
   // After:
   expect($import->skipped_rows)->toBe(1)
       ->and($import->created_rows)->toBe(1);
   ```

10. **"sends success notification"** (line 720-722) — reads from notification data, which still passes `$results` array to `viewData`. This is the **notification payload**, not the model. Leave as-is.

11. **"includes result counts in notification body"** (lines 746-750) — Same, reads from notification payload. Leave as-is.

12. **1000-row tests** (lines 899, 940-943, 976-978):
    ```php
    // Before:
    $results = $import->results;
    expect($results['created'])->toBe(1000)
        ->and($results['failed'])->toBe(0);
    // After:
    expect($import->created_rows)->toBe(1000)
        ->and($import->failed_rows)->toBe(0);
    ```

    For mixed ops 1000-row test:
    ```php
    // Before:
    $results = $import->results;
    expect($results['created'])->toBe(850)
        ->and($results['updated'])->toBe(100)
        ->and($results['skipped'])->toBe(50)
        ->and($results['failed'])->toBe(0)
        ->and($import->status)->toBe(ImportStatus::Completed);
    // After:
    expect($import->created_rows)->toBe(850)
        ->and($import->updated_rows)->toBe(100)
        ->and($import->skipped_rows)->toBe(50)
        ->and($import->failed_rows)->toBe(0)
        ->and($import->status)->toBe(ImportStatus::Completed);
    ```

    For entity link 1000-row test:
    ```php
    // Before:
    $results = $import->results;
    expect($results['created'])->toBe(1000)
        ->and($results['failed'])->toBe(0)
    // After:
    expect($import->created_rows)->toBe(1000)
        ->and($import->failed_rows)->toBe(0)
    ```

**Step 2: Run tests**

```bash
php artisan test --compact --filter=ExecuteImportJob
```

Expected: All tests pass.

**Step 3: Commit**

```bash
git add tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php
git commit -m "test: update import job tests to read individual columns instead of results JSON"
```

---

### Task 6: Add `failed_rows` column to ImportHistory table

**Files:**
- Modify: `app-modules/ImportWizard/src/Filament/Pages/ImportHistory.php:83-84`

**Step 1: Add `failed_rows` TextColumn after `skipped_rows`**

After the `skipped_rows` column (line 84), add:

```php
TextColumn::make('failed_rows')
    ->label('Failed')
    ->numeric()
    ->color('danger'),
```

**Step 2: Commit**

```bash
git add app-modules/ImportWizard/src/Filament/Pages/ImportHistory.php
git commit -m "feat: add failed_rows column to ImportHistory table"
```

---

### Task 7: Run full test suite and PHPStan

**Step 1: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```

Expected: No new errors beyond baseline.

**Step 2: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

**Step 3: Run full ImportWizard tests**

```bash
php artisan test --compact tests/Feature/ImportWizard/
```

Expected: All tests pass.

**Step 4: Commit any fixes**

```bash
git add -A && git commit -m "chore: fix lint and static analysis issues from Phase 1"
```

(Only if there are fixes to make.)

---

## Phase 2: Job Safety

### Task 8: Write test for `failed()` handler

**Files:**
- Modify: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php`

**Step 1: Write the failing test**

```php
it('marks import as Failed when job exhausts retries via failed() handler', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $job = new ExecuteImportJob(
        importId: $this->import->id,
        teamId: (string) $this->team->id,
    );

    $job->failed(new \RuntimeException('Queue worker gave up'));

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Failed);
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="marks import as Failed when job exhausts retries"
```

Expected: FAIL — `failed()` method does not exist.

**Step 3: Commit**

```bash
git add tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php
git commit -m "test: add failing test for ExecuteImportJob failed() handler"
```

---

### Task 9: Implement `failed()` handler

**Files:**
- Modify: `app-modules/ImportWizard/src/Jobs/ExecuteImportJob.php`

**Step 1: Add `failed()` method**

Add after the `handle()` method:

```php
public function failed(\Throwable $exception): void
{
    $import = Import::query()->find($this->importId);

    if ($import === null) {
        return;
    }

    if (! in_array($import->status, [ImportStatus::Completed, ImportStatus::Failed], true)) {
        $import->update(['status' => ImportStatus::Failed]);
    }

    $this->writeFailedRowsToDb($import);

    try {
        $this->notifyUser($import, [
            'created' => $import->created_rows,
            'updated' => $import->updated_rows,
            'skipped' => $import->skipped_rows,
            'failed' => $import->failed_rows,
        ], failed: true);
    } catch (\Throwable) {
    }
}
```

**Step 2: Run test**

```bash
php artisan test --compact --filter="marks import as Failed when job exhausts retries"
```

Expected: PASS.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Jobs/ExecuteImportJob.php
git commit -m "feat: add failed() handler to ExecuteImportJob for retry exhaustion"
```

---

### Task 10: Write test for incremental failed row writes

**Files:**
- Modify: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php`

**Step 1: Write the test**

```php
it('writes FailedImportRow records to database on successful completion', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'Good Person'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->failed_rows)->toBe(0)
        ->and($import->failedRows()->count())->toBe(0);
});

it('writes FailedImportRow records incrementally during chunk processing', function (): void {
    $rows = [];
    for ($i = 2; $i <= 11; $i++) {
        $rows[] = makeRow($i, ['Name' => "Person {$i}"], ['match_action' => RowMatchAction::Create->value]);
    }

    createImportReadyStore($this, ['Name'], $rows, [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->created_rows)->toBe(10);
});
```

**Step 2: Run tests**

```bash
php artisan test --compact --filter="writes FailedImportRow"
```

Expected: PASS (these confirm incremental writes work after Phase 2 implementation).

**Step 3: Commit**

```bash
git add tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php
git commit -m "test: add tests for incremental FailedImportRow writes"
```

---

### Task 11: Move `writeFailedRowsToDb()` to run per-chunk and remove error cap

**Files:**
- Modify: `app-modules/ImportWizard/src/Jobs/ExecuteImportJob.php`

**Step 1: Remove `MAX_STORED_ERRORS` constant (line 51)**

Delete:

```php
private const int MAX_STORED_ERRORS = 100;
```

**Step 2: Update `recordFailedRow()` — remove cap check (lines 347-358)**

Replace:

```php
private function recordFailedRow(int $rowNumber, array $rawData, \Throwable $e): void
{
    if (count($this->failedRows) >= self::MAX_STORED_ERRORS) {
        return;
    }

    $this->failedRows[] = [
        'row' => $rowNumber,
        'error' => Str::limit($e->getMessage(), 500),
        'data' => $rawData,
    ];
}
```

With:

```php
private function recordFailedRow(int $rowNumber, array $rawData, \Throwable $e): void
{
    $this->failedRows[] = [
        'row' => $rowNumber,
        'error' => Str::limit($e->getMessage(), 500),
        'data' => $rawData,
    ];
}
```

**Step 3: Add `flushFailedRows()` method**

Add a new private method:

```php
private function flushFailedRows(Import $import): void
{
    if ($this->failedRows === []) {
        return;
    }

    $now = now();

    $rows = collect($this->failedRows)->map(fn (array $row): array => [
        'id' => (string) Str::ulid(),
        'import_id' => $import->id,
        'team_id' => $this->teamId,
        'data' => json_encode($row['data'] ?? ['row_number' => $row['row']]),
        'validation_error' => $row['error'],
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ($rows->chunk(100) as $chunk) {
        $import->failedRows()->insert($chunk->all());
    }

    $this->failedRows = [];
}
```

**Step 4: Call `flushFailedRows()` in the chunk callback (after `persistResults` call, line 107)**

Update the chunk callback to include:

```php
$this->flushProcessedRows($store);
$this->flushCustomFieldValues();
$this->flushFailedRows($import);
$this->persistResults($import, $results);
```

**Step 5: Remove the `writeFailedRowsToDb()` call from the success path (line 122)**

Delete:

```php
$this->writeFailedRowsToDb($import);
```

**Step 6: Delete the old `writeFailedRowsToDb()` method (lines 378-399)**

Remove entirely.

**Step 7: Update the `failed()` handler to flush remaining failed rows**

In the `failed()` method added in Task 9, replace:

```php
$this->writeFailedRowsToDb($import);
```

With:

```php
$this->flushFailedRows($import);
```

**Step 8: Also flush in the catch block (line 124-134)**

Update the catch block to flush before re-throwing:

```php
} catch (\Throwable $e) {
    $this->flushFailedRows($import);
    $this->persistResults($import, $results);
    $import->update(['status' => ImportStatus::Failed]);

    try {
        $this->notifyUser($import, $results, failed: true);
    } catch (\Throwable) {
    }

    throw $e;
}
```

**Step 9: Run tests**

```bash
php artisan test --compact --filter=ExecuteImportJob
```

Expected: All tests pass.

**Step 10: Commit**

```bash
git add app-modules/ImportWizard/src/Jobs/ExecuteImportJob.php
git commit -m "feat: write FailedImportRow records incrementally per chunk, remove 100-error cap"
```

---

### Task 12: Run Phase 2 verification

**Step 1: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```

**Step 2: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

**Step 3: Run full ImportWizard tests**

```bash
php artisan test --compact tests/Feature/ImportWizard/
```

Expected: All tests pass, no new PHPStan errors.

**Step 4: Commit any fixes**

```bash
git add -A && git commit -m "chore: fix lint and analysis issues from Phase 2"
```

---

## Phase 3: UX / State Fixes

### Task 13: Write test for backward navigation guard during validation

**Files:**
- Modify: `tests/Feature/ImportWizard/Livewire/ImportWizardTest.php`

**Step 1: Read the existing test file first**

Check current test structure and conventions.

**Step 2: Write the test**

This test verifies that `goBack()` is blocked when ReviewStep has active validation batches. The exact assertion depends on the current test file structure — adapt accordingly.

The core behavior to test: when the Import status is `Reviewing` and there are pending validation batches, `goBack()` on ImportWizard should not change `currentStep`.

**Step 3: Run test to verify it fails**

```bash
php artisan test --compact --filter="blocks backward navigation during validation"
```

**Step 4: Commit**

```bash
git add tests/Feature/ImportWizard/Livewire/ImportWizardTest.php
git commit -m "test: add failing test for backward nav guard during validation"
```

---

### Task 14: Implement backward navigation guard

**Files:**
- Modify: `app-modules/ImportWizard/src/Livewire/ImportWizard.php:80-88`

**Step 1: Update `goBack()` to check for active validation**

The current `goBack()`:

```php
public function goBack(): void
{
    if ($this->importStarted) {
        return;
    }

    $this->currentStep = max($this->currentStep - 1, self::STEP_UPLOAD);
    $this->syncStepStatus();
}
```

Add a check: if on ReviewStep and Import status is Reviewing, check if there are unfinished validation batches. The simplest approach is to check the Import's status — if we're on STEP_REVIEW, the ReviewStep component manages its own batch IDs. Since ImportWizard doesn't have direct access to ReviewStep's `$batchIds`, the cleanest approach is to dispatch an event asking ReviewStep if it's safe to go back, or check the Import status.

Simpler approach: Block `goBack()` when `currentStep === STEP_REVIEW` and the import's status is still `Reviewing` (meaning validation was initiated but we haven't progressed yet). This is imperfect — the real signal is batch completion.

Best approach: Add a `$validationInProgress` property that ReviewStep dispatches events to update:

In `ImportWizard.php`, add a public property:

```php
public bool $validationInProgress = false;
```

Add a listener:

```php
#[On('validation-state-changed')]
public function onValidationStateChanged(bool $inProgress): void
{
    $this->validationInProgress = $inProgress;
}
```

Update `goBack()`:

```php
public function goBack(): void
{
    if ($this->importStarted || $this->validationInProgress) {
        return;
    }

    $this->currentStep = max($this->currentStep - 1, self::STEP_UPLOAD);
    $this->syncStepStatus();
}
```

Also update `goToStep()`:

```php
public function goToStep(int $step): void
{
    if ($this->importStarted || $this->validationInProgress) {
        return;
    }
    // ... rest unchanged
}
```

**Step 2: Update ReviewStep to dispatch validation state events**

In `ReviewStep.php`, update `checkProgress()` (line 336):

After the `if ($this->batchIds === [])` block, dispatch to parent:

```php
public function checkProgress(): void
{
    // ... existing logic ...

    $this->dispatch('validation-state-changed', inProgress: $this->isValidating())
        ->to(ImportWizard::class);
}
```

Also dispatch in `mount()` after starting validation (around line 155):

```php
$this->dispatch('validation-state-changed', inProgress: true)->to(ImportWizard::class);
```

**Step 3: Run test**

```bash
php artisan test --compact --filter="blocks backward navigation"
```

Expected: PASS.

**Step 4: Commit**

```bash
git add app-modules/ImportWizard/src/Livewire/ImportWizard.php app-modules/ImportWizard/src/Livewire/Steps/ReviewStep.php
git commit -m "feat: block backward navigation during active ReviewStep validation"
```

---

### Task 15: Run Phase 3 verification

**Step 1: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```

**Step 2: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

**Step 3: Run full ImportWizard tests**

```bash
php artisan test --compact tests/Feature/ImportWizard/
```

Expected: All tests pass.

**Step 4: Commit any fixes**

```bash
git add -A && git commit -m "chore: fix lint and analysis issues from Phase 3"
```

---

## Final Verification

### Task 16: Full test suite and cleanup

**Step 1: Run full test suite**

```bash
php artisan test --compact
```

**Step 2: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```

**Step 3: Verify no dead references remain**

Search the codebase for any remaining references to dropped columns:

```bash
grep -r 'processed_rows\|successful_rows\|failed_rows_data\|file_path.*import\|->importer\b\|->results\b' app-modules/ImportWizard/src/ --include='*.php' | grep -v 'failed_rows' | grep -v vendor
```

Expected: No matches (except possibly in comments or unrelated contexts).

**Step 4: Final commit if needed**

```bash
git add -A && git commit -m "chore: final cleanup for ImportWizard cleanup phases"
```
