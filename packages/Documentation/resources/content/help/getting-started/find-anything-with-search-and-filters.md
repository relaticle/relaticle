---
title: Find anything with search and filters
description: Find instructions for searching Relaticle records and filtering lists of companies, people, opportunities, tasks and notes.
order: 7
updated: "2026-11-10"
related: [help/getting-started/use-custom-fields, help/getting-started/import-your-existing-data]
---

Press **⌘K** (or click the search box in the sidebar) to search across every
record at once, or use the filter row on any list to narrow down what's
already in front of you.

1. Click the search box at the top of the sidebar, or press **⌘K**.
2. Type any part of a name. Results appear grouped by record type:
   **Companies**, **Opportunities**, and more.
3. Click a result to open it.

![The global search dropdown showing results for "Acme" grouped under Companies and Opportunities](/help-assets/getting-started/find-anything-with-search-and-filters-1.png)

## Find a record in a list

The lists (Companies, People, Opportunities, Tasks, Notes) have no search box.
To find a record by name in a list, add a filter on its name or title field with
the **contains** condition. The filter row above the table shows it, and you can
remove it from there.

## Filter a list

1. Click **Filter** in the row above the table. Once a filter is set, it
   shows as **+**.
2. Pick a field. Type to narrow the list of fields.
3. Pick a condition, such as **contains**, **is any of**, or **in the past**.
4. Enter or pick a value. The list updates once the filter is complete.

![The filter row above the Opportunities list with three chips: Stage is any of Negotiation/Review, Close Date in the next 30 days, and Amount greater than $10,000.00](/help-assets/getting-started/find-anything-with-search-and-filters-2.png)

Each filter shows as a chip in the row. Click its field, its condition or its
value to change it. Picking another field keeps the chip and clears its
condition and value. To remove it, click **⋮** on the chip, then **Remove filter**.
A record must match every chip to stay in the list. When the filters hide
every record, click **Clear filters**.

The row stays on one line. Scroll it sideways when it holds more chips than
fit.

## Use dates that stay relative

Pick **in the past**, **in the next**, or **this** to filter by a period,
such as the last 30 days or this month. A saved view keeps the period
relative, so it moves with the calendar. **Today** works the same way.

Fields that hold a member list you first, as your name followed by
**(me)**.

## Show deleted records

**Deleted records** sits at the bottom of the field list. Its chip shows
deleted records with the rest (**included**) or on their own (**only**).
Click the second part of the chip to switch. You see it only if you're
allowed to see deleted records.

## Sort and group a list

Click **Sort** to order the list by a column, flip the direction, or clear
it. Once a column is set, the button reads **Sorted by** and the column
name. On Tasks, **Group** groups the
list by status or priority.

Filters, sort, and grouping save with the view. When you change them,
**Discard changes** and **Save** appear at the right of the row.

## If you can't find a field in the filter list

Every custom field can be filtered except Textarea, Rich Editor, and Color
Picker fields. Encrypted fields can't be filtered either, and neither can a
field that's turned off. A field doesn't need **Visible in List** to be
filtered. See [Use custom fields](/help/getting-started/use-custom-fields)
for every field setting.

## Filter through the API, MCP or Rela

The filter row is one way to narrow a list. The REST API, the MCP server and Rela, the built-in AI assistant, use a `filter` object instead. It filters by native fields and custom fields, combines conditions with AND, OR and NOT, and follows links between records. Read [the MCP guide](/developers/mcp) for the full grammar.
