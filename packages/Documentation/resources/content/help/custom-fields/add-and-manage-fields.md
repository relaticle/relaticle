---
title: Add, edit, and archive fields
description: Create fields on the Custom Fields page, reorder them, retire them safely, and understand system fields.
order: 2
updated: "2026-08-13"
related: [help/custom-fields/field-types-you-can-add, help/custom-fields/control-where-a-field-appears, help/getting-started/use-custom-fields]
---

Fields are managed on the **Custom Fields** page: click your workspace name at
the top of the sidebar and choose **Custom Fields**. Only the workspace owner
sees this page — everyone else uses the fields, the owner shapes them.

The page shows one tab per record type — Companies, People, Opportunities,
Tasks, Notes — with a count badge, and the fields of the selected type in a
table you can search with **Search fields...**.

![The Custom Fields page on the Opportunities tab, showing Amount, Close Date, and Stage with System badges plus a custom Deal Source field](/help-assets/custom-fields/add-and-manage-fields-1.png)

## Add a field

1. Pick the record type's tab.
2. Click **Add Field**.
3. Choose the **Type** — this is permanent, so check
   [Field types you can add](/help/custom-fields/field-types-you-can-add)
   first.
4. Name it (names must be unique per record type).
5. For choice types, add the **Options**.
6. Adjust the **Settings** toggles — where the field shows up, whether it's
   searchable — and save.

The field appears on the record form, the record page, and (if you enabled
it) the list view immediately, for everyone in the workspace. It's also
instantly usable by the AI assistant and the CSV import — no extra setup.

## Edit and reorder

Click a field's row to rename it, change its options, or adjust its settings
— everything except the type. Drag the handle at the start of each row to
reorder fields; forms and record pages follow the order you set.

## Archive instead of deleting

**Deactivate** moves a field to the collapsible **Archived** group at the
bottom of the table. It disappears from forms and lists, but every stored
value is kept — **Activate** brings the field and its data straight back.
This is the safe way to retire a field you might need again, and the way to
hide a field from the AI assistant.

**Delete** is only available on a field that is already archived, and it is
destructive: the field, its options, and **all values stored on your
records** are removed permanently. Relaticle asks you to confirm first.

## System fields

Fields with a **System** badge are the ones Relaticle itself relies on — a
person's Emails, a company's Domains, an opportunity's Amount, Close Date and
Stage, a task's Status and Priority, a note's Body. You can't rename, retype,
archive, or delete them, but you can still edit their settings — and for the
selects among them, their options:
[rename your pipeline stages](/help/custom-fields/edit-the-options-in-a-select-field)
any time.
