# Custom Fields 4.0, Phase 5: Host Adoption and Release

Status: approved. Program: Custom Fields 4.0 (phase 5 of 5).
Plans: 2026-09-03-cf4-p5.1-relaticle-adoption.md (relaticle/relaticle),
2026-09-03-cf4-p5.2-release.md (package release). Depends on phases 1 through 4 tagged.
Tracking: relaticle/custom-fields#210.

## Ownership

Layered-A: the package owns the substrate; Relaticle core owns all AI intelligence. Nothing
in 4.2 is ever packaged.

## 4.1 Surface migration at 4.0

- Chat bridge services survive (same id-array shape). Schema describer gains cardinality and
  relationship code. Proposal cards render chips with names. The silent-inverse-write concern
  from the old plan dissolves: one row is one fact; the other side is a read. The record-field
  detector switches from `lookup_type !== null` to "has a relationship definition".
- MCP and REST API: payload unchanged; record fields become filterable and sortable in the
  API; Scribe regenerates; GetCrmSchemaTool exposes the vocabulary. The raw json_value search
  in SearchTool is replaced or explicitly retired.
- ImportWizard: same payload path; imports stamp source = import.
- Activity log: one listener writes timeline entries on both records from one event. It must
  ship in the same release as the substrate bump: record-field changes stop flowing through
  custom_field_values, so the old observer goes silent on them.
- Option categories: the host half is phase 2.2 and lands in this same Relaticle release.

## 4.2 Intelligence (core-only, never packaged)

- GetRelatedRecordsTool in 4.0: depth-limited recursive-CTE traversal, grouped by relationship.
- Trust boundary unchanged: link writes ride the proposal/approval flow; the ledger stamps
  the actor either way.
- Headless-edge contract defined, not built: core will create 0-field definitions
  (works_with, source = ai_inferred, confidence) via the package service when email
  intelligence (relaticle#91) lands. 4.0 ships substrate and contract only.

## 4.3 Deferred

Inferred edges, confidence scoring, graph visualization, multi-hop UI. Gated on relaticle#91
and relaticle#495.

## 5.2 Licensing

The commercial license gains a field-of-use clause at the 4.0 boundary: not usable to build
competing CRM products. 3.x terms stay as sold. Rationale: the AGPL side is already viral for
closed-source competitors; the clause closes the one real leak, which was the commercial
license itself. The clause is announced with the release, not buried in a changelog.

## 5.3 Rollout

1. Development on the 4.x branch; Relaticle wired via composer path repository between the
   pre-release tags, and against the tags themselves at each phase boundary.
2. Migration rehearsal against a copy of Relaticle production data (1,573 fields), verified in
   both directions (set and clear) through panel and API paths; then the purge step; then the
   category backfill dry-run and its unmatched-option report.
3. Tag 4.0.0; one Relaticle PR bumps the constraint and carries the surface migration;
   business-review of the full loop on the production-shaped stack (Horizon, Redis, Reverb).
4. Announce: package changelog, upgrade guide, licensing clause, Relaticle release notes,
   follow-up on relaticle#469, close custom-fields#53 and relaticle#556 from the shipping PRs.
5. Verify Packagist picked up the tag before announcing (it has missed close pushes before).

## Testing strategy

Relaticle (Feature and Browser): the chat propose-approve-timeline loop for link writes;
API filter and sort on record fields; import with a relationship column; activity-log entries
on both records; GetRelatedRecordsTool traversal.

## Open item

The public reply on #469 promised paired fields with no date. A follow-up posts when 4.0 ships.

## Exit criterion

`4.0.0` tagged and on Packagist, Relaticle on the release with the full loop business-reviewed,
announcements out.
