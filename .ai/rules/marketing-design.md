---
paths:
  - 'packages/Cloud/resources/views/marketing/**'
  - 'packages/Cloud/resources/views/components/marketing/**'
  - 'packages/Cloud/resources/css/cloud.css'
  - 'packages/Cloud/resources/js/motion.js'
---

# Marketing design system

The marketing site is a framed grid: one `max-w-6xl` frame with continuous hairline rails, flat
cells inside it, depth from a surface ladder instead of shadows, and real product UI at 1:1 scale.
The homepage and `/email-and-calendar` are built on it. No test reads for these rules, so a
reviewer reads for a hand-rolled section, a raw gray pair, or a heading class outside the roles.

## Tokens (`packages/Cloud/resources/css/cloud.css`)

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `rail` | gray-200 | white 10% | `bg-rail gap-px` grids, `border-rail` frame lines |
| `surface` | white | gray-950 | every cell |
| `tint` | gray-50 | gray-900 | cells that hold product UI, link hover |

Cells inside a `gap-px` grid stay opaque. A translucent fill lets the rail color show through and
turns the cell gray in dark mode. `canvas` is taken by the Scribe theme, which is why the third
token is `tint`.

## Type roles

| Role | Utility | Where |
| --- | --- | --- |
| Display | `type-display` | the page's `h1` |
| Title | `type-title` | section headings, through `frame-header` |
| Lead | `type-lead` | the sentence that opens a showcase or a cell |
| Label | `type-label` | mono uppercase labels, through `eyebrow` |

A heading is two-tone: the claim in `gray-950`, the continuation in `gray-500`. `gray-400` on white
is about 2.5:1 and fails WCAG 1.4.3 even for large text; keep the continuation at `gray-500` or darker.

## Components (`components/marketing`)

- `hero`: the page's first section. The `h1` on the left, the subline and CTAs on the right, then
  the page's visual below in a `tint` cell. It opens the rails that run to the footer.
- `frame`: a section with rails. Everything below the hero sits in one.
- `frame-header`: label plus two-tone title, left-aligned. Copy stays under two lines at 1440px.
- `showcase`: a text cell (label, lead, one link) beside a `tint` cell holding product UI.
- `window`: the frame around a product fragment.
- `theme-image`: a light and a dark WebP of a real app screenshot. It loads only the current
  theme's file and swaps on theme change.
- `actor`: the You, Rela, Claude and Mailbox chips. They are the site's signature: every place that
  shows who did something uses them, and nothing else uses those colors.
- `record-emails-mock`: the Emails tab of a record, mirroring the shipped UI.

## Header and footer

A page passes `framed` to `x-cloud::guest-layout`, and the header and footer then sit on the same
`max-w-6xl` rails with a `md:px-10` inset, so the logo, the `h1` and every section edge line up.
Pages not yet rebuilt on this system omit the flag and keep the old layout. Flip the default once
every page is framed.

## Motion (`packages/Cloud/resources/js/motion.js`)

- Animate opacity, transform and clip-path only. Never a heading or body text.
- Each piece plays once, when it scrolls into view. Durations are 0.2s, 0.3s and 0.45s, with the
  ease `[0.22, 1, 0.36, 1]`. A sequence ends inside 5 seconds, or it needs a pause control (WCAG 2.2.2).
- `prefers-reduced-motion: reduce` skips the whole layer, and every element renders in its final state.
- Never animate an element out of `display: none`. Stack the two states in one grid cell and toggle
  `invisible` (WebKit skips or flashes the first frame otherwise).

## Copy

Every claim names something the product does today; check it against the help docs before writing
it. Section headings carry the argument, because visitors scan headings.
