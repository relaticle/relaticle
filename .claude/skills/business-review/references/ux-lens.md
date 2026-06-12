# Experiential UX lens (friction, not pixels)

This lens fixes failure mode D (the UX miss): reports that read fine but miss the
friction a human PM would actually feel.

## Scope (sharp)

This lens catches **bad** UX a PM would feel. It does **NOT** do pixel/visual-design
critique — that's `design-review`'s job and an explicit non-goal here. It does **NOT**
replace the **health gate**, which catches *broken* pages (500s, missing assets,
console errors). Broken = health gate. Ugly = `design-review`. **Bad-to-use =
this lens.**

A persona runs this lens **continuously** as it walks a journey — every friction
signal it observes from the browser becomes a `ux_friction[]` entry.

## The heuristics

A bounded checklist. Each signal must be **observable from the browser** — an actual
behavior the persona saw — not an aesthetic opinion.

1. **Action with no feedback** — clicked Save/Submit/Publish and nothing visibly
   confirmed it happened (no toast, no state change, no redirect).
2. **Missing empty / loading / error states** — a blank screen instead of "no
   submissions yet", a frozen blank during a slow load, or no error UI when
   something fails.
3. **Error copy that is not human or not recoverable** — "Error 500" / a raw stack
   instead of "Couldn't save — try again"; or an error that leaves no way forward.
4. **Unclear primary action** — the persona can't tell what to click to proceed; the
   main next step isn't obvious.
5. **Dead-ends** — a state with no obvious next step and no way back (no link, no
   button, browser-back is the only escape).
6. **Destructive action with no confirm, or a confirm with no undo** — delete/discard
   fires immediately with no guard, or guards but offers no recovery.
7. **Silent failure** — an action appears to do nothing: no error, no success, no
   change. (The worst kind — the user can't tell whether it worked.)

## Severity rubric

- **high** — blocks or seriously confuses a real user **on the value path** (they
  can't complete the journey, or are badly misled about whether it worked).
- **medium** — friction that survives but annoys: the user gets there, irritated or
  briefly lost.
- **low** — a papercut: minor, cosmetic-adjacent friction that doesn't impede the goal.

## How it's recorded

Each friction item goes into the persona's `ux_friction[]` (per the findings schema in
SPEC §16.2), with exactly:

```json
{"journey": "J5", "note": "Submit Payment button enabled with no covering grant — no pre-flight warning shown before the 500", "severity": "high"}
```

- UX friction does **NOT by itself gate the verdict** — it is not a confirmed
  functional blocker (a real broken behavior is a `bug` → goes through adversarial
  verification instead).
- `ux_friction_count` is surfaced in `verdict-final.json` (the aggregator counts
  every persona's items).
- A **`high`-severity** friction item is a strong signal: it **pushes the report's
  judgment toward `ai-needs-human`** even when no functional blocker exists — a PM
  would not happily ship value buried under high friction. The report (`report.md`)
  weighs this; the deterministic label stays `ai-approved` only if the human-judgment
  pass agrees the friction is acceptable.
