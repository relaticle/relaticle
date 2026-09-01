# Custom Fields 4.0, Plan 0: Pre-Substrate Housekeeping

> **For agentic workers:** REQUIRED SUB-SKILL: sdd-lean, task-by-task. FIRST plan executed on the `4.x` branch, BEFORE Plan 1 (substrate). Only substrate-independent cleanups live here; anything touching lookup_type or the record components waits for Plan 3. Every task ends with the package quality gate: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `vendor/bin/pest --type-coverage --min=100.0`, targeted pest run.

**Goal:** Land every breaking cleanup that does not depend on the new substrate first, so the large feature diffs of Plans 1 and 2 sit on a clean base and reviews are not fighting noise.

**Source of findings:** 2026-09-01 architecture review; each task cites its evidence files.

### Task 1: Component interface signature break

- `src/Filament/Integration/Factories/FieldComponentFactory.php:33-51` holds a dual code path checking `instanceof AbstractFormComponent` to conditionally pass `$record`.
- Add `?Model $record` to `make()` on `FormComponentInterface`, `InfolistComponentInterface`, `TableColumnInterface`, `TableFilterInterface`; delete the instanceof branch.
- Update every in-package implementation; note the break in the upgrade guide (third-party components must add the parameter; `AbstractFormComponent` subclasses inherit it).
- Verify: full Filament feature suite.

### Task 2: Contract renames and interface collapse

- Rename `src/Contracts/ValueResolvers.php` and `src/Contracts/CustomsFieldsMigrators.php` to standard `*Interface` names; migrate all implementations and callers in the same commit.
- Collapse single-implementation internal interfaces (`EntityManagerInterface`, `EntityConfigurationInterface`, the migrator contract) onto final concrete classes; callers depend on the concrete class.
- Fix the dead config key: config defines `management.navigation_group`, `Utils::isResourceNavigationGroupEnabled()` reads `navigation_group_enabled`. Keep `navigation_group_enabled`, delete the orphan.
- Verify: phpstan + full suite.

### Task 3: Drop v1-era upgrade tooling, keep the framework

- Delete `bin/custom-fields-upgrade` (775 lines) and its composer.json bin entry.
- Delete obsolete steps (`MigrateEmailFormatStep`, `MigratePhoneFormatStep`, `MigrateStringToJsonFormatStep`, `MigrateValidationRulesFormatStep`, `CleanMultiValueValidationRulesStep`, `MigrateLookupFieldsStep`) once the upgrade guide states 3.x hosts must be on latest 3.x before jumping to 4.0.
- KEEP `UpgradeCommand`, `UpgradeStep`, `UpgradeStepResult`, `ValidateSchemaStep`, `ClearCachesStep` (Plan 1 adds the record-links steps to this framework).
- Verify: `custom-fields:upgrade --dry-run` green on a fixture dataset.

### Task 4: Consistency sweep

- `declare(strict_types=1)` in the 8 files missing it (`CustomFieldDoesNotExistException`, `CustomFieldQueryBuilder`, `CustomFieldSectionObserver`, `HasFieldType`, `InfolistContainer`, `FormContainer`, `FormBuilder`, `TernaryFilter`).
- Final-class sweep: mark non-extension-point services/components final; the documented extension points stay open (`BaseFieldType`, swap-registry models, contracts).
- Remove `down()` from the existing migrations (`create_custom_fields_table`, `relax_custom_fields_unique_key`); delete the orphaned Database Configuration header block in `config/custom-fields.php:139-144`.
- Resolve the 3 `->todo()` tests (`ViewRecordTest.php:147,212`, `CustomFieldsFieldManagementTest.php:866`); keep the conditional skip only if the feature gate demands it.
- Verify: full gate.

### Task 5: Split the three 650+ line classes

- `src/FieldTypeSystem/FieldSchema.php` (652): extract per-field-type schema builders.
- `src/Services/Visibility/FrontendVisibilityService.php` (672): extract the JS expression generator.
- `src/Filament/Management/Forms/Components/VisibilityComponent.php` (656): extract condition-row rendering.
- Behavior-neutral relocations only; move + update callers + scoped tests per class, one commit each. Doing this FIRST matters most for `FieldSchema`, which Plans 1-3 all touch.

### Task 6: Raise PHPStan level 5 toward 7

- Bump `phpstan.neon` one level at a time; fix, never baseline. Stop at the highest level reachable without suppressions; record the landing level in the contributing doc.
- Verify: `vendor/bin/phpstan analyse` clean, full suite green.
