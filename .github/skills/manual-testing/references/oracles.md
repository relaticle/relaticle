# Oracle Catalogue (FEW HICCUPPS)

Every reported bug must cite **one letter** from this catalogue as its detection oracle. No vibes-based findings.

If the skill cannot articulate which oracle was violated, the observation is not a bug — it gets logged as a "neutral note" instead and is not reported.

| Letter | Oracle | What it tests | Relaticle example of a violation |
|---|---|---|---|
| **F** | Familiar | Pattern we've seen break before | Loading state spinner not cleared after error response |
| **E** | Explainable | User can articulate what just happened | 500 page with no error message; `<x-error>` blank |
| **W** | World | Real-world expectation | "Save" button shows success notification but DB unchanged |
| **H** | History | Worked before this change | Filament action that worked yesterday now throws |
| **I** | Image | Relaticle quality bar | AI-slop layout, broken alignment, inconsistent typography |
| **C** | Comparable | Other CRMs handle differently | Bulk edit modal in HubSpot supports undo; this one doesn't acknowledge changes |
| **C** | Claims | README/docs/marketing claim violated | README says "5-layer authorization"; cross-tenant probe succeeds |
| **U** | Users | Real workflow need | Salesperson can't undo accidental delete of a Company |
| **P** | Product | Internal consistency | CompanyResource sorts by name asc by default; PeopleResource sorts by created_at desc — same table component, different default |
| **P** | Purpose | Feature's stated purpose / Definition of Done violation | Charter DoD says "validation errors identical across surfaces"; UI shows field-level, API returns plain string |
| **S** | Statutes | GDPR/PII/encryption/AGPL compliance | Encrypted custom field returns plaintext in API response |

## Disambiguating C / C and P / P

There are two C's and two P's. The skill must pick the more specific one:

- **Comparable** vs **Claims**: if a competing product has a feature, that's *Comparable*. If our own README/docs assert it, that's *Claims*. *Claims* wins when both apply.
- **Product** vs **Purpose**: if the inconsistency is across the codebase, that's *Product*. If it's a violation of *this charter's* DoD, that's *Purpose*. *Purpose* wins when both apply.

## How to use

When evaluating a matrix-cell observation, ask:

1. Did something fail or look wrong? → check oracle list.
2. Pick the **most specific** oracle violated.
3. If you can't pick one, the observation is not a reportable bug.
4. Record the chosen oracle in the finding (`**Oracle:** Claims`).

A finding without an oracle is rejected by the skill at the triage stage.

## Image oracle — structured rubric

The Image oracle is the highest false-positive risk in the catalogue because "looks wrong" is subjective. The skill must answer this rubric with **Yes / No / N/A** for every page touched by the diff, at the developer-default viewport (1440×900) and at one mobile viewport (375×812). A "No" is a candidate Image-oracle finding; record which item failed.

| # | Question | If "No," typical severity |
|---|---|---|
| 1 | Does every visible button, link, and form control have a clearly distinct visual style from surrounding text? | High (affordance) |
| 2 | Are all text elements legible (no overlap, no clipping, no `text-overflow: ellipsis` cutting load-bearing content)? | High |
| 3 | Are headings, body text, and labels using a consistent type scale across the page (no random font sizes)? | Medium |
| 4 | Are all icons the correct variant per CLAUDE.md icon rules — UI/functional = `line`, brand/social = `fill`, status/emphasis = `fill`? | Medium |
| 5 | Is spacing between sibling elements visually consistent (no surprise gaps, no cramped pairs)? | Medium |
| 6 | Are colors drawn from the design tokens — no hardcoded hex that bypasses the theme? | Medium |
| 7 | Do interactive elements have a visible hover state and visible focus ring (not just `cursor: pointer`)? | Medium (Image) — High when probe P6 also fires |
| 8 | Does the page hierarchy guide the eye to the primary action within 1 second of looking? | Medium |
| 9 | Does the page render acceptably in dark mode (if app supports dark mode) with no white-on-white or invisible text? | High when broken |
| 10 | Are empty states informative (not a blank rectangle), and are error states recoverable (not a stack trace)? | High |
| 11 | Does the page avoid AI-slop patterns: random emoji in headers, redundant trailing periods on labels, mid-sentence Capitalization of Random Words, "Click here →" links, generic-stock placeholder text? | Medium |
| 12 | Are aligned items actually aligned — table columns, form labels, button rows, card grids? | Medium |

**How to apply:**

1. After running visual probes at every viewport, open the captured screenshots.
2. Answer the rubric **once per page** (default viewport) and re-answer items 1, 2, 5, 9, 12 at the mobile viewport.
3. For every "No," cite the item number in the finding: `**Oracle:** Image (rubric item #5: spacing inconsistent in CompanyResource header)`.
4. If the same item fails on multiple pages with the same root cause, file as **one** finding with all affected pages listed.
5. If items 1, 2, or 10 fail, also re-check whether a probe missed it — probes should catch most #1 (P3, P6), #2 (P1, P2), #10 (P4 for stuck loading) failures. A rubric-fail without a probe-fail signals the probes need extension.
