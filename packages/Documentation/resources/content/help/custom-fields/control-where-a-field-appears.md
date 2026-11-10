---
title: Control where a field appears
description: A field's settings decide its place in lists and record pages, and how it filters and sorts.
order: 3
updated: "2026-11-10"
related: [help/custom-fields/add-and-manage-fields, help/getting-started/find-anything-with-search-and-filters]
---

Every field has a **Settings** section that controls where it shows up and
how it behaves. Open the field from the **Custom Fields** page to change
them; changes apply to the whole workspace immediately.

![The Edit field form's Settings section with toggles for Visible in List, Visible in View, Toggleable Hidden, Encrypted, and Enable Color Options](/help-assets/custom-fields/control-where-a-field-appears-1.png)

## Visibility

| Setting | What it does |
|---------|--------------|
| Visible in List | Adds the field as a column on the record type's list page |
| Visible in View | Shows the field on the record's detail page |
| Toggleable Hidden | Keeps the list column available but hidden until someone enables it from the list's column toggle |

A field that's off in both places still exists on the create/edit form. Use
this for data you capture but don't need to see day-to-day.

## Filters and sorting

- **Filters** need no setting. Every field except an encrypted one can be
  filtered from the filter row above the list. See
  [Find anything with search and filters](/help/getting-started/find-anything-with-search-and-filters).
- **Search** needs no setting. Rela and the MCP server match the values of
  Text, Textarea, Email, Phone, and Link fields. They skip choice fields, Rich
  Editor, numbers, dates, and currency. The sidebar search matches names and titles.
- **Sorting** works on text, number, currency, and date columns. Multi-value
  and contact-type columns (emails, phones, links) don't sort.

## Data shape

| Setting | What it does |
|---------|--------------|
| Allow Multiple Values | Lets one record hold several values (with a cap you set, up to 20) |
| Unique Per Entity Type | No two records of this type can share a value; this is how Domains and Emails prevent duplicates |
| Encrypted | Stores values encrypted at rest; encrypted fields can't be sorted |
| Enable Color Options | For Select and Tags fields; gives each option a colour, shown as badges in lists and on the board |
