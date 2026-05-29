# Phase 6 — Screenshot rules

These rules are the #1 source of low-quality reviews. Past runs have shipped full-page shots where the evidence was a 40-pixel region in a corner, no callout, and the reviewer had to either trust blindly or re-run the flow. That is worse than no screenshot. Do not do that.

## Forbidden

- **`agent-browser screenshot file.png`** with no `--selector` and no prior annotation. Always wrong for deliverables.
- **"This one shot covers four cases."** If a single screenshot is supposed to prove four things, take four tightly-cropped shots, one per thing.
- **Skipping the read-back step under time pressure.** When you're tired, this is exactly when shots start slipping. The rule exists *because* of that pressure.
- **"The evidence is visible somewhere in the frame."** The evidence must be inside a red callout box, with a label the reviewer can find in under two seconds.

## Required

- **The callout target must contain BOTH the label AND the value** that together make the evidence meaningful on its own.
- **Walk the annotate → verify-crop → shoot → read-back sequence in full** (described below) for EVERY screenshot. By case 7 of 12, "I remember the rules" is the failure mode.
- **Save the resulting PNG** to `$REVIEW_DIR/case<N>/screenshot.png`. No improvising the path.

## The annotate → verify-crop → shoot → read-back sequence

For every deliverable screenshot:

1. **Annotate** — inject a red-bordered overlay around `callout_target` via `agent-browser eval`. The overlay must contain `callout_label` rendered legibly (≥ 14px font, contrast against page background).
2. **Verify-crop** — confirm the `selector` region fully contains the callout overlay AND the literal `evidence` text. If the evidence is clipped, widen the selector before shooting.
3. **Shoot** — `agent-browser screenshot --selector '<selector>' "$REVIEW_DIR/case<N>/screenshot.png"`.
4. **Read-back** — read the PNG back yourself (or describe it via vision) and confirm: red callout visible, label legible, evidence text inside the box, no critical content clipped.

If any step fails the check, re-annotate / re-crop / re-shoot. Never ship a screenshot that didn't pass read-back.

## The four required `screenshot:` fields in the plan

Every browser-mode case must specify all four before execution begins:

| Field | Purpose |
|---|---|
| `selector` | CSS selector to crop the screenshot to (e.g., `.fi-modal-window`, `tr[data-key="42"]`) |
| `callout_target` | Element the red box wraps — must contain BOTH the label and the value |
| `callout_label` | Short text tag (e.g., "Stripe field hidden", "Currency renders EUR") |
| `evidence` | The exact literal text that must be visible inside the red box for the shot to count as proof |

If a case doesn't need a screenshot (pure DB-state verification, or proven by an earlier case), use the `none` form:

```json
"screenshot": {"none": "covered by case 1.1 — same modal"}
```

The `none` value must be a non-empty string explaining why. Setting `none` AND any of the four populated fields together is rejected by the validator — pick one mode per case.

## A review with 3 tight, annotated, read-back-verified screenshots is worth more than a review with 14 full-page shots.
