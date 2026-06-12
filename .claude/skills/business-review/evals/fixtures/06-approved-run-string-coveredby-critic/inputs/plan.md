<!--json
{
  "pr_number": 336,
  "sha": "8a6b103b5381e755d4986357f1ddee83261bcd4d",
  "tier": 2,
  "computed_tier": 3,
  "tier_rationale": "compute_tier returned 3 on classifier false-positives ('auth' = incidental ->middleware('auth') string; 'tenant' = existing {tenant} route-param plumbing). No auth/login/session or tenant-isolation LOGIC changed. Real blast radius = nav/UI refactor across Tasks+Opportunities list/board surfaces. Re-tiered to 2 per references/tiering.md 'sanity-check the classifier'; tenant-isolation cross-team probe + board drag/drop carried forward as due-diligence.",
  "channel": "healthy",
  "changed_surfaces": [
    {
      "covered_by": "tasks-view-switching",
      "id": "Tasks resource list (ManageTasks)"
    },
    {
      "covered_by": "tasks-view-switching",
      "gated": true,
      "gate_precondition": "Task status custom field exists (seeded)",
      "id": "Tasks board (/tasks/board)"
    },
    {
      "covered_by": "opportunities-view-switching",
      "id": "Opportunities resource list (ListOpportunities)"
    },
    {
      "covered_by": "opportunities-view-switching",
      "gated": true,
      "gate_precondition": "Opportunity stage custom field exists (seeded)",
      "id": "Opportunities board (/opportunities/board)"
    },
    {
      "covered_by": "sidebar-nav-consolidation",
      "id": "Sidebar navigation"
    },
    {
      "covered_by": "legacy-url-redirect",
      "id": "Legacy board redirects"
    }
  ],
  "journeys": [
    {
      "id": "tasks-view-switching",
      "synthesized": true,
      "personas": [
        "team-owner"
      ],
      "happy_path": [
        "Open Tasks (list view)",
        "Confirm List/Board switcher renders after the title with List active",
        "Click Board -> board renders, switcher now shows Board active, switcher in same position",
        "Click List -> back to list, switcher stable"
      ],
      "sad_paths": [
        "Deep-link directly to /tasks/board via URL (not via switcher) -> board must render, not 404 or record view"
      ],
      "acs": [
        2,
        3
      ],
      "name": "Tasks list/board view switching",
      "covers_surfaces": [
        "Tasks resource list (ManageTasks)",
        "Tasks board (/tasks/board)"
      ]
    },
    {
      "id": "opportunities-view-switching",
      "synthesized": true,
      "personas": [
        "team-owner"
      ],
      "happy_path": [
        "Open Opportunities (list view)",
        "Confirm switcher after title, List active",
        "Click Board -> board renders, switcher Board active, stable position",
        "Toggle back"
      ],
      "sad_paths": [
        "Deep-link directly to /opportunities/board -> MUST render the board, NOT be interpreted as viewing a record with id 'board' (the /{record} route is registered after /board)"
      ],
      "acs": [
        2,
        3
      ],
      "name": "Opportunities list/board view switching",
      "covers_surfaces": [
        "Opportunities resource list (ListOpportunities)",
        "Opportunities board (/opportunities/board)"
      ]
    },
    {
      "id": "board-functionality-preserved",
      "synthesized": true,
      "personas": [
        "team-owner"
      ],
      "happy_path": [
        "On Tasks board and Opportunities board: confirm columns render from status/stage options, cards appear in correct columns"
      ],
      "sad_paths": [
        "Open a board for an entity that has no records -> empty columns render without error"
      ],
      "acs": [
        6
      ],
      "name": "Board drag/drop & rendering preserved"
    },
    {
      "id": "sidebar-nav-consolidation",
      "synthesized": true,
      "personas": [
        "team-owner"
      ],
      "happy_path": [
        "Inspect the app sidebar: Tasks and Opportunities each appear as a SINGLE link; no nested 'Board' sub-entry remains under either"
      ],
      "sad_paths": [
        "Collapse/expand sidebar -> still no orphaned Board entry"
      ],
      "acs": [
        1
      ],
      "name": "Sidebar consolidated to one entry per entity",
      "covers_surfaces": [
        "Sidebar navigation"
      ]
    },
    {
      "id": "legacy-url-redirect",
      "synthesized": true,
      "personas": [
        "team-owner"
      ],
      "happy_path": [
        "Navigate to legacy /{tenant}/tasks-board and /{tenant}/opportunities-board -> 301 redirect lands on the new /tasks/board and /opportunities/board"
      ],
      "sad_paths": [
        "Hit legacy URL while logged out -> redirected to login (no error/leak)"
      ],
      "acs": [
        5
      ],
      "name": "Legacy board URLs 301-redirect",
      "covers_surfaces": [
        "Legacy board redirects"
      ]
    },
    {
      "id": "board-gate-and-tenant-isolation",
      "synthesized": true,
      "personas": [
        "team-owner"
      ],
      "happy_path": [
        "Confirm the Board segment of the switcher only shows when the status/stage custom field exists for the active team"
      ],
      "sad_paths": [
        "Cross-tenant probe: while authenticated, request another team's board URL -> 403/redirect, no cross-team data"
      ],
      "acs": [
        4
      ],
      "name": "Board gate + tenant isolation"
    }
  ],
  "persona_rationale": "Single persona (team-owner): every touched surface is team-scoped CRM navigation identical for owner and member; no role-differentiated behavior in the diff. Breadth achieved via lenses (functional, routing, isolation, dark/mobile/a11y) rather than role archetypes."
}
-->

# Plan — PR #336 (unify list & board views under task/opportunity resources)

Tier 2 (computed 3, re-tiered — see frontmatter). Channel healthy. Regression sweep: 0 matched (no enum/form/import triggers). Sequential inline walk (single authenticated session; journeys are tightly coupled to one logged-in team). 6 synthesized journeys, each tracing to a real touched surface.
