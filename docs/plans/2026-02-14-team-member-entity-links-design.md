# Team Member EntityLinks Design

## Problem

CompanyImporter's `account_owner_email` and TaskImporter's `assignee_email` are defined as flat ImportFields with hardcoded email-only resolution via `resolveTeamMemberByEmail()`. This causes:

1. **Allow-list bypass** — CompanyImporter resolves `account_owner_email` → `account_owner_id` in `prepareForSave`, but `account_owner_id` isn't in the field allow-list, so it gets silently stripped by `array_intersect_key`.
2. **No matcher selection** — Users can't choose how to match team members (email vs ID). The entity link system already provides this UX for CRM entities.
3. **Missing team owners** — `resolveTeamMemberByEmail` only checks the `team_user` pivot, missing Jetstream team owners (stored on `teams.user_id`).

## Solution

Replace both flat ImportFields with proper EntityLinks. Users get the existing submenu UX to choose "Match by: Email" or "Match by: Record ID".

## EntityLink Definitions

### CompanyImporter

```php
'account_owner' => EntityLink::belongsTo('account_owner', User::class)
    ->matchableFields([
        MatchableField::id(),
        MatchableField::email('email'),
    ])
    ->foreignKey('account_owner_id')
    ->label('Account Owner')
    ->guess([...])
```

### TaskImporter

```php
'assignee' => EntityLink::morphToMany('assignees', User::class)
    ->matchableFields([
        MatchableField::id(),
        MatchableField::email('email'),
    ])
    ->label('Assignee')
    ->guess([...])
```

Both use `canCreate: false` (default). Unmatched values silently produce null/skip.

## EntityLinkResolver Changes

Add `resolveViaTeamMember()` method. Triggered when `$link->targetModelClass === User::class`.

Queries `users` table with team membership scope:
- `whereHas('teams', ...)` for pivot members
- `orWhereHas('ownedTeams', ...)` for team owners

## Data Flow

```
CSV: "owner@company.com"
  → MappingStep: user maps column → EntityLink "Account Owner" → submenu → "Email"
  → ColumnData: { source: "Owner", target: "email", entityLink: "account_owner" }
  → ExecuteImportJob.resolveEntityLinkRelationships():
      → EntityLinkResolver.batchResolve() → resolveViaTeamMember('email', [...])
      → ForeignKeyStorage.prepareData() → sets $data['account_owner_id'] = resolved_user_id
  → forceFill + save → done
```

For Task's assignee (MorphToMany):
```
CSV: "user@company.com"
  → same mapping flow
  → MorphToManyStorage.store() → $record->assignees()->syncWithoutDetaching([userId])
```

## Changes Summary

| File | Change |
|------|--------|
| `EntityLinkResolver` | Add `resolveViaTeamMember()`, detect via `targetModelClass === User::class` |
| `CompanyImporter` | Move `account_owner` to `defineEntityLinks()`, remove from `fields()`, remove `prepareForSave`/`afterSave` |
| `TaskImporter` | Move `assignee` to `defineEntityLinks()`, remove from `fields()`, remove `prepareForSave`/`afterSave` |
| `BaseImporter` | Remove `resolveTeamMemberByEmail()` |
| Tests | Update existing account_owner tests, add team member resolution tests |

## No Changes Needed

- `field-select.blade.php` — already renders entity link submenus
- `MappingStep` — already handles entity link auto-mapping
- `ForeignKeyStorage` / `MorphToManyStorage` — already handle both storage patterns
- `MatchableField` — `email('email')` already works (field key = 'email' column)
