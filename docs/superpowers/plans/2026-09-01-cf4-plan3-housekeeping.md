# Custom Fields 4.0, Plan 3: Housekeeping Majors

> **For agentic workers:** REQUIRED SUB-SKILL: sdd-lean, task-by-task. Runs on the `4.x` branch AFTER Plan 1 (substrate) and Plan 2 (UI). Every task ends with the package quality gate: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `vendor/bin/pest --type-coverage --min=100.0`, targeted pest run.

**Goal:** Ship every breaking cleanup the 4.0 major allows, itemized by the 2026-09-01 architecture review of the 3.x package. One chance to break; nothing on this list survives to 5.0.

**Source of findings:** `.context/cf4-architecture-review.md` in the relaticle/relaticle workspace; each task cites its evidence files.

### Task 1: Component interface signature break

- `src/Filament/Integration/Factories/FieldComponentFactory.php:33-51` holds a dual code path checking `instanceof AbstractFormComponent` to conditionally pass `$record`.
- Add `?Model $record` to `make()` on `FormComponentInterface`, `InfolistComponentInterface`, `TableColumnInterface`, `TableFilterInterface`; delete the instanceof branch.
- Update every in-package implementation; note the break in the upgrade guide (third-party components must add the parameter; `AbstractFormComponent` subclasses inherit it).
- Verify: full Filament feature suite.

### Task 2: lookup_type retirement

Definitions own both ends after Plan 1; the column and its vocabulary go.

- Sweep the 18 referencing files (models `CustomField`/`CustomFieldSection`, `FieldSchema`, `RecordFilter`, `RecordColumn`, `RecordEntry`, `RecordSelectComponent`, `MultiChoiceEntry`, `ImportColumnConfigurator`, `ConfiguresBadgeColors`, `FieldForm`, `LookupResolver`, `LookupCache`, `LookupAttributeResolver`, `LookupPreloader`, `BackendVisibilityService`, upgrade steps) to read the target entity type from the field's relationship definition instead.
- `requiresLookupType()` on `FieldSchema` becomes definition-driven; `FieldForm` writes definitions via `CreateRelationshipDefinition`, never the column.
- New publishable migration dropping `lookup_type` from custom_fields. Gate: `ValidateSchemaStep` fails while record-type values still live in json_value (Plan 1 Task 12 must have run).
- Collapse `allow_multiple`/`max_values` into definition cardinality for record fields (settings keys ignored for type record; upgrade step maps them, already done by `MigrateRecordLinksStep`).
- Rename config key `selects.record_lookup` to match the record vocabulary.
- Verify: full suite; grep proves zero `lookup_type` references outside the upgrade steps that translate it.

### Task 3: Contract renames and interface collapse

- Rename `src/Contracts/ValueResolvers.php` and `src/Contracts/CustomsFieldsMigrators.php` to standard `*Interface` names; migrate all implementations and callers in the same commit.
- Collapse single-implementation internal interfaces (`EntityManagerInterface`, `EntityConfigurationInterface`, the migrator contract) onto final concrete classes; callers depend on the concrete class.
- Fix the dead config key: config defines `management.navigation_group`, `Utils::isResourceNavigationGroupEnabled()` reads `navigation_group_enabled`. Keep `navigation_group_enabled`, delete the orphan.
- Verify: phpstan + full suite.

### Task 4: Drop v1-era upgrade tooling, keep the framework

- Delete `bin/custom-fields-upgrade` (775 lines) and its composer.json bin entry.
- Delete obsolete steps (`MigrateEmailFormatStep`, `MigratePhoneFormatStep`, `MigrateStringToJsonFormatStep`, `MigrateValidationRulesFormatStep`, `CleanMultiValueValidationRulesStep`, `MigrateLookupFieldsStep`) once the upgrade guide states 3.x hosts must be on latest 3.x before jumping to 4.0.
- KEEP `UpgradeCommand`, `UpgradeStep`, `UpgradeStepResult`, `ValidateSchemaStep`, `ClearCachesStep`, and the Plan 1 record-links steps.
- Verify: `custom-fields:upgrade --dry-run` green on a fixture dataset.

### Task 5: Consistency sweep

- `declare(strict_types=1)` in the 8 files missing it (`CustomFieldDoesNotExistException`, `CustomFieldQueryBuilder`, `CustomFieldSectionObserver`, `HasFieldType`, `InfolistContainer`, `FormContainer`, `FormBuilder`, `TernaryFilter`).
- Final-class sweep: mark non-extension-point services/components final; the documented extension points stay open (`BaseFieldType`, swap-registry models, contracts).
- Remove `down()` from the existing migrations (`create_custom_fields_table`, `relax_custom_fields_unique_key`); delete the orphaned Database Configuration header block in `config/custom-fields.php:139-144`.
- Resolve the 3 `->todo()` tests (`ViewRecordTest.php:147,212`, `CustomFieldsFieldManagementTest.php:866`); keep the conditional skip only if the feature gate demands it.
- Verify: full gate.

### Task 6: Kill the reflection call into Filament internals

- `Utils::invokeMethodByReflection` (`src/Support/Utils.php:65`) calls Filament's private `applyGlobalSearchAttributeConstraints` from `RecordFilter` and `RecordSelectInputComponent`.
- Plan 1 Task 9 rewrites both callers onto link queries; confirm no reflection survives, then delete the helper. If any caller remains, replace with a public Filament API and fail loudly on absence.
- Verify: grep for `invokeMethodByReflection` returns nothing; record filter/search browser paths pass.

### Task 7: Split the three 650+ line classes

- `src/FieldTypeSystem/FieldSchema.php` (652): extract per-field-type schema builders.
- `src/Services/Visibility/FrontendVisibilityService.php` (672): extract the JS expression generator.
- `src/Filament/Management/Forms/Components/VisibilityComponent.php` (656): extract condition-row rendering.
- Behavior-neutral relocations only; move + update callers + scoped tests per class, one commit each.

### Task 8: Raise PHPStan level 5 toward 7

- Bump `phpstan.neon` one level at a time; fix, never baseline. Stop at the highest level reachable without suppressions; record the landing level in the contributing doc.
- Verify: `vendor/bin/phpstan analyse` clean, full suite green.
