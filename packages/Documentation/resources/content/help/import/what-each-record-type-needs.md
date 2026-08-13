---
title: What each record type needs
description: Columns, relationships, and example CSVs for companies, people, opportunities, tasks, and notes.
order: 2
updated: "2026-08-13"
related: [help/import/prepare-your-csv, help/import/update-existing-records]
---

Each record type imports separately — one file per type. This page lists what
each type accepts: its required column, the relationships you can link during
mapping, and a working example CSV.

## Companies

- `name` (required)
- `account_owner_email` — a team member's email, to assign ownership
- Custom fields with the `custom_fields_` prefix

```
name,account_owner_email,custom_fields_industry,custom_fields_domains
Acme Corporation,owner@yourcompany.com,Technology,acme.com
```

Companies match existing records by **domain** or **Record ID** — see
[Update existing records with an import](/help/import/update-existing-records).

## People

- `name` (required)
- Custom fields with the `custom_fields_` prefix
- **Company** relationship — link each person to a company by Record ID,
  Domain, or Name

```
name,company,custom_fields_emails,custom_fields_title
John Doe,acme.com,john@acme.com,CEO
Jane Smith,acme.com,jane@acme.com,CTO
```

Map the `company` column to the Company relationship and choose **Match by
Domain**, so `acme.com` links to the existing company. Choosing **Match by
Name** always creates a new company instead.

## Opportunities

- `name` (required)
- Custom fields (amount, stage, close date, and so on)
- **Company** relationship — by Record ID, Domain, or Name
- **Contact** relationship — by Record ID, Email, Phone, or Name

```
name,company,contact,custom_fields_amount,custom_fields_stage
Q1 Enterprise Deal,acme.com,john@acme.com,50000,Proposal
```

Map `company` to Company → Domain and `contact` to Contact → Email for the
most reliable linking.

## Tasks

- `title` (required)
- **Companies**, **People**, **Opportunities** relationships — link to one or
  more records each
- **Assignees** relationship — assign to team members by email

```
title,assignee,company,custom_fields_due_date,custom_fields_priority
Follow up with client,assignee@yourcompany.com,acme.com,2024-03-15,High
```

Map the `assignee` column to Assignees → Email to assign by email address.

## Notes

- `title` (required)
- **Companies**, **People**, **Opportunities** relationships

```
title,company
Meeting Notes,acme.com
```

Notes are always created as new records — an import never updates an existing
note, even if you include an `id` column.
