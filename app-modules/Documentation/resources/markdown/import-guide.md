# Import Guide

Relaticle's import wizard lets you bulk import data from CSV files into your CRM.

## Supported Entities

- **Companies** - Organizations and accounts
- **People** - Contacts linked to companies
- **Opportunities** - Deals and sales pipeline
- **Tasks** - Action items and to-dos
- **Notes** - Meeting notes and observations

---

## Quick Start

### File Requirements

| Requirement | Value |
|-------------|-------|
| Format | CSV (comma-separated values) |
| Encoding | UTF-8 |
| Max Rows | 10,000 per file |
| Max Size | 50MB |
| Headers | Required in first row |

**Tip**: In Excel, use "Save As" → "CSV UTF-8 (Comma delimited)".

### Required Fields

| Entity | Required |
|--------|----------|
| Companies | Name |
| People | Name |
| Opportunities | Name |
| Tasks | Title |
| Notes | Title |

---

## The 4-Step Process

### Step 1: Upload

1. Navigate to the entity list (Companies, People, etc.)
2. Click **Import**
3. Select your CSV file
4. Review file info (columns found, row count)

### Step 2: Map Columns

The wizard automatically matches CSV columns to Relaticle fields by comparing column names.

**Auto-Mapping Examples**:
- "company_name", "Company", "Organization" → Company Name
- "email", "Email Address", "contact_email" → Email
- "custom_fields_industry" → Industry custom field

**Manual Adjustment**: Click any dropdown to change the mapping or select "Don't Import" to skip a column.

### Step 3: Review Values

The wizard analyzes your data and shows validation issues.

**Issue Types**:
- **Errors** (red): Must be fixed or skipped before import
- **Warnings** (yellow): Optional to fix, won't block import

**Actions You Can Take**:
- **Fix**: Enter a corrected value in the text field
- **Skip**: Leave correction blank to skip these rows
- **Change Date Format**: For date columns, select the correct format if auto-detection is uncertain

### Step 4: Preview & Import

Review what will happen:
- **New**: Records that will be created
- **Update**: Existing records that will be modified
- **New Companies**: Companies auto-created (People imports only)

Click **Start Import** when ready. Large imports process in the background.

---

## Date Format Detection

The import wizard automatically detects date formats in your CSV and handles three common formats:

### Supported Formats

| Format | Pattern | Example |
|--------|---------|---------|
| **ISO** | YYYY-MM-DD | 2024-05-15 |
| **European** | DD/MM/YYYY or DD-MM-YYYY | 15/05/2024, 15 May 2024 |
| **American** | MM/DD/YYYY or MM-DD-YYYY | 05/15/2024, May 15th 2024 |

### How Detection Works

The wizard analyzes your date values to determine the format:

1. **Unambiguous dates**: When the day is > 12, the format is clear
   - `31/01/2024` → Must be European (DD/MM)
   - `01/31/2024` → Must be American (MM/DD)

2. **Ambiguous dates**: When both positions are ≤ 12
   - `01/02/2024` → Could be Jan 2 or Feb 1
   - You'll see a warning and can select the correct format

3. **ISO dates**: Always unambiguous
   - `2024-01-15` → Clearly January 15

### Selecting Date Format

In Step 3 (Review Values), date columns show a format dropdown:

- **High confidence**: Format auto-selected, dropdown shows detected format
- **Low confidence**: Warning icon appears, select the correct format manually

**Example**: If your CSV has `01/02/2024` and you know it means February 1st (European), select "European" from the dropdown.

---

## Duplicate Handling

### How Duplicates Are Detected

| Entity | Detection Method |
|--------|------------------|
| Companies | Domain (from custom_fields_domains) |
| People | Email (from custom_fields_emails) |
| Opportunities | Record ID only |
| Tasks | Record ID only |
| Notes | Record ID only |

**Note**: Opportunities, Tasks, and Notes only match by Record ID. To update existing records, include the `id` column from a previous export.

### Duplicate Strategies

Choose how to handle matched records:

| Strategy | Behavior |
|----------|----------|
| **Skip** (default) | Keep existing record, ignore CSV row |
| **Update** | Overwrite existing record with CSV data |
| **Create New** | Always create new record (allows duplicates) |

---

## ID-Based Updates

For precise updates, include Record IDs from a previous export.

### Workflow

1. **Export** your records (includes `id` column with ULIDs)
2. **Modify** the CSV, keeping the `id` column intact
3. **Re-import** - rows with valid IDs update those exact records

### Example

```
id,name,custom_fields_industry
01KCCFMZ52QWZSQZWVG0AP704V,Acme Corporation,Software
01KCCFN1A8XVQR4ZFWB3KC5M7P,TechStart Inc,Hardware
```

**Preview shows**:
- Rows with valid IDs: "Update by ID" (blue badge)
- Rows without IDs: "New" (green badge)

### Mixed Imports

You can create and update in the same file:

```
id,name
01KCCFMZ52QWZSQZWVG0AP704V,Update This Company
,New Company (blank ID = create new)
```

---

## Entity-Specific Details

### Companies

**Columns**:
- `name` (required) - Company name
- `account_owner_email` - Team member email for ownership
- Custom fields with `custom_fields_` prefix

**Duplicate Detection**: By domain (custom_fields_domains field)

```
name,account_owner_email,custom_fields_industry
Acme Corporation,owner@yourcompany.com,Technology
```

### People

**Columns**:
- `name` (required) - Person's full name
- `company_id` - Link to company by Record ID
- `company_name` - Link or create company by name
- `custom_fields_emails` - Email addresses (for duplicate detection)
- Other custom fields

**Duplicate Detection**: By email address

**Company Linking Priority**:
1. `company_id` - Direct ID match (highest priority)
2. Domain match - From email domain to company's domains field
3. `company_name` - Match by name, or create new company

```
name,company_name,custom_fields_emails,custom_fields_title
John Doe,Acme Corporation,john@acme.com,CEO
Jane Smith,Acme Corporation,jane@acme.com,CTO
```

**Note**: If `company_name` doesn't match an existing company, a new company is automatically created.

### Opportunities

**Columns**:
- `name` (required) - Opportunity name
- `company_id` or `company_name` - Link to company
- `contact_name` - Link or create contact
- Custom fields (amount, stage, close_date, etc.)

**Duplicate Detection**: Record ID only

```
name,company_name,contact_name,custom_fields_amount,custom_fields_stage
Q1 Enterprise Deal,Acme Corporation,John Doe,50000,Proposal
```

### Tasks

**Columns**:
- `title` (required) - Task title
- `assignee_email` - Team member to assign
- Custom fields (due_date, priority, status, etc.)

**Duplicate Detection**: Record ID only

```
title,assignee_email,custom_fields_due_date,custom_fields_priority
Follow up with client,assignee@yourcompany.com,2024-03-15,High
```

### Notes

**Columns**:
- `title` (required) - Note title
- Custom fields (content, etc.)

**Duplicate Detection**: Record ID only

```
title,custom_fields_content
Meeting Notes - Q1 Review,Discussed expansion plans for Q2
```

---

## Custom Fields

### Column Naming

Use the prefix `custom_fields_` followed by the field code:

```
custom_fields_industry
custom_fields_emails
custom_fields_website
```

Find field codes in **Settings → Custom Fields** under the "Code" column.

### Field Type Formatting

| Type | Format | Example |
|------|--------|---------|
| Text | Plain text | `Technology` |
| Number | Numeric, no symbols | `50000` |
| Date | YYYY-MM-DD, DD/MM/YYYY, or MM/DD/YYYY | `2024-03-15` |
| Email | Valid email(s), comma-separated | `john@acme.com,jane@acme.com` |
| Select | Exact option label | `Enterprise` |
| Multi-Select | Comma-separated labels | `CRM,Analytics` |
| Boolean | true/false, yes/no, 1/0 | `true` |

---

## CSV Best Practices

### Do

- Include headers in the first row
- Save as UTF-8 encoding
- Quote values containing commas: `"Last, First"`
- Use consistent formatting within each column
- Test with a small sample first (5-10 rows)

### Don't

- Use Excel's default encoding (use "CSV UTF-8")
- Mix date formats in the same column
- Include currency symbols in numbers: use `50000` not `$50,000`
- Leave empty rows at the end of the file

### Date Formatting Tips

The wizard accepts multiple date formats, but consistency is key:

**Good** (pick one format per column):
- `2024-03-15` (ISO - recommended)
- `15/03/2024` (European)
- `03/15/2024` (American)

**Avoid**:
- Mixing formats: `2024-03-15` and `03/15/2024` in same column
- Two-digit years without context: `03/15/24`

---

## Troubleshooting

### Upload Issues

| Problem | Solution |
|---------|----------|
| File too large | Split into files under 10,000 rows each |
| Invalid format | Re-save as "CSV UTF-8" from Excel |
| Upload fails | Check internet, try smaller file |

### Mapping Issues

| Problem | Solution |
|---------|----------|
| Column not auto-mapped | Manually select from dropdown |
| Custom field missing | Check field code in Settings → Custom Fields |
| Required field red | Map a CSV column to the required field |

### Validation Issues

| Problem | Solution |
|---------|----------|
| Invalid email | Fix email format: `user@domain.com` |
| Invalid date | Use a supported format (ISO, European, or American) |
| Invalid ID | Re-export to get fresh Record IDs |
| Unknown select option | Use exact option label from field settings |

### Import Issues

| Problem | Solution |
|---------|----------|
| Stuck at "Processing" | Large imports take time; check back in a few minutes |
| Some rows failed | Download failed rows, fix errors, re-import |
| Unexpected duplicates | Use ID-based updates for precise matching |

---

## FAQ

**Can I import multiple entity types at once?**
No, each entity imports separately. When importing People, related companies are auto-created.

**What happens if my import fails halfway?**
Successful rows remain. Failed rows can be downloaded, fixed, and re-imported.

**Can I undo an import?**
No automatic undo. Test with small samples first and backup important data.

**How do I update existing records?**
Include the `id` column from a previous export. For People, email matching also works.

**What date formats are supported?**
ISO (YYYY-MM-DD), European (DD/MM/YYYY), and American (MM/DD/YYYY). The wizard auto-detects the format.

**What if a date is ambiguous (like 01/02/2024)?**
You'll see a warning. Use the format dropdown to select European or American interpretation.

**What's the maximum file size?**
50MB or 10,000 rows per file. Split larger datasets into multiple imports.

**Do imports run in real-time?**
Small imports complete immediately. Large imports process in the background with progress updates.

---

## CSV Templates

Export existing records to get perfectly formatted templates with all your custom fields.

### Company Template
```
id,name,account_owner_email,custom_fields_industry,custom_fields_domains
,Acme Corporation,owner@yourcompany.com,Technology,acme.com
```

### People Template
```
id,name,company_name,custom_fields_emails,custom_fields_title
,John Doe,Acme Corporation,john@acme.com,CEO
```

### Opportunity Template
```
id,name,company_name,contact_name,custom_fields_amount,custom_fields_stage
,Q1 Enterprise Deal,Acme Corporation,John Doe,50000,Proposal
```

### Task Template
```
id,title,assignee_email,custom_fields_due_date,custom_fields_priority
,Follow up with client,assignee@yourcompany.com,2024-03-15,High
```

### Note Template
```
id,title,custom_fields_content
,Meeting Notes,Discussed expansion plans
```

**Tip**: Export any existing record to get a template with all your workspace's custom fields already included.
