# Custom Fields 4.0, Phase 6: AI Autofill

Status: draft, pending brainstorm. Program: Custom Fields 4.0 (phase 6 of 6).
Plans: none yet; plans follow the approved spec. Depends on phase 3 (value substrate) and
phase 2 (option categories) at minimum; ordering against the 4.0.0 tag is an open question.
Tracking: relaticle/custom-fields#210 (add a checkbox once the brainstorm settles scope).

## What this phase is

Let a workspace mark a custom field as AI-filled and have the value computed on demand from
the record's other data. This file records the market reference the feature is measured
against, the standing ownership constraint, and the questions the brainstorm has to answer.
It makes no design decisions. The research behind the reference lives outside the repo.

## Reference: AI-filled attributes as the market ships them (read 2026-09-06)

- Offered on text, number, currency, select, and multi-select attributes, via an "AI autofill"
  toggle at attribute creation.
- Four modes:
  1. Summarize record: a written summary built from the record's existing attribute values,
     with optional guidance on what to focus on.
  2. Web agent: researches external sources for a text, number, or currency answer, with an
     optional confidence indicator (green, yellow, red). Meant for facts outside the workspace.
  3. Prompt completion: a custom prompt with variables that pull in named attribute values.
  4. Classify record: fills a select or multi-select. Restricted to existing options unless
     "allow AI to generate new options" is on.
- Values never compute automatically. A user runs autofill per cell, per selection (right
  click, "Recalculate with AI"), or from the column header.
- Credits: the web agent costs 10 per record, every other mode 1 per record. Limits come from
  the plan tier. Available to all workspace members.
- The model sees only the attribute values the prompt references, never notes or emails.

## Standing constraint

Phase 5, section 4.2, fixes the ownership split for the whole program: the package owns the
substrate, Relaticle core owns all AI intelligence, and nothing intelligent is ever packaged.
Phase 6 inherits that. Whether the package needs any substrate at all (a per-field autofill
configuration, a value-source stamp, a recalculate seam on the table and form surfaces) is
the first question below, not a decision.

## Existing pieces the brainstorm should start from

- Credits: `packages/Chat/src/Services/CreditService.php` with `AiCreditBalance` and
  `AiCreditTransaction`, already metered per workspace and reset per period.
- LLM layer: `Laravel\Ai` through the chat agents; `NextStepSuggester` and
  `ConversationTitler` already use structured output, which is how a select value or a
  number would come back typed.
- Chat bridge: `CustomFieldsSchemaDescriber` already turns a tenant's field set into a
  schema the model can read, and translates option labels back to ids.
- Trust boundary: chat writes ride the proposal and approval flow. Phase 5, section 4.3,
  already defers confidence scoring and inferred edges behind relaticle#91 and relaticle#495.
- Value source: phase 3 gives links a nullable `source` and `confidence` pair, reserved for
  inferred edges, and a polymorphic actor (user, agent, API token, import). Scalar values
  carry neither.

## Open questions for the brainstorm

1. Release order: ships inside 4.0.0, or as the first post-4.0 minor on both sides?
2. Field types in scope on the Relaticle side: text, number, currency, select, multi-select
   as the reference does, or a narrower first cut?
3. Modes for the first cut: summarize and classify only, prompt completion, web research?
4. Manual only, as the reference does, or also on record create and update with a per-field
   switch?
5. Where the run happens: a Filament table action and a bulk action, a chat tool, both?
6. Whether an AI-written value bypasses the proposal and approval flow (the user clicked
   the button) or is itself a proposal.
7. Credit pricing per mode, and what a workspace without credits sees.
8. Whether the package needs a substrate change (autofill configuration on the field row, a
   source stamp on values, a component seam for the recalculate control), or Relaticle can
   do all of it host-side against the 4.0 surface.
9. Classify mode and option categories: whether new options the model proposes land in a
   category, and whether status-typed fields are excluded.
10. What the model is allowed to read: field values only, as the reference does, or also notes,
    tasks, and activity.

## Exit criterion

Unset until the brainstorm resolves the questions above and the spec moves to approved.
