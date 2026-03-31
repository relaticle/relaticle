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
| Max Size | 10MB |
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

**Linking Related Records**: When mapping a column to a relationship (like Company or Contact), a submenu appears where you choose the match method — by Record ID, Domain, Email, or Name.

### Step 3: Review Values

The wizard analyzes your data and flags validation issues.

**Actions You Can Take**:
- **Fix**: Enter a corrected value to apply to all rows with that value
- **Skip**: Click the skip icon to exclude a value. The row will still be imported, but this field will be empty
- **Change Date Format**: For date columns, select the correct format if auto-detection is uncertain

### Step 4: Preview & Import

Review what will happen:
- **New**: Records that will be created
- **Update**: Existing records that will be modified

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

## Matching Existing Records

When you map a field that uniquely identifies records (like email or domain), the wizard automatically finds matches in your existing data. How matching works depends on the match method you choose in Step 2.

### Match Methods

| Method | Behavior | Available For |
|--------|----------|---------------|
| **Record ID** | Updates the exact record. Skips if ID not found. | All entities |
| **Domain** | Finds existing company by domain, or creates new. | Companies |
| **Email** | Finds existing person by email, or creates new. | People |
| **Phone** | Finds existing person by phone, or creates new. | People |
| **Name** | Always creates a new record (names aren't unique). | Companies, People |

### What Happens in Preview

After the wizard resolves matches, Step 4 shows the result:
- Rows matched to existing records → **Update**
- Rows with no match (or using Name matching) → **Create new**
- Rows with an ID that doesn't exist → **Skip**

### No Unique Field Mapped?

If you don't map any matchable field, the wizard shows a warning: all rows will be created as new records. You can go back and map a field, or continue anyway.

### Update Behavior

When the wizard matches a CSV row to an existing record, updates follow these rules:

- **Blank fields are ignored**: If a CSV column is empty, the existing value is preserved. You don't need to fill in every column — only include the fields you want to change.
- **Multi-value fields merge**: For fields that accept multiple values (emails, phone numbers, tags, multi-select), new values are merged with existing ones rather than replacing them.
- **Duplicates within the CSV**: If the same record appears multiple times in your file (e.g., two rows with the same email), the second row updates the first instead of creating a duplicate.

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
- Rows with valid IDs → "Update"
- Rows without IDs → "New"

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

**Fields**:
- `name` (required) - Company name
- `account_owner_email` - Team member email for ownership
- Custom fields with `custom_fields_` prefix

**Matching**: By domain (`custom_fields_domains`) or Record ID

```
name,account_owner_email,custom_fields_industry,custom_fields_domains
Acme Corporation,owner@yourcompany.com,Technology,acme.com
```

### People

**Fields**:
- `name` (required) - Person's full name
- Custom fields with `custom_fields_` prefix

**Relationships** (mapped in Step 2):
- **Company** - Link to a company. Match by Record ID, Domain, or Name (creates new).

**Matching**: By email (`custom_fields_emails`), phone (`custom_fields_phone_number`), or Record ID

```
name,company,custom_fields_emails,custom_fields_title
John Doe,acme.com,john@acme.com,CEO
Jane Smith,acme.com,jane@acme.com,CTO
```

**Note**: In the example above, the `company` column is mapped to the Company relationship. In Step 2, choose "Match by Domain" so `acme.com` links to the existing company. If you choose "Match by Name", a new company will always be created.

### Opportunities

**Fields**:
- `name` (required) - Opportunity name
- Custom fields (amount, stage, close_date, etc.)

**Relationships** (mapped in Step 2):
- **Company** - Link to a company. Match by Record ID, Domain, or Name.
- **Contact** - Link to a person. Match by Record ID, Email, Phone, or Name.

**Matching**: Record ID only

```
name,company,contact,custom_fields_amount,custom_fields_stage
Q1 Enterprise Deal,acme.com,john@acme.com,50000,Proposal
```

**Note**: Map the `company` column to Company → Domain and the `contact` column to Contact → Email for the best matching results.

### Tasks

**Fields**:
- `title` (required) - Task title

**Relationships** (mapped in Step 2):
- **Companies** - Link to one or more companies
- **People** - Link to one or more people
- **Opportunities** - Link to one or more opportunities
- **Assignees** - Assign to team members by email

**Matching**: Record ID only

```
title,assignee,company,custom_fields_due_date,custom_fields_priority
Follow up with client,assignee@yourcompany.com,acme.com,2024-03-15,High
```

**Note**: The `assignee` column is mapped to the Assignees relationship in Step 2. Choose "Match by Email" to link to team members by their email address.

### Notes

**Fields**:
- `title` (required) - Note title

**Relationships** (mapped in Step 2):
- **Companies** - Link to one or more companies
- **People** - Link to one or more people
- **Opportunities** - Link to one or more opportunities

**Matching**: None. Notes are always created as new records.

```
title,company
Meeting Notes,acme.com
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
| Unexpected duplicates | Map a unique field (email, domain, or ID) in Step 2 |

---

## FAQ

**Can I import multiple entity types at once?**
No, each entity imports separately. When importing People, related companies can be auto-created if you choose "Match by Name" for the Company relationship.

**What happens if my import fails halfway?**
Successful rows remain. Failed rows can be downloaded, fixed, and re-imported.

**Can I undo an import?**
No automatic undo. Test with small samples first and backup important data.

**How do I update existing records?**
Include the `id` column from a previous export. For People, email or phone matching also works. For Companies, domain matching works.

**What date formats are supported?**
ISO (YYYY-MM-DD), European (DD/MM/YYYY), and American (MM/DD/YYYY). The wizard auto-detects the format.

**What if a date is ambiguous (like 01/02/2024)?**
You'll see a warning. Use the format dropdown to select European or American interpretation.

**What's the maximum file size?**
10MB or 10,000 rows per file. Split larger datasets into multiple imports.

**Do imports run in real-time?**
Small imports complete immediately. Large imports process in the background with progress updates.

**How does the wizard match existing records?**
It depends on which match method you choose in Step 2. Record ID updates exact records. Domain and email find existing records or create new ones. Name always creates new records.

**Can Notes be updated via import?**
No. Notes are always created as new records regardless of whether you include an ID column.

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
id,name,company,custom_fields_emails,custom_fields_title
,John Doe,acme.com,john@acme.com,CEO
```

**Tip**: Map the `company` column to Company → Domain to link to existing companies. Choose "Match by Name" only when you want to create new companies.

### Opportunity Template
```
id,name,company,contact,custom_fields_amount,custom_fields_stage
,Q1 Enterprise Deal,acme.com,john@acme.com,50000,Proposal
```

**Tip**: Map `company` to Company → Domain and `contact` to Contact → Email to link to existing records.

### Task Template
```
id,title,assignee,custom_fields_due_date,custom_fields_priority
,Follow up with client,assignee@yourcompany.com,2024-03-15,High
```

**Tip**: Map the `assignee` column to Assignees → Email to assign tasks to team members.

### Note Template
```
title,company
Meeting Notes,acme.com
```

**Tip**: Export any existing record to get a template with all your workspace's custom fields already included.
