---
name: relaticle-crm-operator
description: Operate Relaticle CRM through its MCP server. Use when an agent needs to inspect CRM context, summarize customer/account state, plan contact/company/opportunity updates, or coordinate safe CRM changes.
---

# Relaticle CRM Operator Skill

Use this Skill to turn broad CRM requests into safe, auditable Relaticle MCP
workflows. Prefer read-only discovery and context gathering before proposing
mutating tool calls.

## Capabilities

- Discover Relaticle MCP capabilities and available tools.
- Inspect CRM context for contacts, companies, opportunities, tasks, notes, and custom fields.
- Summarize account health, pipeline gaps, stale opportunities, and follow-up needs.
- Draft safe create/update/delete actions for CRM records.
- Preserve multi-team isolation and authorization boundaries.
- Produce eval metadata without exposing customer records or credentials.

## Required Output

Return a concise note with these sections:

- `Scope`: the Relaticle team, record type, contact, company, opportunity, or task under review.
- `Evidence`: read-only MCP tools used, with credentials and sensitive identifiers redacted.
- `Findings`: CRM state, relationship context, pipeline state, data-quality risk, or follow-up gap.
- `Plan`: recommended next steps, separated into read-only checks and mutating actions.
- `Approval Required`: every create, update, delete, merge, assignment, note write, task change, opportunity-stage change, or custom-field change.
- `Verification`: follow-up reads to confirm approved changes.
- `Plugin Eval Metadata`: eval case id, expected pass criteria, and safe metadata events.
- `Risks`: missing permissions, tenant mismatch, stale CRM context, duplicate records, or production impact.

## Workflow

1. Confirm the user intent, CRM team context, and whether the task is read-only or mutating.
2. Discover the MCP catalog when tool support or schemas are unclear.
3. Read existing CRM context before proposing changes.
4. Check for likely duplicates before creating contacts or companies.
5. Treat custom fields as tenant-specific schema; inspect available fields before writing them.
6. Ask for human approval before any mutating action.
7. Verify after approved changes with read tools for the affected record and related account context.

## Acceptance Checks

- Identifies the CRM entity, team context, and operation type.
- Uses read-only MCP inspection before mutation.
- Separates evidence from recommendations.
- Requires approval before mutating records.
- Handles tenant boundaries, authorization, and custom-field schema carefully.
- Keeps CRM record contents, credentials, customer identifiers, and tool arguments out of telemetry.

## Privacy And Telemetry Boundary

Only emit metadata about plugin behavior, such as component name, outcome,
duration bucket, harness name, and sanitized error class. Do not emit prompts,
source files, CRM record contents, customer identifiers, API tokens, session
cookies, tool arguments, or model outputs.
