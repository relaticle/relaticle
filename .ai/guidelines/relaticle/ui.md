# UI

## Verifying visual work

- Verify every visual change with an agent-browser screenshot (light + dark, and
  mobile viewport where relevant) before reporting it done. Never make the user
  act as the renderer.
- Any change to a Blade view, Livewire component, or Filament page must be
  clicked through with agent-browser (including empty-state data) before being
  reported done. Tests passing is not sufficient for UI work.
- agent-browser and the Browser suite both drive Chromium. CI runs WebKit only
  for test files tagged `pest()->group('webkit')`, in the `Browser Tests (WebKit)`
  job of `.github/workflows/ci.yml`. A defect that only WebKit shows passes every
  other gate. After the Chromium pass, run the browser tests for the surfaces you
  changed in WebKit: `php artisan test --compact <files> --browser safari`.
  Install the engine once per machine with `pnpm exec playwright install webkit`.
- When WebKit breaks a surface Chromium passes, tag the test file that covers it
  with `pest()->group('webkit')`, so CI keeps checking it in WebKit.
  `tests/Browser/CRM/RichEditorBrowserTest.php` is the example: a rich editor
  selection froze Safari while every Chromium test passed.
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

## Scripts

- `vite.config.js` builds every script that imports a package. Never add a second bundler, a build script in `bin/`, or a
  committed `dist/` file: a committed bundle drifts from its source, and only an extra CI check catches it.
  `tests/Arch/ConventionsTest.php` fails a `dist/` directory under `resources/` and a `bin/` script that imports a bundler.
- A script with no imports that Filament loads through `FilamentAsset` stays unbuilt in `resources/js/filament/`, as
  `slash-menu.js` and `payload-guard.js` do. `php artisan filament:assets` publishes it to `public/js/app/`.
- A rich editor plugin that imports TipTap is a Vite input in `resources/js/filament/rich-content-plugins/`, and its
  `getTipTapJsExtensions()` returns `Vite::asset()` for it. Filament's docs build one with esbuild from `bin/`. Do not
  follow them here.
- The `filament-rich-editor-modules` plugin in `vite.config.js` resolves `@tiptap/core` and `@tiptap/pm/{state,model,view}`
  to `window.FilamentRichEditor.tiptap`, for those plugin files and the packages listed in `richEditorPackages`. The chat
  composer keeps its own TipTap. A second ProseMirror copy breaks the editor's `instanceof` checks.
- A TipTap package a plugin imports goes in `richEditorPackages`, which also keeps it out of Vite's dev pre-bundling. The
  build fails when a plugin imports an unlisted `@tiptap/` package or another ProseMirror module, when code outside the
  plugins imports a listed package, and when a plugin's chunk loses the default export Filament imports.

## Icons (Lucide)

- UI icons are Lucide through `mallardduck/blade-lucide-icons`: `lucide-<name>`, outline only. Take names from
  Lucide's current list. The package still ships pre-1.0 aliases with stale artwork, such as `check-circle` for
  `circle-check`.
- Every Lucide icon carries the `lucide` class from `config/blade-lucide-icons.php`, and `resources/css/theme.css`
  draws it at a 1.75 stroke. A raw `<svg>` that draws `IconPath` data sets `stroke-width="1.75"` itself.
- Filament's own icons map to Lucide in `App\Support\PanelIcons`, registered for every panel in
  `AppServiceProvider`. `tests/Feature/Filament/PanelIconsTest.php` fails when a panel draws Filament's default set.
- Brand logos (GitHub, Discord, Claude, OpenAI, Google) stay Remix Icon `fill`, because Lucide draws no brand logos.
- `App\Support\IconPath::for()` turns an icon into one `d` for chat chips and record glyphs. It throws on an SVG
  element it cannot convert.
