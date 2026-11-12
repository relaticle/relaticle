---
title: Export your data
description: Download any record type as CSV or Excel. Full lists or selected rows, custom attributes included.
order: 5
updated: "2026-11-12"
related: [help/import/update-existing-records, help/import/prepare-your-csv]
---

Every record type (companies, people, opportunities, tasks, and notes) can
be exported to CSV or Excel. Exports include the record IDs and every custom
attribute, which makes an export the perfect starting template for a
[bulk update by import](/help/import/update-existing-records).

## Export a list

1. Open the record type's list.
2. Click **Import / Export**, then **Export**.
3. Choose the columns and format.

On an opportunities or tasks board, the same button exports every record the filters match. That includes records in hidden columns and records with no value.

To export only some records, tick their rows first and click the export
button in the bar at the bottom of the list.

## What you get

The export runs in the background. When it finishes, a notification appears
with a **Download .csv** (or **.xlsx**) link. The file contains each record's
ID, its core fields, the workspace, who created it and how, timestamps, and
a column per custom attribute. Company exports also include the account owner
and the number of linked people and opportunities.

## Exports as backup

Because normal deletes are [restorable](/help/records/restore-deleted-records)
but force deletes are not, take an export before any large clean-up. It's
the cheapest undo button you'll ever have.
