---
title: Fix import errors
description: What to do when an upload, mapping, or validation step fails — and how ambiguous dates are handled.
order: 4
updated: "2026-08-13"
related: [help/import/prepare-your-csv, help/import/update-existing-records]
---

Most import problems are one of four kinds — the file won't upload, a column
won't map, values fail validation, or the import itself misbehaves. Find the
symptom below; each fix is something you can do yourself.

## Upload problems

| Problem | Fix |
|---------|----------|
| File too large | Split into files under 10,000 rows each |
| Invalid format | Re-save as "CSV UTF-8" from Excel |
| Upload fails | Check your connection, then try a smaller file |

## Mapping problems

| Problem | Fix |
|---------|----------|
| A column didn't auto-map | Pick the field from the **Map To** dropdown yourself |
| A custom field is missing from the dropdown | Check the field's code in **Settings → Custom Fields** |
| A required field shows red | Map one of your CSV columns to it |

## Validation problems

| Problem | Fix |
|---------|----------|
| Invalid email | Correct the format: `user@domain.com` |
| Invalid date | Use ISO, European, or American format consistently |
| Invalid Record ID | Re-export to get fresh IDs |
| Unknown select option | Use the exact option label from the field's settings |

In the review step you can also **Fix** a value once to correct it across
every row that has it, or **Skip** a value — the rows still import, with that
one field left empty.

## Ambiguous dates

The wizard reads three date formats and detects which one a column uses:

| Format | Pattern | Example |
|--------|---------|---------|
| ISO | YYYY-MM-DD | `2024-05-15` |
| European | DD/MM/YYYY | `15/05/2024` |
| American | MM/DD/YYYY | `05/15/2024` |

When a day value is greater than 12 the format is unambiguous —
`31/01/2024` can only be European. When both positions are 12 or less
(`01/02/2024` — January 2 or February 1?), the wizard shows a warning and a
format dropdown on that column: pick **European** or **American** to say
which reading is correct. ISO dates are never ambiguous, which is why they're
the safest format to export from other tools.

## Import problems

| Problem | Fix |
|---------|----------|
| Stuck at "Processing" | Large imports run in the background — check back in a few minutes |
| Some rows failed | Download the failed rows, fix them, re-import just those |
| Unexpected duplicates | Map a unique column (email, domain, or ID) — see [Update existing records](/help/import/update-existing-records) |

## Good to know

- **There is no undo.** Test with a 5-10 row sample before the full file.
- **A failed import keeps its successful rows.** Fix and re-import only the
  downloaded failures.
- **One record type per file.** Importing people can still auto-create their
  companies if you match the Company relationship by Name.
- **Every run is logged.** The **Import History** page under your workspace
  name shows created, updated, skipped, and failed counts for each import.
