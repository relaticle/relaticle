# Import Guide

## Introduction

Relaticle's import feature allows you to quickly populate your CRM with data from CSV files. You can import:

- **Companies** - Organizations and accounts
- **People** - Contacts and individuals
- **Opportunities** - Deals and sales opportunities
- **Tasks** - To-dos and action items
- **Notes** - Meeting notes and observations

### When to Use Import vs Migration Wizard

- **Single Import**: Use the standard import wizard when importing one entity type (e.g., just companies or just people)
- **Migration Wizard**: Use when importing multiple related entities at once (e.g., companies + people + opportunities together)

### Before You Start

- ✅ Prepare your CSV file with clean, validated data
- ✅ Ensure your file is UTF-8 encoded
- ✅ Include column headers in the first row
- ✅ Keep file size under 100MB
- ✅ Review the required fields for each entity type below

---

## Supported File Formats

### CSV Files

- **Format**: Comma-separated values (CSV)
- **Encoding**: UTF-8 (required)
- **Size Limit**: 100MB maximum
- **Headers**: First row must contain column names

### Excel Files

- **Format**: .xlsx files are automatically converted to CSV
- **Compatibility**: Excel 2007 and later
- **Note**: Formulas are evaluated and converted to values

---

## The 4-Step Import Process

### Step 1: Upload Your File

1. Navigate to the entity you want to import (Companies, People, etc.)
2. Click the **Import** button
3. Select your CSV file
4. Click **Upload**

**Troubleshooting Upload Issues**:
- **File too large**: Split into multiple files under 100MB each
- **Invalid format**: Ensure file is saved as CSV UTF-8
- **Upload fails**: Check internet connection and try again

### Step 2: Map Your Columns

The import wizard will automatically attempt to match your CSV columns to Relaticle fields based on column names.

**How Auto-Mapping Works**:
- Exact name matches (e.g., "Email" → Email field)
- Common variations (e.g., "Company Name", "Organization" → Company Name)
- Custom field codes (e.g., "custom_fields_industry" → Industry custom field)

**Required Fields** (marked with red asterisk):
- **Companies**: Name
- **People**: Name, Company Name
- **Opportunities**: Name
- **Tasks**: Title
- **Notes**: Title

**Manual Adjustment**:
- Click dropdown next to any column to change mapping
- Select "Don't Import" to skip a column
- Custom fields appear with "custom_fields_" prefix

### Step 3: Review & Fix Values

The wizard analyzes your data and highlights potential issues before importing.

**Understanding Validation Errors**:
- **Red errors**: Must be fixed before import
- **Row count**: Shows how many rows have this issue
- **Common issues**: Invalid email format, missing required fields, invalid dates

**Fix, Skip, or Continue Actions**:
1. **Fix in CSV**: Download, correct errors, and re-upload
2. **Correct Values**: Use the inline correction tool to map values (e.g., "CA" → "California")
3. **Skip Rows**: Rows with errors will be skipped during import
4. **Continue Anyway**: Only if errors are acceptable (not recommended)

**Common Validation Issues**:
- Invalid email addresses
- Dates in unrecognized formats (use YYYY-MM-DD)
- Required fields left blank
- Invalid UUIDs for ID-based updates

### Step 4: Preview Import

Review a summary of what will happen when you import.

**Summary Statistics**:
- **Total Rows**: Number of records in your file
- **New**: Records that will be created
- **Updates**: Existing records that will be modified
- **Update Method**: Shows whether updates use ID or name/email matching

**Update Method Indicators**:
- **Update by ID** (blue badge): Using Record ID for precise matching
- **Update** (orange badge): Using name/email for matching
- **New** (green badge): Creating a new record

**Final Checks Before Execution**:
- ✅ Verify new vs. update counts look correct
- ✅ Check sample rows in preview table
- ✅ Confirm company matching for People/Opportunities
- ✅ Review duplicate handling strategy

Click **Start Import** when ready.

---

## Handling Duplicates

### Detection Methods by Entity

**Companies**: Exact name match (case-sensitive)
```
"Acme Corp" = "Acme Corp" ✓
"Acme Corp" ≠ "acme corp" ✗
```

**People**: Email address match (from custom_fields_emails)
```
john@acme.com matches existing person ✓
Different email = new person created ✗
```

**Opportunities**: Exact name match
**Tasks**: Exact title match
**Notes**: Exact title match

### Duplicate Handling Strategies

Choose how to handle records that already exist:

#### 1. Skip (Safest - Default)

**What it does**: Creates new record only if no duplicate exists
**Use when**: You want to avoid overwriting existing data
**Example**:
```
CSV: "Acme Corp"
Existing: "Acme Corp" with Phone: 555-1234
Result: CSV row skipped, existing record unchanged
```

#### 2. Update (Use with Caution)

**What it does**: Updates existing record with CSV data
**Use when**: Refreshing data from a master system
**Example**:
```
CSV: "Acme Corp", Phone: 555-9999
Existing: "Acme Corp", Phone: 555-1234
Result: Phone updated to 555-9999
```

**⚠️ Warning**: This overwrites existing field values. Blank fields in CSV will erase data.

#### 3. Create New (Allows Duplicates)

**What it does**: Always creates new record, even if duplicate name exists
**Use when**: You need multiple records with same name
**Example**:
```
CSV: "Acme Corp"
Existing: "Acme Corp" (ID: 123)
Result: New "Acme Corp" created (ID: 456)
```

---

## ID-Based Record Updates

### What Is It?

Precise record matching using unique Record IDs, allowing you to update specific records even when duplicates exist.

**Key Benefits**:
- ✅ Update exact record, no ambiguity
- ✅ Works with duplicate names/emails
- ✅ Bypass duplicate handling strategy
- ✅ Perfect for bulk data refreshes

### When to Use

- **Bulk updating existing records**: Refreshing data from external system
- **Correcting specific records**: Fix data without affecting others
- **Working with duplicates**: Update one "John Smith" among many

### How It Works

#### Step 1: Export Current Data

Get the Record IDs you need to update:

1. Navigate to entity list (Companies, People, etc.)
2. Select records to update (or export all)
3. Click **Export to CSV**
4. CSV will include an `id` column with UUIDs

Example export:
```csv
id,name,custom_fields_industry
9d3a5f8e-8c7b-4d9e-a1f2-3b4c5d6e7f8g,Acme Corp,Technology
a1f2b3c4-5d6e-7f8g-9h0i-1j2k3l4m5n6o,TechStart Inc,Technology
```

#### Step 2: Modify Your Data

Keep the `id` column and update other fields:

```csv
id,name,custom_fields_industry
9d3a5f8e-8c7b-4d9e-a1f2-3b4c5d6e7f8g,Acme Corporation,Software
a1f2b3c4-5d6e-7f8g-9h0i-1j2k3l4m5n6o,TechStart Inc,Hardware
```

#### Step 3: Re-Import

1. Upload modified CSV
2. Map columns (including `id` column)
3. Choose any duplicate strategy (doesn't matter - ID takes precedence)
4. Review preview - you'll see "Update by ID" badges
5. Import runs - system matches by ID and updates those specific records

### Mixed Imports

You can create new records and update existing ones in the same import:

```csv
id,name
9d3a5f8e-8c7b-4d9e-a1f2-3b4c5d6e7f8g,Update This Company
,Create New Company (no ID = new record)
b2f3c4d5-6e7f-8g9h-0i1j-2k3l4m5n6o7p,Update Another Company
```

**Preview will show**:
- Row 1: "Update by ID" (blue badge)
- Row 2: "New" (green badge)
- Row 3: "Update by ID" (blue badge)

### Security & Validation

**ID Validation** (happens in Step 3: Review Values):
- ✅ Must be valid UUID format
- ✅ Record must exist in your workspace
- ✅ You must have permission to edit it

**Error Messages**:
- `"Invalid ID format"`: Not a valid UUID - check for typos
- `"Record not found"`: ID doesn't exist or belongs to another workspace
- `"Invalid UUID"`: Format is wrong (should be 36 characters: 8-4-4-4-12)

**Team Isolation**:
- IDs from other workspaces are automatically rejected
- You can only update records in your current workspace
- Import fails safely without cross-contamination

### Best Practices

✅ **DO**:
- Always export first before bulk updating
- Test with 5-10 rows before full import
- Keep ID column in your working spreadsheet
- Verify IDs are current (re-export if data is old)
- Back up data before large updates

❌ **DON'T**:
- Manually type UUIDs (always copy from export)
- Mix IDs from different workspaces
- Delete the ID column accidentally
- Share CSV files with IDs across teams

### Example Workflow: Quarterly Data Refresh

1. **Export**: Download all companies with IDs
2. **Enrich**: Add updated information from your accounting system
3. **Import**: Re-upload with IDs intact
4. **Result**: All companies updated precisely, no duplicates created

---

## Importing Each Entity Type

### Companies

**Required Fields**:
- Name

**Optional Fields**:
- Account Owner Email (assigns ownership to team member)
- Custom fields (industry, website, etc.)

**CSV Example**:
```csv
id,name,account_owner_email,custom_fields_industry,custom_fields_website
,Acme Corporation,owner@company.com,Technology,https://acme.com
,TechStart Inc,owner@company.com,Software,https://techstart.io
```

**Auto-Assignment**:
- If Account Owner Email not provided, defaults to user performing import
- If email doesn't match team member, field is left blank

### People

**Required Fields**:
- Name
- Company Name (auto-creates company if doesn't exist)

**Optional Fields**:
- Email (recommended for duplicate detection)
- Phone, Title, and other custom fields

**CSV Example**:
```csv
id,name,company_name,custom_fields_emails,custom_fields_title
,John Doe,Acme Corporation,john@acme.com,CEO
,Jane Smith,Acme Corporation,jane@acme.com,CTO
```

**Auto-Company Creation**:
- If Company Name doesn't exist, it's automatically created
- Company matched by exact name
- New company inherits import user as creator

**Duplicate Detection**:
- Based on email address (exact match)
- Uses `custom_fields_emails` field
- If email exists → update/skip based on strategy
- If email blank → always creates new

### Opportunities

**Required Fields**:
- Name

**Optional Fields**:
- Company Name (auto-creates if needed)
- Contact Name (auto-creates if needed)
- Amount, Stage, Close Date, custom fields

**CSV Example**:
```csv
id,name,company_name,contact_name,custom_fields_amount,custom_fields_stage
,Q1 Enterprise Deal,Acme Corporation,John Doe,50000,Proposal
,Renewal 2024,TechStart Inc,Jane Smith,25000,Negotiation
```

**Auto-Creation Behavior**:
- Company and Contact created if they don't exist
- Links opportunity to existing records if names match

### Tasks

**Required Fields**:
- Title

**Optional Fields**:
- Company Name, Person Name, Opportunity Name
- Assignee Email, Due Date, Priority

**CSV Example**:
```csv
id,title,company_name,assignee_email,custom_fields_due_date,custom_fields_priority
,Follow up with Acme,Acme Corporation,assignee@company.com,2024-03-15,High
,Send proposal,TechStart Inc,assignee@company.com,2024-03-20,Medium
```

### Notes

**Required Fields**:
- Title

**Optional Fields**:
- Company Name, Person Name, Opportunity Name
- Content, Created Date

**CSV Example**:
```csv
id,title,company_name,custom_fields_content,custom_fields_created_date
,Meeting Notes - Q1 Review,Acme Corporation,Discussed expansion plans,2024-02-01
,Call Summary,TechStart Inc,Technical requirements gathering,2024-02-05
```

---

## Migration Wizard

The Migration Wizard is designed for importing multiple entity types with complex relationships.

### When to Use

- Migrating from another CRM system
- Initial data load with companies + people + opportunities
- Bulk import with many interdependencies

### How It Works

**3-Step Process**:

1. **Upload Files**: Upload separate CSV files for each entity type
2. **Map All Columns**: Map fields for all entity types at once
3. **Import in Order**: System imports in dependency order:
   - Companies first
   - People second (links to companies)
   - Opportunities third (links to companies and people)
   - Tasks and Notes last

### Dependency Handling

The wizard automatically:
- Creates companies before people
- Links people to companies by name
- Links opportunities to companies and contacts
- Handles circular references safely

**Sequential Processing**:
- All imports for a workspace run one at a time
- Prevents race conditions when creating related records
- Ensures data integrity across entity types

### Example Migration

**companies.csv**:
```csv
name,custom_fields_industry
Acme Corp,Technology
TechStart Inc,Software
```

**people.csv**:
```csv
name,company_name,custom_fields_emails
John Doe,Acme Corp,john@acme.com
Jane Smith,TechStart Inc,jane@techstart.io
```

**opportunities.csv**:
```csv
name,company_name,contact_name,custom_fields_amount
Enterprise Deal,Acme Corp,John Doe,50000
Startup Package,TechStart Inc,Jane Smith,25000
```

---

## Custom Fields in Imports

### Naming Convention

Custom fields use the prefix `custom_fields_` followed by the field code:

**Format**: `custom_fields_[code]`

**Examples**:
- `custom_fields_industry` → Industry field
- `custom_fields_emails` → Emails field
- `custom_fields_website` → Website field
- `custom_fields_annual_revenue` → Annual Revenue field

### Finding Field Codes

1. Navigate to Settings → Custom Fields
2. Find your field
3. Look at the "Code" column
4. Use code with `custom_fields_` prefix in your CSV

### Field Type Handling

**Text Fields**:
```csv
custom_fields_industry
Technology
```

**Number Fields**:
```csv
custom_fields_annual_revenue
1000000
```

**Date Fields** (use YYYY-MM-DD format):
```csv
custom_fields_contract_end_date
2024-12-31
```

**Single Select** (use option label):
```csv
custom_fields_company_size
51-200 employees
```

**Multi-Select** (comma-separated):
```csv
custom_fields_products
CRM,Marketing Automation,Analytics
```

**Email** (comma-separated for multiple):
```csv
custom_fields_emails
john@acme.com,john.doe@acme.com
```

**Boolean** (true/false, yes/no, 1/0):
```csv
custom_fields_is_partner
true
```

### Validation

Custom fields maintain their validation rules during import:

- **Required fields**: Must have value (not blank)
- **Email format**: Validates email addresses
- **Number range**: Enforces min/max values
- **Select options**: Must match existing option labels
- **Date format**: Accepts various formats, converts to YYYY-MM-DD

**Error Messages**:
- Show actual validation rule that failed
- Display in Step 3: Review Values
- Can be corrected before import

---

## CSV Formatting Best Practices

### File Structure Requirements

✅ **DO**:
- Include header row with column names
- Use UTF-8 encoding
- Quote fields containing commas: `"Last, First"`
- Keep consistent column order
- Use YYYY-MM-DD for dates

❌ **DON'T**:
- Use Excel's default encoding (use "CSV UTF-8")
- Include empty columns
- Mix date formats in same column
- Use special characters in column names

### Data Formatting Rules

**Text Fields**:
- Remove leading/trailing spaces
- Use consistent capitalization
- Quote values with commas or newlines

**Numbers**:
- Remove currency symbols: `50000` not `$50,000`
- Use decimals for currency: `1234.56`
- Don't use thousands separators

**Dates**:
- Preferred: `2024-03-15` (YYYY-MM-DD)
- Accepted: `03/15/2024`, `March 15, 2024`
- Avoid: `3-15-24`, `15/3/2024`

**Emails**:
- Validate before import
- Use comma-separation for multiple: `john@acme.com,jane@acme.com`
- Trim whitespace

**Booleans**:
- Use: `true`/`false`, `yes`/`no`, `1`/`0`
- Case-insensitive

### Common Mistakes to Avoid

1. **Wrong encoding**: Save as "CSV UTF-8" in Excel
2. **Formula instead of values**: Copy-paste-values before saving
3. **Hidden columns**: Ensure all needed data is visible
4. **Merged cells**: Unmerge all cells before export
5. **Extra rows at bottom**: Delete empty rows
6. **Inconsistent data**: Use same format throughout column

---

## Troubleshooting

### Upload Issues

**Problem**: "File too large"
- **Solution**: Split into multiple files under 100MB each
- **Tip**: Import in batches of 10,000-20,000 rows

**Problem**: "Invalid file format"
- **Solution**: Re-save as CSV UTF-8
- **Mac Excel**: File → Save As → CSV UTF-8
- **Windows Excel**: Save As → CSV UTF-8 (Comma delimited)

**Problem**: "Upload failed"
- **Solution**: Check internet connection, try smaller file, refresh page

### Mapping Issues

**Problem**: Column not auto-mapped correctly
- **Solution**: Manually select correct field from dropdown
- **Tip**: Use standard column names for auto-mapping

**Problem**: Custom field not appearing
- **Solution**: Check field code in Settings → Custom Fields
- **Format**: Must be `custom_fields_[code]`

**Problem**: Required field marked red
- **Solution**: Map required field to CSV column
- **Required fields**: Name (companies), Name + Company (people), etc.

### Validation Issues

**Problem**: "Invalid email format"
- **Solution**: Fix emails in CSV, use valid format: user@domain.com
- **Tip**: Use Excel formula to validate before import

**Problem**: "Invalid date format"
- **Solution**: Convert dates to YYYY-MM-DD format
- **Excel formula**: `=TEXT(A1,"YYYY-MM-DD")`

**Problem**: "Invalid UUID"
- **Solution**: Check ID column, must be valid UUID
- **Tip**: Re-export to get fresh IDs

**Problem**: "Unknown option for select field"
- **Solution**: Use exact option label from field settings
- **Case-sensitive**: "Technology" ≠ "technology"

### Execution Issues

**Problem**: Import stuck at "Processing"
- **Solution**: Wait (large imports take time), refresh page after 10 minutes
- **Background processing**: You can navigate away, will notify when complete

**Problem**: "Some rows failed"
- **Solution**: Download failed rows CSV, fix errors, re-import
- **Check**: Failed rows report shows exact error per row

**Problem**: Duplicates created unexpectedly
- **Solution**: Check duplicate detection method for entity
- **Companies**: Exact name match
- **People**: Email match
- **Tip**: Use ID-based updates to avoid this

---

## FAQ

### Can I import more than one entity type at once?
Yes, use the Migration Wizard. It handles multiple entity types with proper dependency ordering.

### What happens if my import fails halfway?
Successfully imported rows remain in the system. Failed rows can be downloaded, corrected, and re-imported.

### Can I undo an import?
No automatic undo. Recommendation: Test with small sample first, backup data before large imports.

### How do I update existing records?
Two methods:
1. **ID-based** (recommended): Include `id` column with record UUIDs
2. **Name/email-based**: Set duplicate strategy to "Update"

### Can I import custom fields?
Yes! Use `custom_fields_[code]` format. Find codes in Settings → Custom Fields.

### What's the maximum file size?
100MB per file. For larger datasets, split into multiple imports.

### Do imports run in real-time?
Large imports process in background queue. You'll receive notification when complete.

### Can I schedule recurring imports?
Not currently supported. Imports are manual, on-demand only.

### What if I have duplicate company names?
Use ID-based updates to target specific records. Without IDs, first match wins.

### How are team members assigned?
- Account Owner Email: Matches by email, must be team member
- Creator: Defaults to user performing import
- Assignee Email: For tasks, must be team member

---

## Appendix: CSV Templates

### Company Import Template

```csv
id,name,account_owner_email,custom_fields_industry,custom_fields_website
,Acme Corporation,owner@yourcompany.com,Technology,https://acme.com
```

**Download**: Export any company to get template with all custom fields

### People Import Template

```csv
id,name,company_name,custom_fields_emails,custom_fields_phone,custom_fields_title
,John Doe,Acme Corporation,john@acme.com,555-1234,CEO
```

**Download**: Export any person to get template with all custom fields

### Opportunity Import Template

```csv
id,name,company_name,contact_name,custom_fields_amount,custom_fields_stage
,Q1 Enterprise Deal,Acme Corporation,John Doe,50000,Proposal
```

**Download**: Export any opportunity to get template with all custom fields

### Task Import Template

```csv
id,title,company_name,assignee_email,custom_fields_due_date,custom_fields_priority
,Follow up with client,Acme Corporation,assignee@yourcompany.com,2024-03-15,High
```

**Download**: Export any task to get template with all custom fields

### Note Import Template

```csv
id,title,company_name,custom_fields_content,custom_fields_created_date
,Meeting Notes - Q1 Review,Acme Corporation,Discussed expansion plans for Q2,2024-02-01
```

**Download**: Export any note to get template with all custom fields

---

## Need Help?

If you encounter issues not covered in this guide:

1. Check the **in-app tooltips** during import (hover over ⓘ icons)
2. Review your CSV formatting against examples above
3. Test with small sample (5-10 rows) to isolate issues
4. Export existing records to see correct format

**Pro Tip**: The export feature generates perfectly formatted CSV templates with all your custom fields. Use it as a starting point for imports.
