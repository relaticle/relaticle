---
title: Export your data
description: Download any record type as CSV or Excel — full lists or selected rows, custom fields included.
order: 5
updated: "2026-08-13"
related: [help/import/update-existing-records, help/import/prepare-your-csv]
---

Every record type — companies, people, opportunities, tasks, and notes — can
be exported to CSV or Excel. Exports include the record IDs and every custom
field, which makes an export the perfect starting template for a
[bulk update by import](/help/import/update-existing-records).

## Export a list

1. Open the record type's list.
2. Click **Import / Export**, then **Export**.
3. Choose the columns and format.

To export only some records, select their rows first and use the **Export**
bulk action instead.

## What you get

The export runs in the background — when it finishes, a notification appears
with a **Download .csv** (or **.xlsx**) link. The file contains each record's
ID, its core fields, the workspace, who created it and how, timestamps, and
a column per custom field. Company exports also include the account owner
and the number of linked people and opportunities.

## Exports as backup

Because normal deletes are [restorable](/help/records/restore-deleted-records)
but force deletes are not, take an export before any large clean-up — it's
the cheapest undo button you'll ever have.
