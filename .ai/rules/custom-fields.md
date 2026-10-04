---
paths:
  - 'config/custom-fields.php'
  - 'app/Models/CustomField*.php'
  - 'app/Rules/ValidCustomFields.php'
  - 'packages/Chat/src/Services/**'
  - 'packages/ImportWizard/src/Data/EntityLink.php'
---

# Custom Fields 4.0

## An unlisted feature flag takes the package default, so list every flag explicitly
Since custom-fields 4.0, `FeatureConfigurator::isEnabled()` falls back to the package's own
default for a flag the published config does not name (3.x treated an unlisted flag as off).
Four 3.x opt-in flags flipped on at the major (`FIELD_VALIDATION_RULES`,
`FIELD_DESCRIPTION_POSITION`, `SECTION_CONDITIONAL_VISIBILITY`, `UI_SECTION_WIDTH_CONTROL`),
so leaving one unlisted silently changes the UI on upgrade. `config/custom-fields.php` names
all of them; a new package flag must be added to `enable(...)` or `disable(...)` on purpose.

## Detect a link field by its definition, never by its type key
`custom_fields.lookup_type` is gone. A field that links records has
`$field->relationshipDefinition() !== null` (target via `targetEntityType()`, multiplicity
via `allowsMultipleRecords()`). Two field types share that shape, `record` (a simple one-way
link) and `relationship` (paired, with cardinality), so a check on `type === 'record'` misses
half of them. Chat, REST, MCP, import, and validation all branch on the definition.
