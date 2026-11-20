---
title: Merge duplicate records
description: Combine two duplicate companies or people into one new record. The right-hand record wins, gaps are filled from the other, and a merge cannot be undone.
order: 7
updated: "2026-11-20"
related: [help/records/company-records, help/records/people-records, help/records/restore-deleted-records]
---

Duplicates happen, usually after an import or a mailbox sync. Merging two
companies, or two people, gives you one record that keeps everything linked to
either of them. Members and Admins can merge. Viewers cannot.

## Start a merge

- **From the duplicate prompt.** A record page that has likely duplicates shows
  a section with their count. Expand it and click **Merge** next to the one you
  want. The dialog opens with that record already picked.
- **From the menu.** Open the record, click the **⋯** menu, and choose
  **Merge record**.

## In the dialog

1. Search for the other record by name, domain, or email, and pick it.
2. Check the two cards. Click **Swap records** to change which one wins.
3. Click **Merge records**.

The record on the right wins wherever both records hold a value. Gaps are
filled from the other record. Domains, emails, and multi-select values are
combined, with the winner's first.

## What you get

Merging creates a new record. Both originals are removed, and everything
linked to either of them moves to the new one: notes, tasks, emails, meetings,
people and opportunities, and the activity history. The history shows one
creation and a single entry saying who merged the records.

A list that grows past its limit keeps all its values. Merging raises the
workspace's limit for that attribute to the combined count, up to 20. Every
record of that type can then hold that many.

## Old links

Opening the link of a merged record shows **This record was merged** and a
**Go to record** button that leads to the new record.

## Merging from the API, MCP, and Rela

You can merge without the dialog. The REST API has a merge endpoint for
companies and for people, the MCP server offers a merge tool for each, and
Rela, the built-in AI assistant, proposes merges for you to approve. They
follow the same rule as the dialog.

## Good to know

- A merge cannot be undone. A merged record is not in the trash and cannot be
  [restored](/help/records/restore-deleted-records).
- A merge takes two records. Three duplicates take two merges.
- Only companies and people can be merged.
