# Chat

## Verifying chat changes

Chat features MUST be verified against the production-shaped stack before being
reported done: Horizon running, `QUEUE_CONNECTION=redis` (not sync), Reverb up,
and the full loop walked in a real browser (send → stream → proposal card →
approve/reject). Sync-queue testing masks exactly the bug class that reaches
production: message ordering, approval races, duplicate proposals.

- When a production chat transcript is given as a bug report, enumerate every
  defective turn as a separate defect (ordering, duplicate/stale proposal cards,
  rate-limit UX, wrong success messages), reproduce each locally, and track them
  as a checklist — never fix only the most visible one.
- When one chat tool has a bug, sweep its sibling Create/Update/Delete tools for
  the same class of bug before closing.

## Tool design

- Prefer giving the agent a tool (e.g. `ListTeamMembersTool`) over injecting
  tenant data into the system prompt — add prompt-context injection only when a
  tool round-trip is demonstrably too costly.
- New write tools must support batch input like the delete path (`ids[]` /
  multi-record proposals → one `PendingAction`, all-or-nothing) — do not add new
  scalar-only tools.
- A field reachable in the Filament form must be settable from chat; the
  assistant answering "that field isn't supported" is a bug, not a limitation
  to document.

## Chat tools + custom fields

Chat tools (`packages/Chat/src/Tools/*/Create*Tool.php` and `Update*Tool.php`) automatically support **every** active custom field for their entity. Adding a new field to `app/Enums/CustomFields/*Field.php` (or via the Custom Fields admin UI) is enough — do NOT add per-field schema slots, value coercion, or display rows to the chat tool. The bridge services in `packages/Chat/src/Services/Tools/` handle:

- Inlining a per-tenant `custom_fields` schema description so the LLM knows the valid codes and option labels.
- Translating option labels back to option IDs at validation time.
- Formatting the proposal-card "old → new" diff per field type.

If you need a custom field to be **un-settable** from chat, mark it `active=false` on the `custom_fields` row, or add a tool-side allowlist filter inside `CustomFieldsSchemaDescriber`. Don't reach for hand-rolled per-field code.
