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
- Every write tool takes batch input: `records[]` on create and update,
  `ids[]` on delete. One call → one `PendingAction`; a multi-record proposal is
  a `_batch` the dock resolves per item. Do not add scalar-only tools.
- A request needing several writes is ONE turn: the assistant chains the write
  tools and links them with `$ref:<pending_action_id>` where a record it just
  proposed would go. Proposals sharing a `turn_id` are one plan, presented as a
  single card and approved once (`ProposalPlanService`). A new foreign key on a
  write tool must be listed in `ownedForeignKeys()`/`ownedForeignKeyLists()`, or
  it will accept neither reference validation nor ownership checks.
- Resolve a reference only at approval time (`PlanReferenceResolver`), never at
  proposal time, and never let a `$ref` fall out of a card's display: a plan card
  that hides the link being approved is the failure this design exists to
  prevent (`RecordNameResolver` renders it as "Name (step N)").
- Read tools take `lookup: true` to skip the `display_block`; the prompt tells
  the model to use it (or `SearchCrmTool`) when it only needs ids. Every read
  result without that flag renders, so a new read tool must either emit a block
  or be named in the prompt's no-block list.
- A new list tool needs `availableIncludes()` (copy its sibling `Get*Tool`'s
  allowlist) or the prompt's related-records rule has nothing to call for it.
- Replayed proposal tool results are NEVER rewritten: mutating an earlier
  message invalidates the Anthropic prompt-cache prefix from that turn on.
  Decided status travels only in `<resolved_actions>`, auto-cancelled status in
  `<superseded_proposals>`, both re-queried per turn. Never label a proposal by
  its card heading.
- A field reachable in the Filament form must be settable from chat; the
  assistant answering "that field isn't supported" is a bug, not a limitation
  to document.

## Chat tools + custom fields

Chat tools (`packages/Chat/src/Tools/*/Create*Tool.php` and `Update*Tool.php`) automatically support **every** active custom field for their entity. Adding a new field to `app/Enums/CustomFields/*Field.php` (or via the Custom Fields admin UI) is enough — do NOT add per-field schema slots, value coercion, or display rows to the chat tool. The bridge services in `packages/Chat/src/Services/Tools/` handle:

- Inlining a per-tenant `custom_fields` schema description so the LLM knows the valid codes and option labels.
- Translating option labels back to option IDs at validation time.
- Formatting the proposal-card "old → new" diff per field type.

If you need a custom field to be **un-settable** from chat, mark it `active=false` on the `custom_fields` row, or add a tool-side allowlist filter inside `CustomFieldsSchemaDescriber`. Don't reach for hand-rolled per-field code.
