# MatchBehavior Refactor: Explicit 2-Case Enum

## Context

`MatchBehavior` currently has 2 enum cases (`UpdateOnly`, `AlwaysCreate`) plus a `null` default that means "find or create." The `null` default combines with `EntityLink.canCreate` to determine actual runtime behavior, creating a split source of truth:

- `null` + `canCreate=true` → find or create (Company/People links)
- `null` + `canCreate=false` → find only (User/Opportunity links)

This causes two bugs:
1. **Misleading UI descriptions**: Email matcher on User links says "Find existing record or create new if not found" but actually skips when not found.
2. **Garbage data on creation**: When Email/Domain/Phone matchers trigger creation (null + canCreate=true), they create records with the lookup value as name (e.g., Company named "acme.com"). Unlike Attio, we don't have data enrichment to derive proper names from domains/emails.

## Design

### Replace with 2 explicit enum cases

```php
enum MatchBehavior: string
{
    case AlwaysCreate = 'always_create';  // Name matcher only. No lookup, always create.
    case FindOnly = 'find_only';          // All other matchers. Lookup, skip if not found.
}
```

### Every MatchableField gets explicit behavior

No more `null` default. No more `?MatchBehavior` — the type becomes `MatchBehavior` (non-nullable).

| Matcher | Behavior | Description |
|---------|----------|-------------|
| Record ID | FindOnly | "Find existing record. Skip if not found." |
| Email | FindOnly | "Find existing record. Skip if not found." |
| Domain | FindOnly | "Find existing record. Skip if not found." |
| Phone | FindOnly | "Find existing record. Skip if not found." |
| Name | AlwaysCreate | "Always create a new record (no lookup)." |

### Eliminate `EntityLink.canCreate`

- Remove `canCreate` property from EntityLink constructor
- Remove `canCreate()` fluent method
- Remove `->canCreate()` calls from all factory methods (company, contact, polymorphicCompanies, polymorphicPeople)
- Remove `canCreate` logic from `fromCustomField()` — no longer needed
- `ExecuteImportJob::resolveMatchId()` — replace `$link->canCreate` check with matcher behavior check

### Description moves to enum

```php
enum MatchBehavior: string
{
    // ...

    public function description(): string
    {
        return match ($this) {
            self::AlwaysCreate => 'Always create a new record (no lookup).',
            self::FindOnly => 'Find existing record. Skip if not found.',
        };
    }

    public function performsLookup(): bool
    {
        return $this !== self::AlwaysCreate;
    }

    public function skipsOnNoMatch(): bool
    {
        return $this === self::FindOnly;
    }
}
```

### Behavioral change

Email/Domain/Phone matchers on Company/People entity links will **no longer create records** when unmatched. They become FindOnly — lookup existing, skip if not found. Only the Name matcher creates records.

This fixes the bug where imports created Companies named "acme.com" or People named "john@example.com".

## Files to modify

| File | Change |
|------|--------|
| `app-modules/ImportWizard/src/Enums/MatchBehavior.php` | Replace with 2 cases + helper methods |
| `app-modules/ImportWizard/src/Data/MatchableField.php` | All matchers get explicit behavior. `?MatchBehavior` → `MatchBehavior`. Remove `description()` (moved to enum). |
| `app-modules/ImportWizard/src/Data/EntityLink.php` | Remove `canCreate` property, fluent method, and all `->canCreate()` calls. Remove canCreate logic from `fromCustomField()`. |
| `app-modules/ImportWizard/src/Support/MatchResolver.php:38,80` | `AlwaysCreate` check stays. Replace `UpdateOnly` with `FindOnly`. |
| `app-modules/ImportWizard/src/Jobs/ValidateColumnJob.php:114,129` | Replace `AlwaysCreate`/`UpdateOnly` checks with new enum cases. |
| `app-modules/ImportWizard/src/Support/EntityLinkValidator.php:24,47` | `AlwaysCreate` check stays (same case name). |
| `app-modules/ImportWizard/src/Jobs/ExecuteImportJob.php:668` | Replace `$link->canCreate` with behavior check on the matcher. |
| `app-modules/ImportWizard/src/Livewire/Steps/MappingStep.php:316,331` | `isAlwaysCreate()` stays (same case name). |
| `app-modules/ImportWizard/resources/views/components/field-select.blade.php:299,303` | Update to use enum's `description()`. |

## Verification

1. Run all ImportWizard tests: `php artisan test --compact --filter=ImportWizard`
2. PHPStan: `vendor/bin/phpstan analyse app-modules/ImportWizard/src/`
3. Pint: `vendor/bin/pint --dirty --format agent`
4. Manual UI check: Email matcher on Account Owner should show "Find existing record. Skip if not found."
5. Manual import test: import with Email matcher on Company link — unmatched emails should skip, not create garbage records
