# Visual Probes

Deterministic checks the skill runs via `agent-browser eval` to catch concrete visual failures that don't depend on Claude's judgment. Screenshots and the Image oracle (`oracles.md`) cover the subjective layer; these probes cover the measurable one.

Each probe is a self-contained IIFE that returns a JSON object: `{ type, viewport, count, offenders[] }`. Run them at every viewport in the Supermodel sweep. Findings with `count > 0` become reportable observations — Claude must still decide if the offending element is in-scope (changed in the diff or downstream of it). Out-of-scope offenders go in the "neutral notes" section, not as findings.

## How to run

```bash
agent-browser open <url>
agent-browser wait --load networkidle
agent-browser eval "<paste probe IIFE here>"
```

For a full sweep, run every probe at every viewport:

```bash
for vp in "320 568" "375 812" "768 1024" "1024 768" "1440 900"; do
  read w h <<< "$vp"
  agent-browser set viewport "$w" "$h"
  agent-browser eval "<probe>"
done
```

Aggregate the results and merge same-element duplicates across viewports (an element that overflows at 320 *and* 375 is one finding, not two).

---

## P1 — Horizontal overflow

Any element extending past the viewport's right edge. Catches sidebar bleed, tables that don't wrap, fixed-width buttons.

```javascript
(() => {
  const docW = document.documentElement.clientWidth;
  const out = [];
  for (const el of document.querySelectorAll('body *')) {
    const r = el.getBoundingClientRect();
    if (r.right > docW + 1 && r.width > 0 && r.height > 0) {
      const cs = getComputedStyle(el);
      if (cs.position === 'fixed' && cs.right === '0px') continue;
      out.push({
        tag: el.tagName.toLowerCase(),
        cls: (el.className.baseVal ?? el.className).toString().slice(0, 80),
        text: (el.textContent || '').trim().slice(0, 60),
        right: Math.round(r.right),
        overflow_px: Math.round(r.right - docW),
      });
    }
  }
  return { type: 'horizontal-overflow', viewport: docW, count: out.length, offenders: out.slice(0, 10) };
})()
```

## P2 — Text clipping

Leaf elements where `scrollWidth > clientWidth` — text is being cut off or hidden by `overflow: hidden` without an ellipsis.

```javascript
(() => {
  const out = [];
  for (const el of document.querySelectorAll('body *')) {
    if (el.children.length > 0) continue;
    const txt = (el.textContent || '').trim();
    if (!txt) continue;
    if (el.scrollWidth > el.clientWidth + 1) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      out.push({
        tag: el.tagName.toLowerCase(),
        cls: (el.className.baseVal ?? el.className).toString().slice(0, 80),
        text: txt.slice(0, 80),
        scroll_w: el.scrollWidth,
        client_w: el.clientWidth,
        ellipsis: getComputedStyle(el).textOverflow === 'ellipsis',
      });
    }
  }
  return { type: 'text-clipping', count: out.length, offenders: out.slice(0, 15) };
})()
```

## P3 — Undersized tap targets

Interactive elements smaller than 44×44 CSS pixels (WCAG 2.5.5 AA). False positives are common (icon-only buttons inside larger hit areas) — Claude must verify by clicking the element in the screenshot.

```javascript
(() => {
  const sel = 'a[href], button, [role="button"], input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])';
  const out = [];
  for (const el of document.querySelectorAll(sel)) {
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    if (getComputedStyle(el).display === 'none') continue;
    if (r.width < 44 || r.height < 44) {
      out.push({
        tag: el.tagName.toLowerCase(),
        cls: (el.className.baseVal ?? el.className).toString().slice(0, 80),
        text: (el.textContent || el.value || el.getAttribute('aria-label') || '').trim().slice(0, 60),
        w: Math.round(r.width),
        h: Math.round(r.height),
      });
    }
  }
  return { type: 'tap-target-undersize', count: out.length, offenders: out.slice(0, 15) };
})()
```

## P4 — Stuck loading indicators

Spinners, progressbars, or `wire:loading` elements still visible after the page has settled. Run after `agent-browser wait --load networkidle` plus a 1s pause.

```javascript
(() => {
  const sel = '[role="progressbar"], .fi-loading-indicator, [wire\\:loading], .spinner, .animate-spin';
  const out = [];
  for (const el of document.querySelectorAll(sel)) {
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    if (r.width === 0 || r.height === 0) continue;
    if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') continue;
    out.push({
      tag: el.tagName.toLowerCase(),
      cls: (el.className.baseVal ?? el.className).toString().slice(0, 80),
      role: el.getAttribute('role'),
      wire_loading: el.hasAttribute('wire:loading'),
    });
  }
  return { type: 'stuck-loading', count: out.length, offenders: out };
})()
```

## P5 — Image hygiene

`<img>` without `alt`, with broken `src`, or rendering at zero natural size. Decorative images need `alt=""` AND `aria-hidden="true"` to pass.

```javascript
(() => {
  const out = [];
  for (const img of document.querySelectorAll('img')) {
    const r = img.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    const decorative = img.alt === '' && img.getAttribute('aria-hidden') === 'true';
    const issue = [];
    if (!img.hasAttribute('alt')) issue.push('missing-alt');
    if (img.alt === '' && !decorative) issue.push('empty-alt-not-decorative');
    if (img.naturalWidth === 0 && img.complete) issue.push('broken-src');
    if (issue.length === 0) continue;
    out.push({
      src: (img.currentSrc || img.src || '').slice(-80),
      alt: img.alt,
      issue,
    });
  }
  return { type: 'image-hygiene', count: out.length, offenders: out };
})()
```

## P6 — Focus visibility

Tab through interactive elements; report any whose computed `outline-width` is `0px` and `box-shadow` doesn't change on `:focus-visible`. Run from a fresh page load.

```javascript
(async () => {
  const sel = 'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const els = [...document.querySelectorAll(sel)].slice(0, 30);
  const out = [];
  for (const el of els) {
    const before = getComputedStyle(el);
    const beforeOutline = before.outlineWidth;
    const beforeShadow = before.boxShadow;
    el.focus();
    await new Promise(r => requestAnimationFrame(r));
    const after = getComputedStyle(el);
    const outlineChanged = after.outlineWidth !== beforeOutline && after.outlineWidth !== '0px';
    const shadowChanged = after.boxShadow !== beforeShadow && after.boxShadow !== 'none';
    if (!outlineChanged && !shadowChanged) {
      out.push({
        tag: el.tagName.toLowerCase(),
        cls: (el.className.baseVal ?? el.className).toString().slice(0, 80),
        text: (el.textContent || el.value || el.getAttribute('aria-label') || '').trim().slice(0, 60),
      });
    }
    el.blur();
  }
  return { type: 'no-focus-ring', count: out.length, sampled: els.length, offenders: out };
})()
```

## P7 — Color contrast spot-check

For every text node larger than 12px, compute foreground vs background color contrast and flag anything below WCAG AA (4.5:1 for normal text, 3:1 for ≥18pt or bold ≥14pt). Background detection is naive (walks ancestors until a non-transparent color is found) — false positives expected on text over images or gradients.

```javascript
(() => {
  const parseRgb = (s) => {
    const m = s.match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const p = m[1].split(',').map(x => parseFloat(x.trim()));
    return { r: p[0], g: p[1], b: p[2], a: p[3] ?? 1 };
  };
  const lum = ({ r, g, b }) => {
    const c = [r, g, b].map(v => {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  };
  const ratio = (a, b) => {
    const la = lum(a), lb = lum(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  };
  const bgOf = (el) => {
    let cur = el;
    while (cur && cur !== document.documentElement) {
      const c = parseRgb(getComputedStyle(cur).backgroundColor);
      if (c && c.a > 0) return c;
      cur = cur.parentElement;
    }
    return { r: 255, g: 255, b: 255, a: 1 };
  };
  const out = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let n;
  while ((n = walker.nextNode())) {
    const txt = n.nodeValue?.trim();
    if (!txt || txt.length < 3) continue;
    const el = n.parentElement;
    if (!el) continue;
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) continue;
    const cs = getComputedStyle(el);
    const fg = parseRgb(cs.color);
    if (!fg) continue;
    const bg = bgOf(el);
    const cr = ratio(fg, bg);
    const px = parseFloat(cs.fontSize);
    const bold = parseInt(cs.fontWeight, 10) >= 700;
    const large = px >= 24 || (bold && px >= 18.66);
    const min = large ? 3 : 4.5;
    if (cr < min) {
      out.push({
        text: txt.slice(0, 60),
        ratio: Math.round(cr * 10) / 10,
        min,
        size_px: Math.round(px),
        bold,
      });
    }
  }
  const seen = new Set();
  const dedup = out.filter(x => {
    const k = x.text + '|' + x.ratio;
    if (seen.has(k)) return false;
    seen.add(k);
    return true;
  });
  return { type: 'low-contrast', count: dedup.length, offenders: dedup.slice(0, 20) };
})()
```

## P8 — Layout shift sample

Watch CLS for 2 seconds after page settle. Anything > 0.1 is a poor Core Web Vitals score; > 0.25 is "Poor."

```javascript
(async () => {
  let cls = 0;
  const entries = [];
  const obs = new PerformanceObserver(list => {
    for (const e of list.getEntries()) {
      if (!e.hadRecentInput) {
        cls += e.value;
        entries.push({ value: e.value, sources: (e.sources || []).map(s => s.node?.tagName?.toLowerCase()).filter(Boolean) });
      }
    }
  });
  obs.observe({ type: 'layout-shift', buffered: true });
  await new Promise(r => setTimeout(r, 2000));
  obs.disconnect();
  return { type: 'cls', total: Math.round(cls * 1000) / 1000, threshold_poor: 0.1, count: entries.length, entries: entries.slice(0, 10) };
})()
```

---

## State-explosion checklist

For every page that the diff plausibly touched, exercise each state and capture an annotated screenshot. The screenshot path is the only required output; findings come from probes + Claude's read of the screenshot.

| State | How to reach it | What to verify |
|---|---|---|
| **Empty** | Filter to nothing (`?search=zzzzzz`) or use a freshly-seeded second team with no records | Empty-state copy renders; CTA visible; no broken layout from missing thumbnails |
| **One row** | Single record (Filament filter to one ID) | Single-item layout doesn't break (e.g. table headers still aligned) |
| **Many rows** | 100+ records (use existing seed or `>>` a factory) | Pagination renders; row height consistent; no horizontal scroll on default viewport |
| **Loading** | Throttle network in `agent-browser` (`browser_network_request` or DevTools), reload, screenshot mid-load | Skeleton/spinner present; not a blank flash |
| **Error** | Visit `/<resource>/0` or trigger validation error | Error copy is human, not stack trace; recovery path obvious |
| **Disabled** | Form with required field empty → submit button disabled | Disabled state visually distinct (not just same color with `cursor: not-allowed`) |
| **Hover** | `agent-browser hover @e1` on every interactive element in a row | Hover state visible (color change, underline, etc.) |
| **Focus** | Tab through page; screenshot at each stop | Focus ring visible (run P6 to flag missing rings) |
| **Dark mode** | Toggle theme (if app supports it; check `<html class="dark">` toggle) | All custom-token colors flip correctly; no hardcoded white-on-white |

A page that doesn't have a state listed (e.g., a static settings page has no "many rows" state) skips that row — record `N/A` in the matrix.

## Viewport sweep

Each probe runs at all five viewports unless the diff is desktop-only:

| Viewport | When to skip |
|---|---|
| 320×568 (small phone) | Skip if diff is admin-only / `/sysadmin/*` |
| 375×812 (standard phone) | Never skip — covers iPhone SE through 14 |
| 768×1024 (tablet portrait) | Skip if Filament panel doesn't ship a tablet layout |
| 1024×768 (tablet landscape / small laptop) | Never skip |
| 1440×900 (laptop) | Default — assume this is what was developed against |

Re-walk the changed pages at each viewport. Probe results from one viewport that don't reproduce at others are kept (it's a real bug at that viewport), but record the viewport in the offender's record.

## Output format

The skill aggregates probe results into one block per page:

```
### Page: /app/<team>/companies (1440×900)
- P1 horizontal-overflow: 0
- P2 text-clipping: 2 (`.fi-ta-cell-name` clips "Acme Corporation Holdings International" — see screenshot)
- P3 tap-target-undersize: 0
- P4 stuck-loading: 0
- P5 image-hygiene: 1 (avatar.jpg missing alt)
- P6 no-focus-ring: 0 sampled-30
- P7 low-contrast: 3 (placeholder text in search input ratio 3.1 < 4.5)
- P8 cls: 0.04
- Screenshot: .context/testing/reports/<run>/companies-1440.png
- State sweep: empty=OK, one=OK, many=OK, loading=OK, error=OK, disabled=N/A, hover=OK, focus=see-P6, dark=OK
```

Findings ≥ 1 in P1, P2, P3, P5 (with `issue: broken-src`), or P4 are **High** by default (broken UX). P6, P7, P8 findings are **Medium** unless the change in this diff is the cause (then High). Subjective screenshot-driven findings (Image oracle from `oracles.md`) keep their normal severity.
