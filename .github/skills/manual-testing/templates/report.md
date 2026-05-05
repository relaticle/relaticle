<!-- Manual-Testing Report Template

Save filled to: .context/testing/reports/YYYY-MM-DD-HHMM-<task-slug>.md -->

# Manual Test Report: <task-slug>

**Run:** <ISO 8601 timestamp> · **Branch:** <git branch> · **HEAD:** <short sha>
**Diff scope:** <files-changed>, <insertions>+/<deletions>-

## Risk classification

- **Likelihood:** <N> — <reason, anchor selected>
- **Impact:** <N> — <reason, including high-impact tag matches if any>
- **Score:** <L>×<I>=<Total> → <smoke|light|medium|deep>
- **Surfaces touched:** <comma-separated list>
- **Auto-fix ceiling:** <Disabled | High only | High only excluding auth/tenancy/migration>

## Charter

**Explore** <area> **with** <personas, tours, surfaces, time budget> **to discover** <info>.

**Definition of Done:**
- [<x or  >] <DoD item 1>
- [<x or  >] <DoD item 2>
- [<x or  >] <DoD item 3>

## Matrix executed

| Cell | Persona | Surface | Tour | Data-state | Outcome |
|------|---------|---------|------|------------|---------|
| 1 | Default | Filament UI | Garbage Collector | typical | OK |
| 2 | Cross-Tenant Spy | REST API | (n/a) | other-team-id | F1 (Critical) |
| 3 | ... | | | | |

## Visual sweep

(Only when a Filament UI / Livewire surface is in the matrix. One block per page touched.)

### Page: <route> (<viewport>)

| Probe | Count | Notes |
|---|---|---|
| P1 horizontal-overflow | <N> | <one-liner if N>0> |
| P2 text-clipping | <N> | |
| P3 tap-target-undersize | <N> | |
| P4 stuck-loading | <N> | |
| P5 image-hygiene | <N> | |
| P6 no-focus-ring | <N>/<sampled> | |
| P7 low-contrast | <N> | |
| P8 cls | <value> | |

**Image-oracle rubric** (`oracles.md` items 1-12, default viewport): <Y/Y/Y/N/Y/...> · mobile re-check (1,2,5,9,12): <Y/Y/N/Y/Y>

**State sweep:** empty=<OK|F-N|N/A> · one=<...> · many=<...> · loading=<...> · error=<...> · disabled=<...> · hover=<...> · focus=<...> · dark=<...>

**Screenshots:** `.context/testing/reports/<run-slug>/<page>-<viewport>.png` (one per state × viewport)

(Repeat block per page.)

## Findings

### F1 — <Severity> · <Title>

**Oracle:** <FEW HICCUPPS letter and name>

**RIMGEA:**
- *Replicate:* <3/3, conditions>
- *Isolate:* <smallest repro action sequence>
- *Maximize:* <worst-case impact discovered>
- *Generalize:* <sibling-resource probe results>
- *Externalize:* <real-user impact in plain language>
- *Articulate:* <one-paragraph summary>

**Repro steps:**
1. ...
2. ...
3. ...

**Screenshot:** <relative path>

**Console / network:**
```
<relevant log snippet>
```

**Action:** <BLOCKED — awaiting triage | Auto-fixed in attempt N | Listed for user triage>

### F2 — ...

## Auto-fix attempts

(Per High finding that entered the auto-fix loop.)

### F2 attempts

| Attempt | Hypothesis | Patch | Outcome |
|---|---|---|---|
| 1 | <one sentence> | <diff snippet, <30 lines> | <PASS / FAIL with reason> |
| 2 | ... | ... | ... |

Final state: <fixed / exhausted / handed off>.

## Regression tests written

- `<test path>` — <appended/created>, <passes locally>, staged for commit.

## PROOF debrief

- **Past:** <what ran, time taken, cells executed of planned>
- **Results:** <severity counts, fixed/deferred split, tests written>
- **Obstacles:** <what blocked, time-exhausted cells, environment issues>
- **Outlook:** <what's needed next, open Critical findings, follow-up items>
- **Feedback:** <any new pattern observed that should feed back into personas/tours/oracles for future runs>

## Running totals (post-run)

- Critical: <N>
- High: <N>
- Medium: <N>
- Low: <N>
- Regression tests written across all runs: <N>
- Auto-fix success rate: <0-1>
