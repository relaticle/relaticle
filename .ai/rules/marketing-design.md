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
The homepage, `/ai`, `/email-and-calendar`, `/pricing`, `/self-hosted`, `/ai-native-crm` and the two
`/crm-for-{assistant}` pages are built on it. No test reads
for these rules, so a reviewer reads for a hand-rolled section, a raw gray pair, or a heading class
outside the roles.

## Tokens (`packages/Cloud/resources/css/cloud.css`)

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `rail` | gray-200 | white 10% | `bg-rail gap-px` grids, `border-rail` frame lines |
| `surface` | white | gray-950 | every cell |
| `tint` | gray-50 | gray-900 | cells that hold product UI, link hover |

Four treatments sit on top of the tokens. A `spotlight dark` frame stays dark in both themes and
lifts to `gray-900` on a dark page. A page has at most one dark moment: the `closing-band` on the
homepage, `/ai` and `/email-and-calendar`, the Control section on `/ai`. `bg-hero-panel` is the
gradient in the logo's colours (`#4038CD` to `#5D54E8`) under a hero's product window; it never
carries copy. `bg-cta-band` is the closing band's surface. `bg-warm` is a static radial wash for the
human moments (the homepage email visual), never behind body text.

`bg-dots` is a 20px dot texture for the `tint` cells that hold product UI, so a mock has something to
sit on. It never sits behind marketing copy. On `/ai` the showcase visuals and the Control cells use it.

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
  then the page's visual below on a `bg-hero-panel` cell. `align="center"` stacks the same parts
  centred, for a page whose visual is a grid rather than one window: `/pricing` uses it with the
  price in the headline and the billing toggle under the line. The homepage hero is centered and built from
  the same parts: the open-source pill, a two-tone `type-display` headline, one subline with Claude
  and ChatGPT in plain text and Rela as the only chip, two buttons, the trial line, then the demo on
  a rounded `bg-hero-panel` that sits outside the rails, with joined tabs on a `mac-window`. The copy
  gaps are 32, 20, 28 and 16px, so the product window starts near 660px at 1440x900. The demo plays
  once per visit and again on each return to its tab. Nothing in the hero loops: a looping badge was
  removed for WCAG 2.2.2.
- `mac-window`: the browser chrome around a full app screen (traffic lights, a gray frame, a white
  inner panel). The homepage demo and the `/ai` playground use it. An optional `address` slot puts a
  URL in the title bar, which `/self-hosted` binds to the address the visitor types. A record fragment
  such as `record-emails-mock` keeps the plain `window`.
- `closing-band`: the page's ending and its one dark moment on the homepage, `/ai` and
  `/email-and-calendar`: a 48px spacer row, then a dark band the page above hangs into as a tab, a
  two-tone `type-title`, an optional `lead`, buttons in the slot (Start for free and Get in touch by
  default) and the trial note. Never two dark moments on one page.
- `frame`: a section with rails. Everything below the hero sits in one.
- `frame-header`: label plus two-tone title, left-aligned. Copy stays under two lines at 1440px.
- `showcase`: a text cell (label, lead, one link) beside a `tint` cell holding product UI, with an
  optional `figure` caption at the cell's top left, unused on the homepage. `facts` takes up
  to three short lines, rendered as hairline rows under the lead; each names something the help docs
  describe. A prop is never named `loop`: Blade's own `$loop` overwrites it after the
  facts `@foreach`.
- `board-mock`: the opportunities board built from the live board's markup, with a card that
  `motion.js` drags to the next stage. The hero tab and the press page keep the screenshot.
- `window`: the frame around a product fragment.
- `chat-window`: a `window` with the Rela chip and the sample workspace name in its header. Every chat
  fragment in the `/ai` showcases sits in one, so they read as the same product.
- The `/self-hosted` hero is that page's signature: an install configurator
  (`partials/self-hosted-install.blade.php`). A light terminal `window` holds the guide's quick start
  with an `APP_KEY` and `DB_PASSWORD` the browser generates through `crypto.getRandomValues`, and an
  address field that rewrites `APP_URL` and the URL in the `mac-window` beside it, which shows the
  board from a real screenshot. The server renders the `$(openssl ...)` form, so the markup stays
  runnable shell without JavaScript, and Copy puts commands only on the clipboard. The terminal is
  never dark in light mode, so the band stays the page's one dark moment.
  `tests/Browser/Marketing/SelfHostedInstallTest.php` pins the secrets, the address and the clipboard.
  Below it, the six containers come from `compose.yml`, and every number in the page comes from the
  self-hosting guide.
- The `/ai-native-crm` hero is that page's signature: a self-scoring checklist
  (`partials/ai-native-checklist.blade.php`). Five checks, a toggle per check for the visitor's own CRM
  with a live "x of 5" readout, and Relaticle's answer beside each with a link to the page that proves
  it. `tests/Browser/Marketing/AiNativeChecklistTest.php` pins the score and the toggles.
- The `/crm-for-{assistant}` hero is an ask gallery (`partials/assistant-playground.blade.php`): four
  asks as tabs, each showing the assistant's reply with the Relaticle tool it called, and for a write
  the record it produced with the assistant's actor chip. The client is fixed to the page's assistant,
  and the ChatGPT page prefixes every ask with `@Relaticle`. It carries no copy button, because the
  ChatGPT page must render no clipboard call. `tests/Browser/Marketing/AssistantPlaygroundTest.php`
  pins the tabs and the prefix.
- The `/ai` hero is that page's signature: a list page with Rela's side panel docked beside it, as the
  app renders it. An approved proposal changes the row in the list, because `ListOpportunities`,
  `ListPeople` and `ManageTasks` re-render on `ai-write-completed`. A record page does not re-render, so
  the hero never shows one changing live. `tests/Browser/Marketing/AiPlaygroundTest.php` pins the rows.
- `theme-image`: a light and a dark WebP of a real app screenshot. It loads only the current
  theme's file and swaps on theme change. It lazy-loads unless `loading="eager"` is passed, which a
  hero image needs, because the LCP image is never lazy-loaded.
- `actor`: the You, Rela, Claude, ChatGPT and Mailbox chips, in `sm` and `lg`. They are the site's
  signature: every place that shows who did something uses them, and nothing else uses those colors.
  ChatGPT stays neutral gray, as the works-with strip draws it, and differs from You by its icon. A
  chip marks an actor in a story, never a tool in a sentence: the hero names Claude and ChatGPT in
  plain text and keeps Rela as its one chip.
- The four-hands story (`home/partials/story.blade.php`, `story-record.blade.php`): the Northwind
  record in the middle, the four actors around it on dashed wires. `motion.js` `playStory()` reveals
  each actor, sends a pulse along its wire and writes its fields, with the stage moving from Proposal
  to Negotiation to Won, inside 5 seconds. On phones the wires hide and the record shows its end state
  above the cards.
- `record-emails-mock`: a record's Emails and Meetings tabs, mirroring the shipped UI. The two tabs are
  real tabs a visitor can switch; meeting dates are computed from today, so the mock never goes stale.
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
`max-w-6xl` rails, so the logo, the `h1` and every section edge line up.

The framed header is a floating card: 64px tall (the height of the app's top bar) with a 12px gap
above it at `md`, a 1px `gray-200` ring and one soft shadow (`shadow-navbar`). A 92px band on the
rails sits under it with a `cross` mark at each bottom corner from `xl`. `--header-height` is the
card's bottom edge (4.75rem at `md`), and `scroll-padding-top` follows it. On phones the menu opens
as a card under the header instead of a full screen.

The framed footer is quiet: the logo and blurb with four small social icons, four link columns,
then the copyright row with the theme switcher. No cells, no wordmark.

Pages not yet rebuilt on this system omit the flag and keep the old layout. Flip the default once
every page is framed.

## Motion (`packages/Cloud/resources/js/motion.js`)

- Animate opacity, transform and clip-path only. Never a heading or body text.
- A reveal plays once, when it scrolls into view. Durations are 0.2s, 0.3s and 0.45s, with the
  ease `[0.22, 1, 0.36, 1]`. A sequence ends inside 5 seconds, or it needs a pause control (WCAG 2.2.2).
- A showcase demo plays once through `playOnEntry()`: it starts when the cell scrolls into view,
  holds its end state, resets when the cell leaves the viewport, and plays again on the next entry.
  It never repeats while the cell stays in view. The root carries `data-demo-state` (`idle`,
  `playing`, `done`), and `tests/Browser/Marketing/ShowcaseDemoTest.php` pins the states and each
  demo's end state in Chromium and WebKit. A demo observes its cell, not its root, when the root is
  wider than the cell.
- No demo draws a pointer cursor and none carries a pause, skip or replay control. That is a product
  decision of 2026-11-03, and each demo ends inside 5 seconds, so WCAG 2.2.2 asks for no control. The
  only stop is `prefers-reduced-motion`. A control comes back only with that decision reversed.
- A sequence awaits each `animate()` through the `step` it receives. A raw `await animate()` outlives
  a leave and commits its mid-flight value over the reset.
- `prefers-reduced-motion: reduce` skips the whole layer, demos included, and every element renders in
  its final state. The browser plugin cannot emulate it, so that pass is manual:
  `agent-browser set media light reduced-motion`.
- Never animate an element out of `display: none`. Stack the two states in one grid cell and toggle
  `invisible` (WebKit skips or flashes the first frame otherwise).

## Copy

Every claim names something the product does today; check it against the help docs before writing
it. Section headings carry the argument, because visitors scan headings.
