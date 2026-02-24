# Sentry Investigation: CRM-3B & CRM-3A

**Date:** 2026-02-24 10:00 UTC  
**Severity:** 🔴 CRITICAL (Production errors)  
**Location:** Vendor package `relaticle/custom-fields`

## Summary

Two critical bugs discovered in the `relaticle/custom-fields` package causing production errors:

1. **CRM-3B** - Class "people" not found (ACTIVE: 4 events in 3 minutes)
2. **CRM-3A** - Method toCollection() doesn't exist (1 event during upgrade)

## Issue CRM-3B: Class "people" not found

**Sentry:** https://relaticle.sentry.io/issues/RELATICLE-CRM-3B  
**Status:** ACTIVE (last seen 1 minute ago)  
**Events:** 4 total  
**First seen:** 2026-02-24 09:57:25 UTC

### Error
```
Error: Class "people" not found
```

Also occurs with "company" - the error message shows whichever entity type is being validated.

### Root Cause

**File:** `vendor/relaticle/custom-fields/src/Rules/UniqueCustomFieldValue.php:47`

```php
// CURRENT CODE (BROKEN)
$morphAlias = Relation::getMorphAlias($entityType) ?? (new $entityType)->getMorphClass();
```

**The Problem:**
1. `$this->customField->entity_type` stores morph **aliases** like `'people'`, `'company'`
2. The code calls `Relation::getMorphAlias($entityType)` which expects a **class name**, not an alias
3. Returns `null` because `getMorphAlias('people')` doesn't work
4. Falls back to `new $entityType` → `new "people"` → **Fatal error**

**The Fix:**

```php
// Use getMorphedModel() for reverse lookup (alias → class)
$entityClass = Relation::getMorphedModel($entityType) ?? $entityType;
$morphAlias = Relation::getMorphAlias($entityClass) ?? (new $entityClass)->getMorphClass();
```

Or simpler:

```php
// If entity_type is already the alias, just use it directly
$morphAlias = $entityType;
```

Since `$this->customField->entity_type` already stores the morph alias (confirmed in `AppServiceProvider::configureModels()` where we set the morph map), we don't need the reverse lookup at all - just use it directly.

### Impact

- **Affects:** All custom field validation with unique constraints
- **Triggers:** Creating/editing records with unique custom fields (People, Company, etc.)
- **Frequency:** ACTIVE - happening in production right now

### Verification

The morph map is correctly configured in `app/Providers/AppServiceProvider.php:121-130`:

```php
Relation::enforceMorphMap([
    'team' => Team::class,
    'user' => User::class,
    'people' => People::class,
    'company' => Company::class,
    'opportunity' => Opportunity::class,
    'task' => Task::class,
    'note' => Note::class,
    'system_administrator' => SystemAdministrator::class,
]);
```

## Issue CRM-3A: Method toCollection() doesn't exist

**Sentry:** https://relaticle.sentry.io/issues/RELATICLE-CRM-3A  
**Status:** 1 occurrence  
**First seen:** 2026-02-24 09:10:23 UTC

### Error
```
BadMethodCallException: Method Illuminate\Support\Collection::toCollection does not exist.
```

### Root Cause

**File:** `vendor/relaticle/custom-fields/src/Console/Commands/Upgrade/Steps/CleanMultiValueValidationRulesStep.php:72`

```php
// CURRENT CODE (BROKEN)
$rules = $field->validation_rules?->toCollection() ?? collect();
```

**The Problem:**
- Laravel's `Collection` class doesn't have a `toCollection()` method
- Likely a typo or misunderstanding of Laravel's Collection API

**The Fix:**

```php
// Option 1: If validation_rules is already a Collection, just use it
$rules = $field->validation_rules ?? collect();

// Option 2: If it might be JSON, decode it
$rules = is_string($field->validation_rules)
    ? collect(json_decode($field->validation_rules, true))
    : ($field->validation_rules ?? collect());
```

### Impact

- **Affects:** Custom fields upgrade command (`php artisan custom-fields:upgrade`)
- **Triggers:** Running migration/upgrade scripts
- **Frequency:** Low (only during upgrades)

## Recommended Actions

### Immediate (This PR)

1. ✅ Document the issues
2. ✅ Alert maintainer (Manuk)
3. ⏳ Create PR to `relaticle/custom-fields` with fixes

### Short-term

1. Apply fixes to `relaticle/custom-fields` package
2. Release new package version
3. Update `composer.json` to require fixed version

### Long-term

1. Add integration tests for unique custom field validation
2. Add tests for upgrade commands

## Temporary Workaround

Until the package is fixed, affected production operations will fail. No safe workaround available without patching vendor code.

## Additional Notes

Both bugs are in the same vendor package (`relaticle/custom-fields`), suggesting the package may need:
- More comprehensive testing
- Better handling of morph maps
- Review of Collection API usage

---

**Next steps:**
1. Create fix PR to https://github.com/relaticle/custom-fields
2. Alert Manuk via Telegram
3. Monitor Sentry for additional events
