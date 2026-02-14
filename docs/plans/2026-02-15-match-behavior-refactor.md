# MatchBehavior Refactor + Multi-Column Entity Links

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the split `MatchBehavior(null)` + `EntityLink.canCreate` system with 3 explicit enum cases (`UpdateOnly`, `FindOrCreate`, `AlwaysCreate`), enable multi-column entity link mapping, and eliminate garbage data creation.

**Architecture:** `MatchBehavior` becomes the single source of truth for matcher behavior. Every `MatchableField` gets an explicit non-nullable `MatchBehavior`. `EntityLink.canCreate` is eliminated. Multi-column entity links allow mapping both a lookup column (Domain) and a creation-data column (Name) to the same relationship.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4

**Design doc:** `docs/plans/2026-02-15-match-behavior-refactor-design.md`

---

### Task 1: Refactor MatchBehavior Enum

**Files:**
- Modify: `app-modules/ImportWizard/src/Enums/MatchBehavior.php`

**Step 1: Rewrite the enum**

Replace the entire file with:

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

enum MatchBehavior: string
{
    case UpdateOnly = 'update_only';
    case FindOrCreate = 'find_or_create';
    case AlwaysCreate = 'always_create';

    public function description(): string
    {
        return match ($this) {
            self::UpdateOnly => 'Only update existing records. Skip if not found.',
            self::FindOrCreate => 'Find existing record or create new if not found.',
            self::AlwaysCreate => 'Always create a new record (no lookup).',
        };
    }

    public function performsLookup(): bool
    {
        return $this !== self::AlwaysCreate;
    }

    public function createsOnNoMatch(): bool
    {
        return $this === self::FindOrCreate || $this === self::AlwaysCreate;
    }
}
```

**Step 2: Run PHPStan on just this file**

Run: `vendor/bin/phpstan analyse app-modules/ImportWizard/src/Enums/MatchBehavior.php`
Expected: PASS (consumers will break — that's expected at this stage)

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Enums/MatchBehavior.php
git commit -m "refactor: add FindOrCreate case to MatchBehavior enum"
```

---

### Task 2: Update MatchableField — Explicit Behavior on All Matchers

**Files:**
- Modify: `app-modules/ImportWizard/src/Data/MatchableField.php`

**Step 1: Rewrite MatchableField**

Replace the entire file with:

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Relaticle\ImportWizard\Enums\MatchBehavior;
use Spatie\LaravelData\Data;

final class MatchableField extends Data
{
    public function __construct(
        public readonly string $field,
        public readonly string $label,
        public readonly int $priority = 0,
        public readonly MatchBehavior $behavior = MatchBehavior::FindOrCreate,
        public readonly bool $multiValue = false,
    ) {}

    public static function id(): self
    {
        return new self(
            field: 'id',
            label: 'Record ID',
            priority: 100,
            behavior: MatchBehavior::UpdateOnly,
        );
    }

    public static function email(
        string $fieldKey = 'custom_fields_emails',
        MatchBehavior $behavior = MatchBehavior::FindOrCreate,
    ): self {
        return new self(
            field: $fieldKey,
            label: 'Email',
            priority: 90,
            behavior: $behavior,
            multiValue: true,
        );
    }

    public static function domain(
        string $fieldKey = 'custom_fields_domains',
        MatchBehavior $behavior = MatchBehavior::FindOrCreate,
    ): self {
        return new self(
            field: $fieldKey,
            label: 'Domain',
            priority: 80,
            behavior: $behavior,
            multiValue: true,
        );
    }

    public static function phone(
        string $fieldKey = 'custom_fields_phone_number',
        MatchBehavior $behavior = MatchBehavior::FindOrCreate,
    ): self {
        return new self(
            field: $fieldKey,
            label: 'Phone',
            priority: 70,
            behavior: $behavior,
            multiValue: true,
        );
    }

    public static function name(): self
    {
        return new self(
            field: 'name',
            label: 'Name',
            priority: 10,
            behavior: MatchBehavior::AlwaysCreate,
        );
    }

    public function description(): string
    {
        return $this->behavior->description();
    }

    public function isAlwaysCreate(): bool
    {
        return $this->behavior === MatchBehavior::AlwaysCreate;
    }
}
```

Key changes:
- `?MatchBehavior` → `MatchBehavior` (non-nullable)
- Default changed from `null` to `MatchBehavior::FindOrCreate`
- `email()`, `domain()`, `phone()` accept optional `$behavior` parameter
- `description()` delegates to enum
- All factory methods set explicit behavior

**Step 2: Commit**

```bash
git add app-modules/ImportWizard/src/Data/MatchableField.php
git commit -m "refactor: make MatchBehavior explicit on all MatchableField matchers"
```

---

### Task 3: Add Behavior to RelationshipMatch

**Files:**
- Modify: `app-modules/ImportWizard/src/Data/RelationshipMatch.php`

**Step 1: Add behavior property and update factory methods**

Replace the entire file with:

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Spatie\LaravelData\Data;

final class RelationshipMatch extends Data
{
    public function __construct(
        public readonly string $relationship,
        public readonly RowMatchAction $action,
        public readonly ?string $id = null,
        public readonly ?string $name = null,
        public readonly ?MatchBehavior $behavior = null,
    ) {}

    public static function existing(string $relationship, string $id, ?MatchBehavior $behavior = null): self
    {
        return new self(
            relationship: $relationship,
            action: RowMatchAction::Update,
            id: $id,
            behavior: $behavior,
        );
    }

    public static function create(string $relationship, string $name, ?MatchBehavior $behavior = null): self
    {
        return new self(
            relationship: $relationship,
            action: RowMatchAction::Create,
            name: $name,
            behavior: $behavior,
        );
    }

    public function isExisting(): bool
    {
        return $this->action === RowMatchAction::Update;
    }

    public function isCreate(): bool
    {
        return $this->action === RowMatchAction::Create;
    }
}
```

Key change: `?MatchBehavior $behavior` added as optional property. Factory methods accept it. Backward-compatible (defaults to null for existing JSON data).

**Step 2: Commit**

```bash
git add app-modules/ImportWizard/src/Data/RelationshipMatch.php
git commit -m "refactor: add behavior field to RelationshipMatch for multi-column merge"
```

---

### Task 4: Remove canCreate from EntityLink

**Files:**
- Modify: `app-modules/ImportWizard/src/Data/EntityLink.php`

**Step 1: Remove canCreate property**

Remove from constructor:
- `@param  bool  $canCreate` docblock line
- `public readonly bool $canCreate = false,` property

Remove from `cloneWith()`:
- `canCreate: $overrides['canCreate'] ?? $this->canCreate,` line

Remove fluent method entirely:
```php
public function canCreate(bool $canCreate = true): self
{
    return $this->cloneWith(['canCreate' => $canCreate]);
}
```

**Step 2: Remove ->canCreate() calls from factory methods**

In `company()`: remove `->canCreate()`
In `contact()`: remove `->canCreate()`
In `polymorphicCompanies()`: remove `->canCreate()`
In `polymorphicPeople()`: remove `->canCreate()`

**Step 3: Remove canCreate logic from fromCustomField()**

Remove: `canCreate: $modelClass !== Opportunity::class,`

**Step 4: Add Name matcher to getUniqueMatchableFieldsForEntity()**

Update the method to include Name for Company/People:

```php
private static function getUniqueMatchableFieldsForEntity(string $modelClass): array
{
    return match ($modelClass) {
        Company::class => [
            MatchableField::id(),
            MatchableField::domain('custom_fields_domains'),
            MatchableField::name(),
        ],
        People::class => [
            MatchableField::id(),
            MatchableField::email('custom_fields_emails'),
            MatchableField::name(),
        ],
        default => [
            MatchableField::id(),
        ],
    };
}
```

**Step 5: Commit**

```bash
git add app-modules/ImportWizard/src/Data/EntityLink.php
git commit -m "refactor: remove canCreate from EntityLink, add Name matcher to custom field entity links"
```

---

### Task 5: Update Importers (User-Targeting Links)

**Files:**
- Modify: `app-modules/ImportWizard/src/Importers/CompanyImporter.php`
- Modify: `app-modules/ImportWizard/src/Importers/TaskImporter.php`

**Step 1: CompanyImporter — set Email to UpdateOnly for account_owner**

In `defineEntityLinks()`, change:
```php
MatchableField::email('email'),
```
To:
```php
MatchableField::email('email', MatchBehavior::UpdateOnly),
```

Add `use Relaticle\ImportWizard\Enums\MatchBehavior;` import.

**Step 2: TaskImporter — set Email to UpdateOnly for assignees**

Same change in `defineEntityLinks()`:
```php
MatchableField::email('email', MatchBehavior::UpdateOnly),
```

Add `use Relaticle\ImportWizard\Enums\MatchBehavior;` import.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Importers/CompanyImporter.php app-modules/ImportWizard/src/Importers/TaskImporter.php
git commit -m "refactor: set Email to UpdateOnly for User-targeting entity links"
```

---

### Task 6: Update ValidateColumnJob

**Files:**
- Modify: `app-modules/ImportWizard/src/Jobs/ValidateColumnJob.php`

**Step 1: Update writeEntityLinkRelationships()**

Line 114 — `AlwaysCreate` check stays (same case name, no change needed).

Line 129 — Replace `UpdateOnly` check to skip for both `UpdateOnly` (unchanged):
```php
// No change needed — UpdateOnly still exists as a case
if ($resolvedId === null && $matcher->behavior === MatchBehavior::UpdateOnly) {
    continue;
}
```

Lines 133-135 — Pass behavior to RelationshipMatch factory methods:
```php
$match = $resolvedId !== null
    ? RelationshipMatch::existing($link->key, (string) $resolvedId, $matcher->behavior)
    : RelationshipMatch::create($link->key, (string) $value, $matcher->behavior);
```

**Step 2: Commit**

```bash
git add app-modules/ImportWizard/src/Jobs/ValidateColumnJob.php
git commit -m "refactor: pass matcher behavior to RelationshipMatch in ValidateColumnJob"
```

---

### Task 7: Update MatchResolver

**Files:**
- Modify: `app-modules/ImportWizard/src/Support/MatchResolver.php`

**Step 1: Replace UpdateOnly in unmatched action logic**

Line 38 — No change needed (checks `AlwaysCreate`, which still exists).

Line 80 — `UpdateOnly` still exists, so the check is the same:
```php
$unmatchedAction = $matchField->behavior === MatchBehavior::UpdateOnly
    ? RowMatchAction::Skip
    : RowMatchAction::Create;
```

No change needed. Verify by reading the file.

---

### Task 8: Update ExecuteImportJob — Multi-Column Merge Logic

**Files:**
- Modify: `app-modules/ImportWizard/src/Jobs/ExecuteImportJob.php`

**Step 1: Rewrite resolveEntityLinkRelationships()**

Replace the method to group relationships by key and merge multi-column entries:

```php
private function resolveEntityLinkRelationships(
    ImportRow $row,
    array &$data,
    BaseImporter $importer,
    array $context,
): array {
    if ($row->relationships === null || $row->relationships->count() === 0) {
        return [];
    }

    $entityLinks = $importer->entityLinks();
    $pending = [];

    $grouped = $row->relationships->groupBy('relationship');

    foreach ($grouped as $linkKey => $matches) {
        $link = $entityLinks[$linkKey] ?? null;

        if ($link === null) {
            continue;
        }

        $resolvedId = $this->resolveGroupedMatches($matches, $link, $context);

        if ($resolvedId === null) {
            continue;
        }

        $storageStrategy = $link->getStorageStrategy();
        $data = $storageStrategy->prepareData($data, $link, [$resolvedId]);

        $pending[] = [
            'link' => $link,
            'strategy' => $storageStrategy,
            'ids' => [$resolvedId],
        ];
    }

    return $pending;
}
```

**Step 2: Add resolveGroupedMatches() method**

```php
/** @param  Collection<int, RelationshipMatch>  $matches */
private function resolveGroupedMatches(
    Collection $matches,
    EntityLink $link,
    array $context,
): ?string {
    foreach ($matches as $match) {
        if ($match->isExisting() && $match->id !== null) {
            return $match->id;
        }
    }

    $creationName = $this->resolveCreationName($matches);

    if ($creationName === null) {
        return null;
    }

    $dedupKey = "{$link->key}:" . mb_strtolower(trim($creationName));

    if (isset($this->createdRecords[$dedupKey])) {
        return $this->createdRecords[$dedupKey];
    }

    /** @var Model $record */
    $record = new $link->targetModelClass;
    $record->forceFill([
        'name' => $creationName,
        'team_id' => $context['team_id'],
        'creator_id' => $context['creator_id'],
        'creation_source' => CreationSource::IMPORT,
    ]);
    $record->save();

    $id = (string) $record->getKey();
    $this->createdRecords[$dedupKey] = $id;

    return $id;
}
```

**Step 3: Add resolveCreationName() method**

```php
/** @param  Collection<int, RelationshipMatch>  $matches */
private function resolveCreationName(Collection $matches): ?string
{
    $alwaysCreate = $matches->first(
        fn (RelationshipMatch $m): bool => $m->isCreate() && $m->behavior === MatchBehavior::AlwaysCreate
    );

    $findOrCreate = $matches->first(
        fn (RelationshipMatch $m): bool => $m->isCreate() && $m->behavior === MatchBehavior::FindOrCreate
    );

    if ($findOrCreate !== null && $alwaysCreate !== null) {
        return blank($alwaysCreate->name) ? null : $alwaysCreate->name;
    }

    if ($alwaysCreate !== null) {
        return blank($alwaysCreate->name) ? null : $alwaysCreate->name;
    }

    if ($findOrCreate !== null) {
        return blank($findOrCreate->name) ? null : $findOrCreate->name;
    }

    return null;
}
```

**Step 4: Delete the old resolveMatchId() method**

The logic is now in `resolveGroupedMatches()` + `resolveCreationName()`. Remove `resolveMatchId()` entirely.

**Step 5: Add missing imports**

Add `use Illuminate\Support\Collection;` if not already present.

**Step 6: Commit**

```bash
git add app-modules/ImportWizard/src/Jobs/ExecuteImportJob.php
git commit -m "refactor: multi-column merge logic in ExecuteImportJob, remove canCreate guard"
```

---

### Task 9: Update MappingStep — Allow Multi-Column Entity Links

**Files:**
- Modify: `app-modules/ImportWizard/src/Livewire/Steps/MappingStep.php`

**Step 1: Add method to get mapped matchers per entity link**

Add a new method:

```php
/** @return array<string, list<string>> */
public function getMappedEntityLinkMatchers(): array
{
    return collect($this->columns)
        ->filter(fn (array $m): bool => ($m['entityLink'] ?? null) !== null)
        ->groupBy(fn (array $m): string => $m['entityLink'])
        ->map(fn (Collection $group): array => $group->pluck('target')->values()->all())
        ->all();
}
```

**Step 2: Keep isEntityLinkMapped() for auto-mapping**

`isEntityLinkMapped()` stays as-is — it's used by `autoMapEntityLinks()` to prevent auto-mapping when a link already has a column. This is correct behavior for auto-mapping (map one column per link automatically). Users can manually add more.

**Step 3: Commit**

```bash
git add app-modules/ImportWizard/src/Livewire/Steps/MappingStep.php
git commit -m "refactor: add getMappedEntityLinkMatchers for per-matcher tracking"
```

---

### Task 10: Update Blade Templates — Per-Matcher Mapping State

**Files:**
- Modify: `app-modules/ImportWizard/resources/views/livewire/steps/mapping-step.blade.php`
- Modify: `app-modules/ImportWizard/resources/views/components/field-select.blade.php`

**Step 1: Update mapping-step.blade.php**

Change the `$mappedEntityLinkKeys` computation (lines 29-33) to pass per-matcher data:

```php
$allMappedMatchers = $this->getMappedEntityLinkMatchers();
$mappedEntityLinkMatchers = [];
foreach ($allMappedMatchers as $linkKey => $matcherFields) {
    $currentMappingMatcher = ($mapping?->entityLink === $linkKey) ? $mapping?->target : null;
    $mappedEntityLinkMatchers[$linkKey] = array_values(
        array_filter($matcherFields, fn ($f) => $f !== $currentMappingMatcher)
    );
}
```

Update the field-select component call to pass the new data:

```blade
<x-import-wizard-new::field-select
    :fields="$this->allFields"
    :entity-links="$this->entityLinks"
    :selected="$mapping"
    :mapped-field-keys="$this->mappedFieldKeys"
    :mapped-entity-link-matchers="$mappedEntityLinkMatchers"
    :column="$header"
/>
```

**Step 2: Update field-select.blade.php props**

Replace `'mappedEntityLinks' => []` with `'mappedEntityLinkMatchers' => []`.

**Step 3: Update entity link "in use" logic**

Replace the `$isLinkMapped` check (line 211):

```php
$linkMappedMatchers = $mappedEntityLinkMatchers[$linkKey] ?? [];
$isLinkFullyMapped = count($linkMappedMatchers) >= count($link->matchableFields) && !$isLinkSelected;
$isLinkPartiallyMapped = count($linkMappedMatchers) > 0 && !$isLinkFullyMapped;
```

Use `$isLinkFullyMapped` instead of `$isLinkMapped` for the disabled state on the entity link button. Only fully-mapped links (all matchers used) are disabled.

**Step 4: Update matcher submenu — disable used matchers**

In the matcher loop (line 279-305), add per-matcher disabled state:

```php
$isMatcherMappedElsewhere = in_array($matcher->field, $linkMappedMatchers ?? []);
```

Note: `$linkMappedMatchers` is not available inside the teleported submenu since it's computed per-row. Pass it via the component or compute it at the template level. Since the submenus are in a `@foreach ($entityLinks ...)` loop outside the rows, we need to get the matchers from the parent context. Use `$this->getMappedEntityLinkMatchers()[$linkKey] ?? []` directly.

Update the matcher button to be disabled when already mapped by another column:

```php
@php
    $globalMappedMatchers = $this->getMappedEntityLinkMatchers()[$linkKey] ?? [];
    $isMatcherUsed = in_array($matcher->field, $globalMappedMatchers) && !$isMatcherSelected;
@endphp
```

Apply disabled state to the button (similar pattern to field "in use"):
- Add `{{ $isMatcherUsed ? 'disabled' : '' }}`
- Add opacity/cursor classes when disabled
- Show "in use" badge when mapped elsewhere

**Step 5: Commit**

```bash
git add app-modules/ImportWizard/resources/views/livewire/steps/mapping-step.blade.php app-modules/ImportWizard/resources/views/components/field-select.blade.php
git commit -m "feat: allow multi-column entity link mapping with per-matcher tracking"
```

---

### Task 11: Update EntityLinkValidator (Verify — No Changes Needed)

**Files:**
- Verify: `app-modules/ImportWizard/src/Support/EntityLinkValidator.php`

Lines 24 and 47 check `MatchBehavior::AlwaysCreate` — this case still exists.
Lines do NOT reference `UpdateOnly`, `FindOrCreate`, or `canCreate`.

Verify by reading the file. No changes needed.

---

### Task 12: Run Full Test Suite and Fix

**Step 1: Run all ImportWizard tests**

Run: `php artisan test --compact --filter=ImportWizard`

Expected failures to fix:
- `'returns validation error for UpdateOnly matcher when record not found'` — test name references `UpdateOnly`, which still exists. Test should pass. If it doesn't, investigate.
- `'skips relationship for UpdateOnly when no match found'` — should still pass.
- `'skips auto-creation when canCreate is false'` — will fail if the test relies on `canCreate` being false on the Opportunity entity link. The Opportunity link now only has `UpdateOnly` matchers, so `ValidateColumnJob` wouldn't produce a `create` entry. Update the test: the scenario is now about an `UpdateOnly` matcher that doesn't produce create entries.

Fix the `canCreate` test:
- Rename to `'does not create records for UpdateOnly matchers'`
- Ensure the RelationshipMatch JSON in the test reflects the new behavior (behavior field, or null for backward compat)

**Step 2: Run PHPStan**

Run: `vendor/bin/phpstan analyse app-modules/ImportWizard/src/`

Fix any type errors from the refactor. Common issues:
- `canCreate` property access on EntityLink
- Nullable `?MatchBehavior` where non-nullable expected

**Step 3: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

**Step 4: Commit fixes**

```bash
git add -A
git commit -m "fix: update tests and resolve static analysis issues after MatchBehavior refactor"
```

---

### Task 13: Final Verification

**Step 1: Run full test suite one more time**

Run: `php artisan test --compact --filter=ImportWizard`
Expected: ALL PASS

**Step 2: Run PHPStan clean**

Run: `vendor/bin/phpstan analyse app-modules/ImportWizard/src/`
Expected: No new errors

**Step 3: Summary**

Report what changed and any edge cases to watch for.

---

## Summary of All Changes

| File | What changes |
|------|-------------|
| `MatchBehavior.php` | Add `FindOrCreate` case. Add `description()`, `performsLookup()`, `createsOnNoMatch()`. |
| `MatchableField.php` | `?MatchBehavior` → `MatchBehavior`. Default `FindOrCreate`. `$behavior` param on email/domain/phone. `description()` delegates to enum. |
| `RelationshipMatch.php` | Add `?MatchBehavior $behavior` property. Update factory methods. |
| `EntityLink.php` | Remove `canCreate` property, fluent method, all `->canCreate()` calls, `fromCustomField()` canCreate logic. Add Name to `getUniqueMatchableFieldsForEntity()`. |
| `CompanyImporter.php:55` | `MatchableField::email('email', MatchBehavior::UpdateOnly)` |
| `TaskImporter.php:64` | `MatchableField::email('email', MatchBehavior::UpdateOnly)` |
| `ValidateColumnJob.php:133-135` | Pass `$matcher->behavior` to `RelationshipMatch` factory methods. |
| `MatchResolver.php` | No changes (`UpdateOnly` and `AlwaysCreate` cases still exist). |
| `ExecuteImportJob.php` | Group-and-merge logic. New `resolveGroupedMatches()` + `resolveCreationName()`. Remove `resolveMatchId()`. |
| `MappingStep.php` | Add `getMappedEntityLinkMatchers()`. |
| `mapping-step.blade.php` | Pass per-matcher mapping data to field-select. |
| `field-select.blade.php` | Per-matcher "in use" state. Allow selecting unused matchers on partially-mapped links. |
| `EntityLinkValidator.php` | No changes (only uses `AlwaysCreate`). |
