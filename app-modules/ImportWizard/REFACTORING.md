# ImportWizard Refactoring Plan

> **Philosophy:** Simple, disposable, easy to change. No cathedrals of complexity.

## Current State Analysis

### The Good
- Cache-based design prevents PayloadTooLargeException (smart!)
- Single-pass CSV reading is efficient
- Spatie Data classes provide structure
- Background job processing for large imports

### The Problems

| Issue | Severity | Description |
|-------|----------|-------------|
| **Incomplete API Controller** | CRITICAL | `ImportValuesController` calls undefined methods |
| **Duplicated Validation** | HIGH | Date validation logic in 4+ places |
| **Cache Key Sprawl** | MEDIUM | Same keys hardcoded 6+ times |
| **Two Value Fetchers** | MEDIUM | Livewire + API do same thing differently |
| **Corrections in State** | LOW | Should be in cache like values |

### Code Metrics

```
Total: ~8,700 lines
Duplication: ~15-20% could be eliminated
Largest files:
  - ImportWizard.php: 898 lines (too big)
  - HasValueAnalysis.php: 582 lines (trait doing too much)
  - CsvAnalyzer.php: 527 lines (acceptable for its job)
```

---

## Proposed Architecture

### Before (Current)

```
ImportWizard (898 lines)
├── HasCsvParsing (trait)
├── HasColumnMapping (trait)
├── HasValueAnalysis (trait)  ← duplicates API logic
└── HasImportPreview (trait)

+ ImportValuesController      ← incomplete, duplicates trait
+ ImportCorrectionsController
+ CsvAnalyzer                 ← has date validation
+ DateValidator               ← also has date validation
+ TimestampValidator          ← also has date validation
```

### After (Proposed)

```
ImportWizard (Livewire orchestrator only - ~400 lines)
├── Step navigation
├── File handling
└── Action triggers

ImportSessionService (new - single source of truth)
├── Cache key management
├── Value fetching (used by both Livewire + API)
├── Corrections management
└── Analysis data access

ValueValidationService (new - consolidate validation)
├── validateValue(value, fieldType, format)
├── validateColumn(values, fieldType, format)
└── parseDatePreview(value, fieldType, format)

Controllers (thin - delegate to services)
├── ImportValuesController → ImportSessionService
└── ImportCorrectionsController → ImportSessionService
```

---

## Phase 1: Fix Critical Issues (Day 1)

### 1.1 Complete ImportValuesController

**Problem:** Calls undefined `validateValue()` and `parseDatePreview()`.

**Solution:** Extract shared logic into `ImportSessionService`.

```php
// app-modules/ImportWizard/src/Support/ImportSessionService.php

final class ImportSessionService
{
    public function __construct(
        private DateValidator $dateValidator,
        private TimestampValidator $timestampValidator,
    ) {}

    // Cache key management
    public function valuesCacheKey(string $sessionId, string $csvColumn): string
    {
        return "import:{$sessionId}:values:{$csvColumn}";
    }

    public function analysisCacheKey(string $sessionId, string $csvColumn): string
    {
        return "import:{$sessionId}:analysis:{$csvColumn}";
    }

    public function correctionsCacheKey(string $sessionId, string $fieldName): string
    {
        return "import:{$sessionId}:corrections:{$fieldName}";
    }

    // Value operations
    public function getUniqueValues(string $sessionId, string $csvColumn): array
    {
        return Cache::get($this->valuesCacheKey($sessionId, $csvColumn), []);
    }

    public function getAnalysis(string $sessionId, string $csvColumn): ?array
    {
        return Cache::get($this->analysisCacheKey($sessionId, $csvColumn));
    }

    public function getCorrections(string $sessionId, string $fieldName): array
    {
        return Cache::get($this->correctionsCacheKey($sessionId, $fieldName), []);
    }

    public function storeCorrection(string $sessionId, string $fieldName, string $oldValue, string $newValue): void
    {
        $key = $this->correctionsCacheKey($sessionId, $fieldName);
        $corrections = Cache::get($key, []);
        $corrections[$oldValue] = $newValue;
        Cache::put($key, $corrections, now()->addHours(24));
    }

    public function removeCorrection(string $sessionId, string $fieldName, string $oldValue): void
    {
        $key = $this->correctionsCacheKey($sessionId, $fieldName);
        $corrections = Cache::get($key, []);
        unset($corrections[$oldValue]);

        if ($corrections === []) {
            Cache::forget($key);
        } else {
            Cache::put($key, $corrections, now()->addHours(24));
        }
    }
}
```

### 1.2 Create ValueValidationService

**Problem:** Date validation duplicated in 4+ places.

**Solution:** Single service for all value validation.

```php
// app-modules/ImportWizard/src/Support/ValueValidationService.php

final class ValueValidationService
{
    public function __construct(
        private DateValidator $dateValidator,
        private TimestampValidator $timestampValidator,
    ) {}

    public function validateValue(
        string $value,
        ?string $fieldType,
        ?string $formatValue,
    ): ?array {
        if ($value === '') {
            return null;
        }

        $isDateTimeField = $fieldType === FieldDataType::DATE_TIME->value;
        $isDateField = $fieldType === FieldDataType::DATE->value || $isDateTimeField;

        if (!$isDateField || $formatValue === null) {
            return null;
        }

        if ($isDateTimeField) {
            $format = TimestampFormat::tryFrom($formatValue);
            if ($format === null) {
                return null;
            }
            $result = $this->timestampValidator->validate($value, $format);
        } else {
            $format = DateFormat::tryFrom($formatValue);
            if ($format === null) {
                return null;
            }
            $result = $this->dateValidator->validate($value, $format);
        }

        return $result['issue']?->toArray();
    }

    public function validateColumn(
        array $uniqueValues,
        ?string $fieldType,
        ?string $formatValue,
    ): array {
        $isDateTimeField = $fieldType === FieldDataType::DATE_TIME->value;
        $isDateField = $fieldType === FieldDataType::DATE->value || $isDateTimeField;

        if (!$isDateField || $formatValue === null) {
            return ['issues' => [], 'errorCount' => 0, 'warningCount' => 0];
        }

        if ($isDateTimeField) {
            $format = TimestampFormat::tryFrom($formatValue);
            if ($format === null) {
                return ['issues' => [], 'errorCount' => 0, 'warningCount' => 0];
            }
            return $this->timestampValidator->validateColumn($uniqueValues, $format);
        }

        $format = DateFormat::tryFrom($formatValue);
        if ($format === null) {
            return ['issues' => [], 'errorCount' => 0, 'warningCount' => 0];
        }
        return $this->dateValidator->validateColumn($uniqueValues, $format);
    }

    public function parseDatePreview(
        string $value,
        ?string $fieldType,
        ?string $formatValue,
    ): ?string {
        if ($value === '' || $formatValue === null) {
            return null;
        }

        $isDateTimeField = $fieldType === FieldDataType::DATE_TIME->value;

        try {
            if ($isDateTimeField) {
                $format = TimestampFormat::tryFrom($formatValue);
                if ($format === null) {
                    return null;
                }
                $parsed = Carbon::createFromFormat($format->toPhpFormat(), $value);
                return $parsed?->format('Y-m-d H:i:s');
            }

            $format = DateFormat::tryFrom($formatValue);
            if ($format === null) {
                return null;
            }
            $parsed = Carbon::createFromFormat($format->toPhpFormat(), $value);
            return $parsed?->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
```

---

## Phase 2: Consolidate Duplication (Day 2)

### 2.1 Refactor ImportValuesController

Use the new services:

```php
final class ImportValuesController extends Controller
{
    public function __construct(
        private ImportSessionService $sessionService,
        private ValueValidationService $validationService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
            'csv_column' => ['required', 'string'],
            'field_name' => ['required', 'string'],
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:500'],
            'errors_only' => ['boolean'],
            'date_format' => ['nullable', 'string'],
        ]);

        $sessionId = $validated['session_id'];
        $csvColumn = $validated['csv_column'];
        $fieldName = $validated['field_name'];
        $page = $validated['page'] ?? 1;
        $perPage = $validated['per_page'] ?? 100;
        $errorsOnly = $validated['errors_only'] ?? false;

        // Use services - no more duplicate logic!
        $allValues = $this->sessionService->getUniqueValues($sessionId, $csvColumn);
        $analysisData = $this->sessionService->getAnalysis($sessionId, $csvColumn);
        $corrections = $this->sessionService->getCorrections($sessionId, $fieldName);

        // Build response using shared validation
        $fieldType = $analysisData['fieldType'] ?? null;
        $formatValue = $validated['date_format']
            ?? $analysisData['selectedDateFormat']
            ?? $analysisData['detectedDateFormat']
            ?? null;

        // Filter, paginate, and build response...
        // (Same logic, but using validationService for validation)
    }
}
```

### 2.2 Refactor ImportCorrectionsController

```php
final class ImportCorrectionsController extends Controller
{
    public function __construct(
        private ImportSessionService $sessionService,
        private ValueValidationService $validationService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
            'field_name' => ['required', 'string'],
            'old_value' => ['required', 'string'],
            'new_value' => ['present', 'nullable', 'string'],
            'csv_column' => ['nullable', 'string'],
        ]);

        $newValue = $validated['new_value'] ?? '';

        $this->sessionService->storeCorrection(
            $validated['session_id'],
            $validated['field_name'],
            $validated['old_value'],
            $newValue,
        );

        // Validate if non-empty
        $issue = null;
        if ($newValue !== '' && $validated['csv_column'] !== null) {
            $analysisData = $this->sessionService->getAnalysis(
                $validated['session_id'],
                $validated['csv_column'],
            );

            $issue = $this->validationService->validateValue(
                $newValue,
                $analysisData['fieldType'] ?? null,
                $analysisData['selectedDateFormat'] ?? $analysisData['detectedDateFormat'] ?? null,
            );
        }

        return response()->json([
            'success' => true,
            'isSkipped' => $newValue === '',
            'issue' => $issue,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
            'field_name' => ['required', 'string'],
            'old_value' => ['required', 'string'],
        ]);

        $this->sessionService->removeCorrection(
            $validated['session_id'],
            $validated['field_name'],
            $validated['old_value'],
        );

        return response()->json(['success' => true]);
    }
}
```

### 2.3 Simplify HasValueAnalysis

Remove duplicate logic, delegate to services:

```php
// In HasValueAnalysis trait

public function changeDateFormat(string $fieldName, string $formatValue): void
{
    $analysis = $this->getColumnAnalysisByField($fieldName);
    if (!$analysis) {
        return;
    }

    // Use shared validation service
    $validationService = resolve(ValueValidationService::class);
    $sessionService = resolve(ImportSessionService::class);

    $uniqueValues = $sessionService->getUniqueValues($this->sessionId, $analysis->csvColumnName);

    $result = $validationService->validateColumn(
        $uniqueValues,
        $analysis->fieldType,
        $formatValue,
    );

    // Update cached analysis with new validation result
    $this->updateCachedAnalysis($analysis->csvColumnName, $result);

    // Update selected format in state
    $this->selectedDateFormats[$fieldName] = $formatValue;
}
```

---

## Phase 3: Simplify Main Component (Day 3-4)

### 3.1 Reduce ImportWizard.php Size

**Target:** 400-500 lines (from 898)

**Strategy:**
1. Keep only orchestration logic in main class
2. Move heavy lifting to services
3. Simplify trait responsibilities

```php
// Simplified ImportWizard structure

final class ImportWizard extends Component
{
    use HasCsvParsing;      // File upload, parsing (~100 lines)
    use HasColumnMapping;   // Column mapping (~300 lines - keep as is)
    use HasImportPreview;   // Preview generation (~150 lines)

    // HasValueAnalysis absorbed into services + smaller trait (~150 lines)

    // State
    public int $currentStep = 1;
    public ?TemporaryUploadedFile $uploadedFile = null;
    public array $csvHeaders = [];
    public array $columnMap = [];
    public string $sessionId;

    // Services injected
    public function boot(ImportSessionService $sessionService): void
    {
        $this->sessionService = $sessionService;
    }

    // Step navigation
    public function nextStep(): void
    {
        if (!$this->canProceedToNextStep()) {
            return;
        }
        $this->advanceToNextStep();
        $this->currentStep++;
    }

    // Keep action methods simple
    public function executeImport(): void
    {
        // Delegate to service/job
    }
}
```

### 3.2 Consolidate Traits

**From 4 traits to 3:**

| Before | After | Reason |
|--------|-------|--------|
| HasCsvParsing | HasCsvParsing | Keep - focused |
| HasColumnMapping | HasColumnMapping | Keep - necessary complexity |
| HasValueAnalysis | (absorbed) | Logic moved to services |
| HasImportPreview | HasImportPreview | Keep - focused |

New approach for value analysis:
- Cache operations → `ImportSessionService`
- Validation → `ValueValidationService`
- Remaining state management → stays in component (minimal)

---

## Phase 4: Polish & Document (Day 5)

### 4.1 Add Clear Documentation

```php
/**
 * ImportWizard - 4-step CSV import wizard
 *
 * Architecture:
 * - Livewire component orchestrates UI flow
 * - Services handle data operations
 * - Cache stores large datasets (values, issues)
 * - State stores UI-only data (current step, mappings)
 *
 * Data Flow:
 * 1. Upload → ParsedCsv stored temporarily
 * 2. Map → columnMap built, stored in state
 * 3. Review → Analysis in cache, corrections via API
 * 4. Preview → Background job processes rows
 *
 * Cache Keys (managed by ImportSessionService):
 * - import:{session}:values:{column} - Unique values per column
 * - import:{session}:analysis:{column} - Validation results
 * - import:{session}:corrections:{field} - User corrections
 */
```

### 4.2 Add Type Coverage

Ensure all methods have:
- Return types
- Parameter types
- PHPDoc for arrays (`@param array<string, int>`)

### 4.3 Run Full Test Suite

```bash
composer test:pest -- --filter=ImportWizard
```

---

## File Changes Summary

### New Files
- `src/Support/ImportSessionService.php` (~100 lines)
- `src/Support/ValueValidationService.php` (~80 lines)

### Modified Files
- `src/Http/Controllers/ImportValuesController.php` (simplify)
- `src/Http/Controllers/ImportCorrectionsController.php` (simplify)
- `src/Livewire/Concerns/HasValueAnalysis.php` (reduce)
- `src/Livewire/ImportWizard.php` (reduce)
- `src/Support/CsvAnalyzer.php` (use ValueValidationService)

### Deleted Files
- None (we refactor, not rewrite)

---

## Estimated Impact

| Metric | Before | After | Change |
|--------|--------|-------|--------|
| Total lines | ~8,700 | ~7,500 | -14% |
| Duplication | ~15% | ~5% | -10% |
| Files with validation logic | 4+ | 1 | -75% |
| Cache key hardcodes | 6+ | 1 | -83% |
| Main component size | 898 | ~500 | -44% |

---

## Questions for Dr. Level

1. **Service injection:** Prefer constructor injection or `resolve()` in Livewire?

2. **Cache TTL:** Currently 24 hours. Keep or make configurable?

3. **API routes:** Currently under `/app/import/`. Move to versioned API (`/api/v1/`)?

4. **Test priority:** Which scenarios most critical to cover first?

5. **Corrections storage:** Move from Livewire state to cache completely?

---

## Next Actions

- [ ] Create `ImportSessionService`
- [ ] Create `ValueValidationService`
- [ ] Fix `ImportValuesController` (add missing methods)
- [ ] Refactor `ImportCorrectionsController` to use services
- [ ] Simplify `HasValueAnalysis` trait
- [ ] Update `CsvAnalyzer` to use `ValueValidationService`
- [ ] Run tests and fix any regressions
- [ ] Document architecture decisions

---

*"The Laravel apps that age best are the ones that don't get too clever."* - Taylor Otwell
