---
paths:
  - 'app/Mcp/Tools/**'
  - 'app/Mcp/Schema/**'
  - 'app/Mcp/Resources/**'
  - 'packages/Chat/src/Tools/**'
  - 'packages/Chat/src/Services/Tools/**'
  - 'app/Http/Requests/Api/**'
---

# Agent surfaces

## Four surfaces publish the same entity, and one of them is always the stale one

MCP tools, MCP schema resources, chat tools and API form requests each describe the
same writable fields and the same relation includes. A field or include added to one
must land in all four in the same change. `tests/Feature/CRM/SurfaceParityTest.php`
fails until they agree. Wording may differ per surface. The key set may not.

This is not hypothetical. `account_owner_id` was settable from MCP, chat and the panel
for months while the REST API dropped it from `validated()` and still returned it on
read. Nothing greps it: the surfaces spell the same fact in different vocabularies, so
searching one never finds the other.

## The enum owns the per-type words, the renderers only render

`CustomFieldType::inputFormat()`, `example()` and `isChoice()` are the only place a
field's write format is spelled. `App\Mcp\Schema\CustomFieldSchema` renders them as
JSON, `CustomFieldsSchemaDescriber` as prompt prose. Never re-derive either from the
package's `FieldDataType`: that is a storage classification, not agent vocabulary.

`CustomFieldSchema` uses `CustomFieldType::from()` because it reads active fields only,
and a test asserts every enabled package type has a case. The chat describer uses
`tryFrom()` because it also lists inactive fields, where a type retired through config
can still hold stored values.

## Never publish a capability no code path serves

A schema resource listing a relationship, a tool description naming a filter, a prompt
promising a field: each needs the read or write path behind it in the same change. The
MCP schema advertised `tasks` and `notes` as company relationships while the chat tools
offered no include for them, so the model read a capability it could not call.
