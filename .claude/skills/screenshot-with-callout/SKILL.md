---
name: screenshot-with-callout
description: Mandatory point-of-use sequence for capturing annotated screenshots that go into deliverables (ClickUp reviews, bug repros, internal evidence, end-user docs). Invoke this BEFORE every screenshot capture — not once per session — so the rules are fresh in context at the moment you actually shoot. Covers the annotate → verify-crop → shoot → read-back flow, the helper JS files (annotate.js, verify-crop.js), and the audience-specific annotation rules (label vs. no label). If you are about to type `agent-browser screenshot file.png` for any reason other than throwaway debugging, invoke this skill first.
---

# Screenshot capture sequence (mandatory at point of use)

**Invoke this skill via the Skill tool every time you are about to take a screenshot for a deliverable.** Do not rely on having read it earlier in the session — by the time you need it, the previous read is far back in your context and you will be running on vague recall. That recall is exactly what produces full-page screenshots with no callout, evidence the reviewer can't find, and clipped frames. The fix is to re-load this skill at the moment of use so the rules are adjacent to the action.

**Hard rule, before anything else:** `agent-browser screenshot file.png` with no `--selector` and no prior annotation is **forbidden** for deliverables. Raw full-viewport shots are always wrong. The only acceptable use of `agent-browser screenshot` without these prerequisites is `/tmp/debug-*.png` files for live debugging that will never be uploaded or referenced anywhere a human will see them.

## Why screenshots fail (the three reliable failure modes)

1. **The important thing is tiny** — it's one region on a busy page, surrounded by sidebar, topbar, and unrelated widgets the reviewer already knows.
2. **The important thing is cut off** — partially below the fold, sticky header covering the heading, or a dropdown clipped at the viewport edge.
3. **There's no visual indication of what to look at** — the reviewer sees a screen full of form fields and has to guess which one you mean.

A screenshot that doesn't clearly show the thing it's meant to prove is **worse than no screenshot at all** — it forces the reviewer to either trust you blindly or re-run the flow themselves. The whole point of evidence is that it's self-describing.

## Two audiences, two annotation rules

The red outline box is always welcome. The text label is the difference:

| Audience | Red outline box | Text label on the box |
|---|---|---|
| **ClickUp business reviews, bug repros, internal evidence** | Yes | **Yes** — the reviewer needs the image to self-describe |
| **End-user documentation** (`docs/docs/en/**/*.md`) | Yes | **No** — image must not contain prose annotations |

For docs, draw a clean red outline around the element the reader should notice (no label) and explain what they're looking at in the surrounding markdown — a `>{info}` callout or a sentence immediately above the image. End users opening the docs don't want prose burned into the image; the UI should look like what they see when they open the app, with only the visual cue pointing at the element.

For business reviews, keep the label — the ClickUp reviewer isn't reading a whole paragraph around the attachment, they need the image to self-describe.

When in doubt, pass a label argument of `null` to the annotation helper to skip the text tag and keep only the outline.

## The capture sequence — every step is mandatory

### Step 1. Know what you're capturing

Write the one-sentence purpose first, before touching the browser: *"the Partner Housed Configuration section with the services-only banner visible"*. If you can't say it in one sentence, you don't yet understand what the case is supposed to prove and you're not ready to shoot.

For business reviews, this should match the `evidence:` field from your Phase 4 plan (see `business-review-task` skill). If it doesn't match, stop and update the plan first — improvising at capture time is how shots go wrong.

### Step 2. Scroll the target into the middle of the viewport

Use `scrollIntoView({block: "center"})`, **not** `{block: "start"}` — `start` pins the element under the sticky topbar where it gets covered. Then verify with `getBoundingClientRect`:

```bash
agent-browser eval 'var el = document.querySelector("SELECTOR"); var r = el.getBoundingClientRect(); JSON.stringify({top:r.top,bottom:r.bottom,left:r.left,right:r.right,vh:innerHeight,vw:innerWidth,fullyVisible: r.top>=0 && r.bottom<=innerHeight && r.left>=0 && r.right<=innerWidth})'
```

If `fullyVisible` is false, either scroll differently, expand the viewport (`set viewport 1920 1400`), or crop to the element via `--selector` in step 5. **Cut-off content is a failed shot, not a done shot.**

### Step 3. Annotate — write `/tmp/annotate.js` once per session, then call it

Save the annotation helper to `/tmp/annotate.js` once at the start of your session, then call it before every screenshot. The helper draws a red outline + optional label on a fixed-position overlay that doesn't disturb the page layout:

```bash
cat > /tmp/annotate.js <<'EOF'
(sel, label) => {
  const el = document.querySelector(sel);
  if (!el) return 'no element';
  const r = el.getBoundingClientRect();
  const box = document.createElement('div');
  box.setAttribute('data-ai-callout', '1');
  Object.assign(box.style, {
    position: 'fixed',
    top: (r.top - 6) + 'px',
    left: (r.left - 6) + 'px',
    width: (r.width + 12) + 'px',
    height: (r.height + 12) + 'px',
    border: '3px solid #ef4444',
    borderRadius: '8px',
    boxShadow: '0 0 0 4px rgba(239,68,68,0.25)',
    pointerEvents: 'none',
    zIndex: '2147483647',
  });
  if (label) {
    const tag = document.createElement('div');
    tag.textContent = label;
    Object.assign(tag.style, {
      position: 'absolute',
      top: '-28px',
      left: '-3px',
      background: '#ef4444',
      color: 'white',
      font: '600 12px/1.2 system-ui, sans-serif',
      padding: '4px 8px',
      borderRadius: '6px',
      whiteSpace: 'nowrap',
    });
    box.appendChild(tag);
  }
  document.body.appendChild(box);
  return 'annotated';
}
EOF
```

Then for each screenshot:

```bash
agent-browser eval "($(cat /tmp/annotate.js))('#partner-housed-section', 'Partner Housed Configuration')"
```

Pass `null` as the second argument to omit the label (for end-user docs).

For multiple highlights on one screenshot, call the function once per element with different labels. Keep callouts focused — **1-3 per image, never a rainbow of boxes**. A callout that highlights everything highlights nothing.

#### Annotate the element that contains BOTH the label AND the value

This is the #1 way callouts go wrong in practice. Common failure: you search the DOM for the text you care about (e.g. "Not Required"), grab the parent of that text node, and annotate it — but that only wraps the value, not the label that explains it. Or worse, you grab the label but the value renders in a separate sibling beneath it, so the callout wraps only half the story.

Before annotating, ask: **does the element I'm about to annotate contain BOTH the label and the value (or whatever pair of information makes the callout meaningful on its own)?** Verify by testing both:

```bash
agent-browser eval 'var el = document.querySelector("#ai-annot-target"); JSON.stringify({
  containsLabel: el.textContent.includes("Next Annual Recertification"),
  containsValue: el.textContent.includes("Not Required"),
  bbox: el.getBoundingClientRect()
})'
```

If either is false, climb parent nodes until both are true. Useful container conventions for this codebase:

- **Filament infolist entries**: `.fi-in-entry` (label + value, stacked)
- **Filament form fields**: `.fi-fo-field-wrp` (label + input together)
- **Filament table cells**: the `<tr>` (whole row)
- **Filament modal**: `.fi-modal-window`

A callout that wraps only the label with the value outside it is **worse than no callout** — it actively misdirects the reader to a spot where the proof *isn't*.

### Step 4. Verify the crop — write `/tmp/verify-crop.js` once, then use it on every shot

The annotation extends beyond the target element — 3px border + 4px box-shadow + optional label tag above the box, roughly 10-35px overflow on each side. So the *callout's* bbox (not the target element's) is what must fit inside your crop, on **all four edges**. If you hand-write this check inline every shot, you will forget to check one axis (probably the right edge — most failures are vertical, so left/right gets ignored).

Save the helper once per session:

```bash
cat > /tmp/verify-crop.js <<'EOF'
(planned) => {
  const c = document.querySelector('[data-ai-callout]')?.getBoundingClientRect();
  if (!c) return { error: 'NO CALLOUT on page — annotate before verifying' };
  const cropTop = planned.top;
  const cropLeft = planned.left;
  const cropBottom = planned.top + planned.height;
  const cropRight = planned.left + planned.width;
  const fits = {
    top: c.top >= cropTop,
    bottom: c.bottom <= cropBottom,
    left: c.left >= cropLeft,
    right: c.right <= cropRight,
  };
  const allFit = fits.top && fits.bottom && fits.left && fits.right;
  const callout = { left: Math.round(c.left), top: Math.round(c.top), right: Math.round(c.right), bottom: Math.round(c.bottom) };
  const crop = { left: cropLeft, top: cropTop, right: cropRight, bottom: cropBottom };
  if (allFit) return { ok: true, callout, crop };
  const needed = {
    top: fits.top ? null : `reduce planned.top by ${Math.ceil(cropTop - c.top)}`,
    bottom: fits.bottom ? null : `grow planned.height by ${Math.ceil(c.bottom - cropBottom)}`,
    left: fits.left ? null : `reduce planned.left by ${Math.ceil(cropLeft - c.left)}`,
    right: fits.right ? null : `grow planned.width by ${Math.ceil(c.right - cropRight)}`,
  };
  return { ok: false, fits, callout, crop, needed };
}
EOF
```

Then before every shot:

```bash
agent-browser eval "($(cat /tmp/verify-crop.js))({top: 96, left: 0, height: 760, width: 1558})"
```

**The result MUST be `{"ok": true, ...}` before you take the screenshot.** If it returns `{"ok": false, "needed": {...}}`, apply the adjustment verbatim — grow/shrink your crop parameters as the `needed` field instructs — and re-run the helper until it returns ok.

**Do not proceed to `agent-browser screenshot` while `ok` is false.** Cropping after the fact on a shot where the callout is clipped is unrecoverable — you'll chop off the value, chop off the red box, or chop off the right edge. Yes, the right edge — don't forget left/right just because most failures are vertical.

**The rule: no shot without a passing `verify-crop.js` result in the immediately-preceding tool call.** If you find yourself typing `c.top >= planned.top && c.bottom <= ...` into an eval inline, stop — use the helper. The helper exists precisely because you will forget an axis if you write the check by hand.

### Step 5. Dismiss anything floating over the target

Debugbar, toast notifications, impersonation banners, tooltip popups — all of these will end up in your screenshot if you don't hide them first. Re-run your cleanup JS right before the screenshot. See the "Cleanup JS pattern" section in `agent-browser-journey` for the full helper.

Quick version:

```bash
agent-browser eval '
  document.querySelectorAll("[id*=debugbar], .phpdebugbar").forEach(n => n.style.display = "none");
  Array.from(document.querySelectorAll("button")).filter(b => b.textContent.trim() === "Dismiss").forEach(b => b.click());
  true
'
```

### Step 6. Shoot

```bash
agent-browser screenshot --selector 'SELECTOR' /tmp/.../caseN-N-slug.png
```

Or, if you're cropping a verified rectangle from a full viewport (not using `--selector`):

```bash
agent-browser screenshot /tmp/raw.png
sips --cropToHeightWidth 760 1558 --cropOffset 96 0 /tmp/raw.png --out /tmp/.../caseN-N-slug.png
```

The `sips` numbers must match exactly what you passed to `verify-crop.js`. If you change the crop after verification, re-verify.

### Step 7. Read the PNG back and name the evidence out loud

This is **non-negotiable**. Open the file with the Read tool and write down what you see. **Name the literal evidence text**, not vague impressions.

✅ Good: *"I can see the label 'Reserved For' and the value 'Hopkins, David' directly beneath it, both inside the red callout box. The top edge of the callout is visible, the bottom edge is visible, the date '04/09/2026' below the name is also inside the box, and nothing is clipped against any image edge."*

❌ Bad: *"the reserved column looks right"*, *"visible in the overview area"*, *"the modal is showing what we want"*, *"close enough"*.

If you find yourself writing one of the bad phrases, **STOP**. That phrasing means you haven't actually looked carefully — you're rationalizing a shot you already took. Go back, look for the literal words, and either confirm them or retake.

#### What to check when reading the PNG

1. **State the concrete thing** — name the literal text/element that proves the case, present in the image, inside the red box.
2. **Trace each edge** — top, bottom, left, right. None of the callout (or the evidence inside it) is clipped against the image border.
3. **Sanity-check against the step-1 purpose** — does the image literally show what you said you were capturing? If you wrote "the services-only banner visible" and the banner isn't in the frame, the shot is broken. No exceptions.
4. **Read the surrounding context** — does anything in the image contradict what the case claims (a stale notification, a leftover modal from a previous case, the wrong tenant in the topbar)?

If any check fails, retake. **A bad screenshot is a defect you're embedding in the deliverable**, and the most expensive place to catch it is after the reviewer has already read it.

### Step 8. Clean up the callout before you navigate away

Stale annotations persist in the DOM across SPA navigation and will appear on the next page's screenshot pinned to the wrong element. Remove them every time:

```bash
agent-browser eval 'document.querySelectorAll("[data-ai-callout]").forEach(n => n.remove()); "cleared"'
```

### Step 9 (only if doing a light/dark pair). Capture the dark-mode pair AFTER light passes

Switching to dark and immediately taking a second shot means you double your failures if the framing was wrong. **Verify the light shot first**, then:

```bash
agent-browser set media dark
agent-browser wait 400   # let the CSS transition settle
# re-apply the annotation (dark mode may have re-rendered the DOM)
agent-browser eval "($(cat /tmp/annotate.js))('#target', 'label')"
# re-run verify-crop.js
# screenshot
agent-browser eval 'document.querySelectorAll("[data-ai-callout]").forEach(n => n.remove())'
agent-browser set media light
```

## Common mistakes to avoid

- **Don't screenshot a modal with only its header visible.** Modals often extend below the fold at 1080px height. Either expand the viewport (`set viewport 1920 1400`), scroll inside the modal, or crop to `.fi-modal-window` with `--selector`.
- **Don't rely on refs (`@eXX`) for positioning.** Refs go stale between snapshots. Use CSS selectors or text matching for the annotation step.
- **Don't forget to remove callouts before navigating away.** Stale annotations bleed across pages and into the next case's screenshot.
- **Don't annotate every element on the page.** If three things matter, box three things — not thirty.
- **Don't take a screenshot when the page is still loading.** Wait for Livewire to settle (`wait 1500-3000` after actions, longer after form submits). Half-rendered skeletons are useless.
- **Don't rationalize a bad shot under time pressure.** "I'm on case 9 of 12 and tired" is exactly when shots start slipping. The whole reason these rules exist is that pressure is fallible — that's the moment to slow down, not speed up.

## When in doubt, crop

If you can't reliably frame a full-viewport shot, give up on the full viewport and crop to the DOM element with `--selector`. A cropped screenshot of just the form section with a callout around the key toggle is far more valuable than a full-page shot where that toggle is 40 pixels tall in the corner.

## What this skill does NOT cover

This skill is just about the capture sequence. It doesn't cover:
- **Where the resulting PNG goes** (uploading to ClickUp, embedding in a doc, attaching to a PR comment) — that's in the calling skill, e.g. `business-review-task` Phase 5 step 8 handles uploading to the ClickUp attachment endpoint.
- **What to capture for a given case** — that's planned at Phase 4 of `business-review-task`. By the time you invoke this skill, the `selector` / `callout_target` / `callout_label` / `evidence` should already be in your plan.
- **General agent-browser quirks** (login, tenant switching, Filament Select, action modals, etc.) — those are in `agent-browser-journey`.

This skill is intentionally narrow: it's the 9-step sequence you walk through in the moment of capture, nothing more. That's the whole point — it's small enough to re-load every time you need it.
