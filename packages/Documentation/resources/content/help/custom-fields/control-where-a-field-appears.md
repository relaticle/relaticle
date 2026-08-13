---
title: Control where a field appears
description: A field's settings decide its place in lists and record pages, and whether it can be searched or filtered.
order: 3
updated: "2026-08-13"
related: [help/custom-fields/add-and-manage-fields, help/getting-started/find-anything-with-search-and-filters]
---

Every field has a **Settings** section that controls where it shows up and
how it behaves. Open the field from the **Custom Fields** page to change
them; changes apply to the whole workspace immediately.

![The Edit field form's Settings section with toggles for Visible in List, Visible in View, Toggleable Hidden, Searchable, Encrypted, and Enable Color Options](/help-assets/custom-fields/control-where-a-field-appears-1.png)

## Visibility

| Setting | What it does |
|---------|--------------|
| Visible in List | Adds the field as a column on the record type's list page |
| Visible in View | Shows the field on the record's detail page |
| Toggleable Hidden | Keeps the list column available but hidden until someone enables it from the list's column toggle |

A field that's off in both places still exists on the create/edit form — use
this for data you capture but don't need to see day-to-day.

## Search, filters, and sorting

- **Searchable** lets the list's search box match this field's values.
- **Filters** appear automatically for choice fields — Select, Multi Select,
  Radio, Checkbox List, Toggle Buttons, Tags Input, and Record fields all get
  a filter on their list page. Text, number, and date fields don't.
- **Sorting** works on text, number, currency, and date columns. Multi-value
  and contact-type columns (emails, phones, links) don't sort.

## Data shape

| Setting | What it does |
|---------|--------------|
| Allow Multiple Values | Lets one record hold several values (with a cap you set, up to 20) |
| Unique Per Entity Type | No two records of this type can share a value — how Domains and Emails prevent duplicates |
| Encrypted | Stores values encrypted at rest; encrypted fields can't be sorted |
| Enable Color Options | For Select and Tags fields — gives each option a colour, shown as badges in lists and on the board |
