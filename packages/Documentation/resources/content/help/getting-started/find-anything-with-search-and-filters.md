---
title: Find anything with search and filters
description: Find instructions for searching Relaticle records and filtering lists of companies, people, opportunities, tasks and notes.
order: 7
updated: "2026-11-13"
related: [help/getting-started/use-custom-fields, help/getting-started/import-your-existing-data]
---

Press **⌘K**, or click the search box in the sidebar, to search across every
record at once. Use the filter row on any list to narrow down what's already
in front of you.

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

A field that holds members shows you first, as your name followed by
**(me)**.

## Show deleted records

**Deleted records** sits at the bottom of the field list. Its chip shows
deleted records with the rest (**included**) or on their own (**only**).
Click the second part of the chip to switch. You see it only if you're
allowed to see deleted records.

## Sort and group a list

Click **Sort** and search for the column to order the list by. Click
**Add sort** to break ties with another column, and drag a row by its
handle to change which sort comes first. Each row switches between
ascending and descending, and its X removes it. The button reads
**Sorted by** and the first column, plus a count when more follow.

On Tasks, **Group** groups the list by status or priority.

A saved view is yours alone, and it saves itself. Change a filter, sort,
grouping, column or column name while a view is open, and the view keeps the
change. To start another view from what's on screen, open the view switcher
and pick **Create new view**.

The switcher lists your views in one order. The built-in table is one of the rows, such as **All companies**. Opportunities add **Pipeline**, Tasks add **Board**, and Notes add **Cards**. Search at the top narrows the list by name.

Drag a row to move it. A short press and hold does the same on a touch screen. Each row's menu also has **Move up** and **Move down**, which work from the keyboard. Search turns dragging and moving off while you type.

The view at the top is the one a page opens on when you visit it with no link attached, such as from the sidebar. A board or the cards view at the top opens that page. A link that names something, such as a filter, a record, or **All companies**, opens what it points to.

## If you can't find a field in the filter list

Every custom attribute can be filtered except Textarea, Rich Editor, and Color
Picker attributes. Encrypted attributes can't be filtered either, and neither
can an attribute that's turned off. An attribute doesn't need **Visible in
List** to be filtered. See
[Use custom attributes](/help/getting-started/use-custom-fields) for every
attribute setting.

## Filter through the API, MCP or Rela

The filter row is one way to narrow a list. The REST API, the MCP server and Rela, the built-in AI assistant, use a `filter` object instead. It filters by native fields and custom attributes, combines conditions with AND, OR and NOT, and follows links between records. Read [the MCP guide](/developers/mcp) for the full grammar.
