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

Two treatments sit on top of the tokens. A `spotlight dark` frame stays dark in both themes and
lifts to `gray-900` on a dark page; the page has one, the four-hands story. `bg-warm` is a static
radial wash for the human moments (the email visuals and the closing CTA), never behind body text.

Cells inside a `gap-px` grid stay opaque. A translucent fill lets the rail color show through and
turns the cell gray in dark mode. `canvas` is taken by the Scribe theme, which is why the third
token is `tint`.

## Type roles

| Role | Utility | Where |
| --- | --- | --- |
| Display | `type-display` | the page's `h1` |
| Title | `type-title` | section headings, through `frame-header` |
| Lead | `type-lead` | the sentence that opens a showcase or a cell |
| Label | `type-label` | mono uppercase labels at 11px, through `eyebrow` |
| Prose | `type-prose` | marketing sentences in a text cell: showcase facts, step and capability descriptions, FAQ answers |

`type-label` is 11px, Apple's HIG minimum; a label is never large text, so it needs 4.5:1.
`type-prose` is 16px at 1.6 with a `66ch` cap (USWDS 16px floor, Baymard's 50 to 75 characters,
WCAG 1.4.12). A `text-sm` sentence in a text cell is the drift a reviewer reads for. Small print
under a button stays `text-sm`.

A heading is two-tone: the claim in `gray-950`, the continuation in `text-gray-500 dark:text-gray-400`.
Keep the continuation to one short clause.

## Colour and contrast

Text meets 4.5:1, large text (24px, or 18.7px bold) 3:1, and control edges and states 3:1 (WCAG 1.4.3,
1.4.11). In light mode body text is `gray-700` or darker and secondary text `gray-500` or darker:
`gray-400` on white is about 2.5:1 and never carries marketing copy. In dark mode body text is
`gray-200` and secondary text `gray-400`: `gray-500` on `gray-950` is about 4.2:1 and fails under
24px. Icons may stay `gray-400`. Product mocks mirror the app at 1:1 and keep its grays, so this rule
does not reach them, but WCAG does. No test reads contrast, so a reviewer measures it in both themes.

## Header offset

`--header-height` in `cloud.css` is the fixed header's `h-16`. Pages that render `#main-header`
get `scroll-padding-top` of the header plus `1.5rem` on `html`, so an anchor jump or a focused element
never lands under the header (WCAG 2.4.11). Anchored sections carry no `scroll-mt` of their own, or the
two offsets add up. The docs shell has its own sticky header and its own margin, and the rule does not
reach it. Change the header height and the token together. `html` also carries `bg-surface` and
`color-scheme`, so overscroll and form controls follow the theme.

## Components (`components/marketing`)

- `hero`: the split hero for inner pages. The `h1` on the left, the subline and CTAs on the right,
  then the page's visual below in a `tint` cell. The homepage hero is centered but built from the same
  parts, inside the frame: a `type-label` pill, a two-tone `type-display` headline, the subline, two
  buttons, a row of tab cells on the rails, and the demo in a `tint` cell. The demo plays once; a Skip and Replay control sits in its browser chrome.
- `frame`: a section with rails. Everything below the hero sits in one.
- `frame-header`: label plus two-tone title, left-aligned. Copy stays under two lines at 1440px.
- `showcase`: a text cell (label, lead, one link) beside a `tint` cell holding product UI, with an
  optional `figure` caption at the cell's top left, unused on the homepage. `facts` takes up
  to three short lines, rendered as hairline rows under the lead; each names something the help docs
  describe. `looping` adds the Pause control for a visual that `motion.js` loops. The prop is not
  named `loop`, because Blade's own `$loop` overwrites it after the facts `@foreach`.
- `board-mock`: the opportunities board built from the live board's markup, with a card that
  `motion.js` drags to the next stage. The hero tab and the press page keep the screenshot.
- `window`: the frame around a product fragment.
- `theme-image`: a light and a dark WebP of a real app screenshot. It loads only the current
  theme's file and swaps on theme change.
- `actor`: the You, Rela, Claude and Mailbox chips. They are the site's signature: every place that
  shows who did something uses them, and nothing else uses those colors.
- `record-emails-mock`: the Emails tab of a record, mirroring the shipped UI.
- `quote`: a named customer quote in a `figure`, with name, role and company in the caption. The
  name links to a public source when one exists.
- `logo-wall`: customer logos on the rails. It renders nothing when it gets no logos.
- `copy-line`: one command or URL in mono with a Copy button. The value stays readable text and an
  optional plain link follows it, so the step works without JavaScript.
- `stat`: one number in `type-title` over a `type-label`, linked to the page that proves it.
- Customer proof: `packages/Cloud/config/marketing.php` holds it, read as `config('marketing.testimonials')`
  and `config('marketing.logos')`. A testimonial is `['quote' => string, 'name' => string, 'role' =>
  string, 'company' => string, 'url' => ?string, 'avatar' => ?string]`. A logo is `['name' => string,
  'src' => string, 'url' => ?string]`. `src` and `avatar` take a public URL or a path from the
  repository root such as `resources/images/customers/acme.svg`, which `Vite::asset()` resolves. Add
  an entry only with the customer's permission; both lists stay empty until then, and the
  customer-voices section renders nothing.
  A testimonial with an optional `'slot' => 'board'`, `'rela'` or `'agents'` also renders beside the
  matching homepage feature showcase, through the showcase's `quote` prop. A `src` or `avatar` that is not a URL must live under `resources/images/`, which `app.js` globs into the Vite manifest, and needs `pnpm run build` before it renders; any other path throws a manifest error and breaks the page. Public URLs need no build.

## Header and footer

A page passes `framed` to `x-cloud::guest-layout`, and the header and footer then sit on the same
`max-w-6xl` rails with a `md:px-10` inset, so the logo, the `h1` and every section edge line up.
Pages not yet rebuilt on this system omit the flag and keep the old layout. Flip the default once
every page is framed.

## Motion (`packages/Cloud/resources/js/motion.js`)

- Animate opacity, transform and clip-path only. Never a heading or body text.
- A reveal plays once, when it scrolls into view. Durations are 0.2s, 0.3s and 0.45s, with the
  ease `[0.22, 1, 0.36, 1]`. A sequence ends inside 5 seconds, or it needs a pause control (WCAG 2.2.2).
- A showcase demo loops through `loop()`: it starts when the cell is in view, holds 2.5s on the
  finished state, fades, resets and plays again, stops and resets when the cell leaves the viewport,
  and restarts on re-entry. The showcase's `looping` prop renders the Pause control in the `tint`
  cell. Pause jumps to the finished state and stays paused across leave and re-entry. The root carries
  `data-loop-state` (`idle`, `playing`, `paused`), and `tests/Browser/Marketing/ShowcaseLoopTest.php`
  pins the three states in Chromium and WebKit.
- A sequence awaits each `animate()` through the `step` it receives. A raw `await animate()` outlives
  a leave or a pause and commits its mid-flight value over the reset.
- `prefers-reduced-motion: reduce` skips the whole layer, loops included, every element renders in its
  final state, and the Pause controls stay hidden. The browser plugin cannot emulate it, so that pass
  is manual: `agent-browser set media light reduced-motion`.
- Never animate an element out of `display: none`. Stack the two states in one grid cell and toggle
  `invisible` (WebKit skips or flashes the first frame otherwise).

## Copy

Every claim names something the product does today; check it against the help docs before writing
it. Section headings carry the argument, because visitors scan headings.
