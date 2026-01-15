# ImportWizard - Business Logic & Features

> A non-technical guide to what the ImportWizard does and how users interact with it.

---

## Overview

The ImportWizard is a 4-step process for importing CSV data into the CRM. Users can import Companies, People, Opportunities, Tasks, and Notes.

```
┌─────────────┐    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│   UPLOAD    │───▶│    MAP      │───▶│   REVIEW    │───▶│   PREVIEW   │
│   File      │    │   Columns   │    │   Values    │    │   & Import  │
└─────────────┘    └─────────────┘    └─────────────┘    └─────────────┘
```

---

## Step 1: Upload

### What the User Does
1. Drag-and-drop or click to upload a CSV file
2. See file details and validation results
3. Proceed to mapping or fix file issues

### Constraints
| Rule | Limit |
|------|-------|
| File formats | `.csv` or `.txt` only |
| Maximum size | 50 MB |
| Maximum rows | 10,000 rows |
| Column names | Must be unique (no duplicates) |

### What the User Sees
- File name, size, and type
- Number of columns detected
- Number of rows detected
- Any errors (invalid format, too large, etc.)

### Error Messages
- "File too large" → Split into smaller files
- "Too many rows" → Maximum 10,000 rows per import
- "Duplicate column names" → Rename columns to be unique
- "Invalid CSV format" → Check file encoding and structure

---

## Step 2: Map Columns

### What the User Does
1. Review each CSV column
2. Select which CRM field it maps to
3. For relationships, choose how to match records
4. Ensure all required fields are mapped

### Column Types

**Standard Fields**
- Name, Email, Phone, etc.
- Direct 1-to-1 mapping

**Relationship Fields**
- Link to another entity (Company → People, etc.)
- Requires choosing a "matcher" (how to find the related record)

**Custom Fields**
- User-defined fields on each entity
- Includes choice fields, dates, text, etc.

### Auto-Mapping

The system automatically suggests mappings based on:

1. **Column Name Matching**
   - "Company Name" → Company Name field
   - "Email Address" → Email field
   - Recognizes common variations and aliases

2. **Data Pattern Recognition**
   - Detects emails, phone numbers, dates
   - Suggests appropriate fields for unmapped columns

### Relationship Matchers

When mapping a relationship field, users choose how to find the related record:

**For Company Relationships:**

| Matcher | Behavior | Creates New? |
|---------|----------|--------------|
| Record ID | Find by exact ULID | No |
| Domain | Find by company domain | No |
| Name | Create new company | Yes |

**For Contact Relationships:**

| Matcher | Behavior | Creates New? |
|---------|----------|--------------|
| Record ID | Find by exact ULID | No |
| Email | Find by email address | No |
| Phone | Find by phone number | No |
| Name | Create new person | Yes |

### Warnings

- **No unique identifier mapped** → Cannot detect duplicates reliably
- **Matcher creates new records** → Will create companies/people automatically

### Required to Proceed
- All required fields must be mapped
- Can proceed with warnings (user choice)

---

## Step 3: Review Values

### What the User Does
1. Select a column from the sidebar
2. Review unique values and validation issues
3. Fix issues by correcting, skipping, or creating options
4. Repeat for columns with errors

### Value Display

For each unique value, the user sees:
- The value itself
- How many rows contain this value
- Any validation issue (error or warning)
- Current status (original, corrected, or skipped)

### Issue Types

**Errors** (must fix to import)
- Invalid date format
- Invalid email format
- Choice value not in options list
- Required field is blank

**Warnings** (can import, but review recommended)
- Ambiguous date (could be DD/MM or MM/DD)
- Value might not parse correctly

### User Actions

**Correct a Value**
- Type a new value
- For choice fields: select from dropdown
- One correction applies to ALL rows with that value

**Skip a Value**
- Rows with this value won't be imported
- Useful for invalid data that can't be fixed

**Create Missing Options**
- For choice fields only
- Adds new options to the field permanently
- Then the value becomes valid

### Date Format Selection

When dates are ambiguous:
1. System shows detected format with confidence percentage
2. User can select the correct format if confidence < 80%
3. Three formats supported:
   - **ISO**: `2024-05-15` (Year-Month-Day)
   - **European**: `15/05/2024` (Day/Month/Year)
   - **American**: `05/15/2024` (Month/Day/Year)

### Filter Options
- **All values**: See everything
- **Errors only**: Focus on problems

---

## Step 4: Preview & Import

### What the User Does
1. Review how records will be created/updated
2. Check the summary counts
3. Confirm and start the import

### Summary Display

```
┌────────────────────────────────────┐
│  Will Create: 45 new records       │
│  Will Update: 12 existing records  │
│  Will Skip: 3 rows (errors)        │
└────────────────────────────────────┘
```

### Row Preview

Each row shows:
- Original CSV values
- Mapped field values
- Status: Create / Update / Skip
- Linked relationships (if any)

### Duplicate Detection

**How existing records are found:**

| Entity | Matching Priority |
|--------|-------------------|
| Company | 1. Record ID → 2. Domain → 3. Create new |
| People | 1. Record ID → 2. Email → 3. Phone → 4. Create new |
| Opportunity | Record ID only |
| Task | Record ID only |
| Note | Record ID only |

### User Confirmations

Before importing:
- "X records will be created, Y updated, Z skipped"
- If errors exist: "Some rows will be skipped. Continue anyway?"

---

## Entity-Specific Features

### Companies

**Available Fields:**
- Name (required)
- Account Owner Email
- All custom fields

**Relationships:**
- None (companies are top-level)

**Unique Identifiers:**
- Record ID
- Domain (via custom fields)

---

### People

**Available Fields:**
- Name (required)
- All custom fields (email, phone, etc.)

**Relationships:**
- Company (with ID/Domain/Name matching)

**Unique Identifiers:**
- Record ID → Email → Phone (in priority order)

**Smart Company Matching:**
- If email domain matches a company's domain, auto-links
- Filters public domains (gmail.com, yahoo.com, etc.)
- Can be overridden with explicit domain mapping

---

### Opportunities

**Available Fields:**
- Name (required)
- Amount, Stage, Close Date
- All custom fields

**Relationships:**
- Company
- Contact (primary person)

**Unique Identifier:**
- Record ID only

---

### Tasks

**Available Fields:**
- Title (required)
- Priority, Status
- All custom fields

**Relationships:**
- Linked entity (Company, Person, or Opportunity)

**Unique Identifier:**
- Record ID only

---

### Notes

**Available Fields:**
- Content (required)
- Note Date
- Limited custom fields

**Relationships:**
- Linked entity (polymorphic)

**Unique Identifier:**
- Record ID only

---

## Custom Fields

### Supported Field Types

| Type | Validation | Correction UI |
|------|------------|---------------|
| Single Choice | Must match option | Dropdown |
| Multi Choice | Must match options | Multi-select |
| Date | Valid date format | Text + format picker |
| Date/Time | Valid timestamp | Text + format picker |
| Email | Valid email format | Text input |
| Phone | Valid phone format | Text input |
| Text | Any string | Text input |
| Number | Numeric value | Text input |
| URL | Valid URL | Text input |

### Choice Fields

**When values don't match existing options:**
1. Show error: "Invalid option"
2. User can:
   - Correct to an existing option
   - Skip the value
   - Create missing options (adds to field permanently)

---

## Date & Time Handling

### Supported Date Formats

**Date Only:**
| Format | Example | Notes |
|--------|---------|-------|
| ISO | `2024-05-15` | Never ambiguous |
| European | `15/05/2024`, `15 May 2024` | Day first |
| American | `05/15/2024`, `May 15, 2024` | Month first |

**DateTime (Timestamps):**
| Format | Example | Notes |
|--------|---------|-------|
| ISO | `2024-05-15 16:00:00` | Standard format, time at end |
| European | `16:00 15-05-2024` | Time FIRST, then day/month/year |
| American | `16:00 05-15-2024` | Time FIRST, then month/day/year |

### Ambiguous Dates

Dates like `05/06/2024` could be:
- May 6, 2024 (American)
- June 5, 2024 (European)

The system:
1. Analyzes all values in the column
2. Calculates confidence score based on unambiguous patterns
3. If confidence < 80%, asks user to confirm format

### Two-Digit Years
- `24` → interpreted as `2024`
- `99` → interpreted as `1999` (for historical data)

---

## Relationship Behavior

### Linking to Existing Records

**By ID:** Exact match, never creates new
**By Email/Phone:** Searches for match, never creates new
**By Domain:** Matches company domain, never creates new

### Creating New Records

**By Name:** Always creates a new record
- Warning shown during mapping step
- Cannot be undone

### Domain-Based Company Matching

When importing People with company relationship:

1. If person has email `alice@acme.com`
2. System looks for company with domain `acme.com`
3. If found, auto-links person to that company
4. If not found, creates new company (if Name matcher used)

**Public domains are filtered:**
- gmail.com, yahoo.com, outlook.com, etc.
- These won't auto-link to a "Gmail Inc" company
- Explicit domain mapping bypasses this filter

---

## Validation Rules

### Required Fields
- Must be mapped in Step 2
- Must have values in Step 3
- Blank = error (unless skipped)

### Field-Specific Validation

| Field Type | Rules |
|------------|-------|
| Email | Must be valid email format |
| Phone | Must be valid phone format |
| URL | Must be valid URL |
| Date | Must match selected format |
| Choice | Must be existing option (or create new) |
| ULID | Must be valid ULID format |

### Row-Level Validation
- All mapped fields validated per row
- Any error → row skipped unless corrected

---

## Import Execution

### What Happens

1. **Preparation**
   - Apply all corrections to CSV data
   - Save final CSV for processing

2. **Processing**
   - Split into chunks (50-1000 rows each)
   - Process in background
   - Create/update records

3. **Completion**
   - Record counts updated
   - Failed rows tracked
   - Temp files cleaned up

### Background Processing

Large imports run in background:
- User can navigate away
- Progress tracked
- Notification on completion

### Failed Rows

Rows that fail during import:
- Logged with error reason
- Can be exported for retry
- Don't affect successful rows

### Duplicate Strategy

During import, if a matching record is found:
- **Default behavior:** Update the existing record
- The duplicate strategy is applied via the importer's `resolveRecord()` method

---

## User Experience Summary

### Happy Path
1. Upload CSV → auto-validates
2. Map columns → auto-maps most fields
3. Review values → few or no errors
4. Preview → confirm and import

### Error Recovery
1. Upload errors → fix file and re-upload
2. Mapping errors → manually select correct field
3. Value errors → correct, skip, or create options
4. Import errors → review failed rows, retry

### Warnings vs Errors
- **Errors** block progress, must be fixed
- **Warnings** inform, user decides whether to fix

---

## Limits & Constraints

| Limit | Value | Why |
|-------|-------|-----|
| Max file size | 50 MB | Memory constraints |
| Max rows | 10,000 | Processing time |
| Session duration | 24 hours | Cache cleanup |
| Chunk size | 50-1000 rows | Background job efficiency |
| Date format confidence | 80% | Below this, user must confirm |

---

## Glossary

**Correction:** Replacing an invalid value with a valid one. Applied to all rows with that value.

**Skip:** Marking a value so rows containing it won't be imported.

**Matcher:** The method used to find related records (ID, Email, Domain, Name).

**Unique Identifier:** Field(s) used to detect if a record already exists.

**Choice Field:** Custom field with predefined options (dropdown).

**Public Domain:** Common email domains (gmail, yahoo) that shouldn't auto-link to companies.

**Ambiguous Date:** Date that could be interpreted as DD/MM or MM/DD.

**ULID:** Universally Unique Lexicographically Sortable Identifier (record IDs).
