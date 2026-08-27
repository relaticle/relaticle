---
title: Prepare your CSV file
description: File requirements, column naming, and formatting rules that make an import succeed on the first try.
order: 1
updated: "2026-08-13"
related: [help/import/what-each-record-type-needs, help/import/fix-import-errors, help/getting-started/import-your-existing-data]
---

A CSV imports cleanly when it is UTF-8 encoded, has headers in the first row,
and formats each column consistently. This page covers the rules; the
[walkthrough](/help/getting-started/import-your-existing-data) covers the
clicks.

## File requirements

| Requirement | Value |
|-------------|-------|
| Format | CSV (comma-separated values) |
| Encoding | UTF-8 |
| Maximum rows | 10,000 per file |
| Maximum size | 10MB |
| Headers | Required in the first row |

In Excel, use **Save As → CSV UTF-8 (Comma delimited)** — Excel's default CSV
encoding breaks accented characters.

## Required columns

Only one column is required per record type:

| Record type | Required column |
|-------------|-----------------|
| Companies | Name |
| People | Name |
| Opportunities | Name |
| Tasks | Title |
| Notes | Title |

Every other column is optional — a blank cell simply leaves that field empty.

## Custom field columns

Name custom field columns with the prefix `custom_fields_` followed by the
field's code:

```
custom_fields_industry
custom_fields_emails
custom_fields_website
```

Find each field's code in **Settings → Custom Fields**, under the **Code**
column. The wizard also auto-maps close names — a column called "Industry"
usually lands on the Industry field without the prefix — but the prefix makes
the match exact.

## Format values by field type

| Field type | Format | Example |
|------|--------|---------|
| Text | Plain text | `Technology` |
| Number | Digits only, no symbols | `50000` |
| Date | YYYY-MM-DD, DD/MM/YYYY, or MM/DD/YYYY | `2024-03-15` |
| Email | Valid email(s), comma-separated | `john@acme.com,jane@acme.com` |
| Select | The exact option label | `Enterprise` |
| Multi-select | Comma-separated option labels | `CRM,Analytics` |
| Boolean | true/false, yes/no, or 1/0 | `true` |

## Do

- Include headers in the first row.
- Quote values that contain commas: `"Last, First"`.
- Keep one consistent format within each column — especially dates.
- Test with a small sample (5-10 rows) before importing the full file.

## Don't

- Use Excel's default encoding — pick "CSV UTF-8".
- Mix date formats in the same column (`2024-03-15` and `03/15/2024`).
- Include currency symbols in numbers — use `50000`, not `$50,000`.
- Leave empty rows at the end of the file.

## Get a ready-made template

Export a few existing records from any list — the exported file includes the
`id` column and every custom field your workspace uses, already named
correctly. Delete the rows, keep the headers, and fill in your data.
