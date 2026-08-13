---
title: Update existing records with an import
description: Match rows by ID, email, phone, or domain to update records in place instead of creating duplicates.
order: 3
updated: "2026-08-13"
related: [help/import/what-each-record-type-needs, help/import/fix-import-errors]
---

When a CSV column uniquely identifies a record — an ID, an email, a domain —
the import wizard matches rows to your existing data and updates those records
instead of duplicating them. You choose the match method while mapping
columns, and the preview shows exactly what will be created versus updated
before anything changes.

## Match methods

| Method | Behaviour | Available for |
|--------|----------|---------------|
| **Record ID** | Updates that exact record; skips the row if the ID isn't found | All record types |
| **Domain** | Finds the existing company by domain, or creates a new one | Companies |
| **Email** | Finds the existing person by email, or creates a new one | People |
| **Phone** | Finds the existing person by phone, or creates a new one | People |
| **Name** | Always creates a new record — names aren't unique | Companies, People |

The preview step then labels every row:

- Matched to an existing record → **Update**
- No match, or matched by Name → **Create new**
- A Record ID that doesn't exist → **Skip**

If you don't map any matchable column, the wizard warns you that every row
will be created as new. You can go back and map one, or continue anyway.

## How updates behave

- **Blank cells are ignored.** An empty CSV cell preserves the existing value
  — include only the columns you want to change.
- **Multi-value fields merge.** Emails, phone numbers, tags, and multi-select
  values are added to what's already on the record, not replaced.
- **Duplicates within the file collapse.** If two rows resolve to the same
  record (say, the same email twice), the second row updates the first's
  result instead of creating a duplicate.

## Update by Record ID

For precise updates, start from an export:

1. **Export** the records you want to change — the file includes an `id`
   column.
2. **Edit** the CSV, keeping the `id` column intact.
3. **Re-import** — rows with a valid ID update those exact records.

```
id,name,custom_fields_industry
01KCCFMZ52QWZSQZWVG0AP704V,Acme Corporation,Software
01KCCFN1A8XVQR4ZFWB3KC5M7P,TechStart Inc,Hardware
```

## Create and update in one file

Rows with an ID update; rows with a blank ID create:

```
id,name
01KCCFMZ52QWZSQZWVG0AP704V,Update This Company
,New Company (blank ID = create new)
```
