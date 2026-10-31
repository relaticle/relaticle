# UI

## Verifying visual work

- Verify every visual change with an agent-browser screenshot (light + dark, and
  mobile viewport where relevant) before reporting it done. Never make the user
  act as the renderer.
- Any change to a Blade view, Livewire component, or Filament page must be
  clicked through with agent-browser (including empty-state data) before being
  reported done. Tests passing is not sufficient for UI work.
- agent-browser and the Browser suite both drive Chromium, and CI installs
  Chromium only (`.github/workflows/ci.yml`). A defect that only WebKit shows
  passes every gate. After the Chromium pass, run the browser tests for the
  surfaces you changed in WebKit:
  `php artisan test --compact <files> --browser safari`. Install the engine once
  per machine with `pnpm exec playwright install webkit`.
- An SVG that reaches the page as a string (`svg()->toHtml()` inside `@js`, a
  JavaScript template) carries its own size class:
  `svg('ri-claude-fill', 'size-full')`. With only a `viewBox`, Chromium stretches
  it to its flex slot and WebKit collapses it to 0x0.
  `tests/Browser/Chat/ModelPickerTest.php` fails under `--browser safari` when a
  model picker icon loses its size.
- A visual bug the reporter sees and agent-browser does not show is an engine
  difference until proven otherwise. Reproduce it in WebKit before changing
  code, then re-run that repro after the fix.

## Marketing & demo surfaces

- Mockups of the product (hero tabs, demos) mirror the real app UI 1:1.
  Screenshot the actual app first and match sidebar, spacing, and component
  placement. External sites (e.g. attio.com) are inspiration for concept only,
  never for visual specifics.
- Use design tokens from `resources/css/theme.css`; don't introduce ad-hoc pixel
  values or colors without a semantic token.
- Demo/example content (names, companies, conversations) must read like real CRM
  data for the buyer persona. No placeholder-looking values.

## Stylesheets

- Each Tailwind entry lists its sources with `@source`, and `app.css` turns automatic detection off with
  `source(none)`. Detection reads `.gitignore`, which the Docker build never sees, so the image once
  scanned all of `vendor/` and shipped different CSS from production.
- A class written in a directory no entry lists never reaches the CSS. Add the directory to the entry
  whose pages render it.
- The docs, help, marketing and blog pages load `packages/Cloud/resources/css/cloud.css`, which imports core `app.css` and
  lists Cloud's own `@source` directories. `documentation.css` only `@reference`s `cloud.css`, because the docs shell loads
  both. It emits no utilities.
- The Dockerfile frontend stage copies or mounts every `@source` path. `tests/Arch/ConventionsTest.php`
  fails a path that does not exist or that the stage cannot see.
- Cloud's own stylesheets are exempt from that stage check, because the image never builds them. No core stylesheet may
  name `packages/Cloud`, and `tests/Arch/ConventionsTest.php` fails one that does.
- With Cloud present, the app and sysadmin panels load `packages/Cloud/resources/css/filament/{app,admin}/theme.css` in place
  of the core themes. Each imports its core theme and adds Cloud's `@source` directories. A class added to a Cloud billing view
  or sysadmin page reaches CSS only through them, and `packages/Cloud/tests/Feature/AppPanelThemeTest.php` pins the swap.

## Icons (Remix Icon)

- **Brand/social icons** (GitHub, Discord, Twitter, LinkedIn) → always `fill` variant
- **UI/functional icons** (arrows, chevrons, checks, close) → always `line` variant
- **Feature/section icons** → `line` variant, stay consistent within a section
- **Status/emphasis icons** (success checkmarks, alerts) → `fill` variant
