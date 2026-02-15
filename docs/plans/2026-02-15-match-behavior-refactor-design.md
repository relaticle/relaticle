# MatchBehavior Refactor: 3-Case Enum + Multi-Column Entity Links

## Context

`MatchBehavior` currently has 2 enum cases (`UpdateOnly`, `AlwaysCreate`) plus a `null` default that means "find or create." The `null` default combines with `EntityLink.canCreate` to determine actual runtime behavior, creating a split source of truth:

- `null` + `canCreate=true` → find or create (Company/People links)
- `null` + `canCreate=false` → find only (User/Opportunity links)

This causes two bugs:
1. **Misleading UI descriptions**: Email matcher on User links says "Find existing record or create new if not found" but actually skips when not found (because `canCreate=false`).
2. **Garbage data on creation**: When Email/Domain/Phone matchers trigger creation (`null` + `canCreate=true`), they create records with the lookup value as name (e.g., Company named "acme.com"). Unlike Attio, we don't have data enrichment to derive proper names from domains/emails.

## Design

### 3 explicit enum cases

```php
enum MatchBehavior: string
{
    case MatchOnly = 'match_only';           // Record ID. Lookup, skip if not found.
    case MatchOrCreate = 'match_or_create';  // Email/Domain/Phone. Lookup, create if not found.
    case Create = 'create';                  // Name. No lookup, always create.
}
```

### Every MatchableField gets explicit behavior

No more `null` default. `?MatchBehavior` becomes `MatchBehavior` (non-nullable).

| Matcher | Default Behavior | Description |
|---------|-----------------|-------------|
| Record ID | MatchOnly | "Only update existing records. Skip if not found." |
| Email | MatchOrCreate | "Find existing record or create new if not found." |
| Domain | MatchOrCreate | "Find existing record or create new if not found." |
| Phone | MatchOrCreate | "Find existing record or create new if not found." |
| Name | Create | "Always create a new record (no lookup)." |

Descriptions match the existing UI. Email/Domain/Phone factory methods accept an optional `$behavior` parameter to override the default (e.g., `MatchOnly` for User-targeting links).

### Eliminate `EntityLink.canCreate`

- Remove `canCreate` property, fluent method, and all `->canCreate()` calls
- Remove `canCreate` logic from `fromCustomField()`
- Behavior is now owned entirely by `MatchBehavior` on each `MatchableField`

### Description moves to enum

```php
enum MatchBehavior: string
{
    public function description(): string
    {
        return match ($this) {
            self::MatchOnly => 'Only update existing records. Skip if not found.',
            self::MatchOrCreate => 'Find existing record or create new if not found.',
            self::Create => 'Always create a new record (no lookup).',
        };
    }

    public function performsLookup(): bool
    {
        return $this !== self::Create;
    }

    public function createsOnNoMatch(): bool
    {
        return $this === self::MatchOrCreate || $this === self::Create;
    }
}
```

### Multi-column entity link mapping

Allow multiple CSV columns to map to the same entity link, each using a different matcher. This solves the garbage data problem: Domain does the lookup, Name provides the creation data.

**Example flow:**
- CSV columns: `company_domain`, `company_name`
- Column A → Company > Domain (MatchOrCreate)
- Column B → Company > Name (Create)

**Per-row processing:**
1. Domain lookup finds match → use existing Company (ignore Name column)
2. Domain lookup fails → create Company using Name column's value ("Acme Corp", not "acme.com")
3. Only Name mapped (no lookup column) → always create Company with that name

**Data structure change — `RelationshipMatch` gets `behavior` field:**

```php
final class RelationshipMatch extends Data
{
    public function __construct(
        public readonly string $relationship,
        public readonly RowMatchAction $action,
        public readonly ?string $id = null,
        public readonly ?string $name = null,
        public readonly ?MatchBehavior $behavior = null,
    ) {}
}
```

Each `ValidateColumnJob` writes a `RelationshipMatch` with the matcher's behavior. `ExecuteImportJob` groups entries by relationship key and applies merge logic:

1. **Existing match wins**: If any entry is `existing` (lookup found a record), use that ID
2. **Create provides name**: Among `create` entries, prefer the Create matcher's name for record creation
3. **Fallback**: MatchOrCreate without a sibling Create matcher uses the lookup value as name (acceptable — user chose not to provide a Name column)

**UI changes — per-matcher mapping state:**

Instead of disabling an entire entity link when one column maps to it, show which specific matchers are already in use. Users can map additional columns to unused matchers on the same link.

### Importer changes

User-targeting entity links explicitly set Email to MatchOnly:

| Importer | Entity Link | Email Behavior |
|----------|-------------|---------------|
| CompanyImporter | account_owner → User | `MatchOnly` |
| TaskImporter | assignees → User | `MatchOnly` |

Custom field entity links via `getUniqueMatchableFieldsForEntity()` gain Name matcher for Company/People targets, enabling multi-column mapping:

| Target | Matchers |
|--------|----------|
| Company | `[id(MatchOnly), domain(MatchOrCreate), name(Create)]` |
| People | `[id(MatchOnly), email(MatchOrCreate), name(Create)]` |
| Opportunity | `[id(MatchOnly)]` |

## Files to modify

| File | Change |
|------|--------|
| `MatchBehavior.php` | 3 cases: `MatchOnly`, `MatchOrCreate`, `Create` + `description()`, `performsLookup()`, `createsOnNoMatch()` |
| `MatchableField.php` | `?MatchBehavior` → `MatchBehavior`. Factory methods explicit. `$behavior` param on email/domain/phone. `description()` delegates to enum. `isAlwaysCreate()` → `isCreate()`. |
| `RelationshipMatch.php` | Add `?MatchBehavior $behavior` property. Update factory methods. |
| `EntityLink.php` | Remove `canCreate` property, fluent method, all calls. Add Name to `getUniqueMatchableFieldsForEntity()`. |
| `MatchResolver.php:38,80` | Replace `AlwaysCreate` → `Create`, `UpdateOnly` → `MatchOnly`. |
| `ValidateColumnJob.php:114,129` | Replace `AlwaysCreate` → `Create`, `UpdateOnly` → `MatchOnly`. Pass behavior to `RelationshipMatch`. |
| `ExecuteImportJob.php:618-692` | Group relationships by key. Multi-column merge logic. Remove `$link->canCreate`. |
| `MappingStep.php:225-229` | Replace `isEntityLinkMapped()` with per-matcher mapping tracking. |
| `field-select.blade.php:211,280-305` | Per-matcher "in use" state instead of whole-link disable. `isAlwaysCreate()` → `isCreate()`. |
| `CompanyImporter.php:55` | `MatchableField::email('email', MatchBehavior::MatchOnly)` |
| `TaskImporter.php:64` | `MatchableField::email('email', MatchBehavior::MatchOnly)` |
| `EntityLinkValidator.php:24,47` | Replace `AlwaysCreate` → `Create`. |
| `MappingStep.php:316,331` | `isAlwaysCreate()` → `isCreate()`. |

## Verification

1. `php artisan test --compact --filter=ImportWizard`
2. `vendor/bin/phpstan analyse app-modules/ImportWizard/src/`
3. `vendor/bin/pint --dirty --format agent`
4. Manual UI check: Domain matcher on Company should show "Find existing record or create new if not found."
5. Manual UI check: Email matcher on Account Owner should show "Only update existing records. Skip if not found."
6. Manual UI check: Map two columns to same Company link (Domain + Name) — both should appear mapped.
7. Manual import: MatchOrCreate with Name column → unmatched domain creates Company with proper name.
