# Relaticle Brand Assets

## Asset Map

- `public/brand/logomark.svg`: Symbol-only mark.
- `public/brand/wordmark.svg`: Lowercase `relaticle` vector wordmark.
- `public/brand/kit/logos/`: Transparent full logos and symbols, in color and white, as SVG and PNG.
- `public/brand/kit/avatars/`: Purple, light, and dark avatars as SVG and 1024/2048-pixel PNGs.
- `public/brand/kit/platforms/`: Square PNG presets for social platforms, in all three colors.
- `public/brand/kit/watermarks/`: Small white and purple PNGs for YouTube.
- `public/brand/kit/manifest.json`: Generated download catalog, dimensions, and SHA-256 checksums.
- `public/brand/kit.zip`: Complete brand kit. Product screenshots remain separate downloads on `/press`.

## Regenerating Downloads

`logomark.svg` and `wordmark.svg` are the original artwork. The generator preserves their paths and applies uniform scaling.
It converts the inverse artwork to opaque white and supplies padding for circular avatar crops.

Install `rsvg-convert` and `zip` before regenerating. On macOS, `brew install librsvg` provides the renderer; `zip` ships with macOS.
On Debian or Ubuntu, install `librsvg2-bin` and `zip`.

Run `pnpm brand:build` after editing either original SVG or the export definitions in `bin/build-brand-assets.mjs`.
The command replaces only `public/brand/kit/` and `public/brand/kit.zip`. Existing email and legacy logo files remain unchanged.
Commit the generated files alongside their sources. Production serves them directly and needs no rendering tools.

Run `pnpm brand:check` to compare a temporary rebuild with every published file and the ZIP.
Use the same librsvg version when checking byte-for-byte output; a renderer upgrade can change PNG bytes.
Run `php artisan test --compact tests/Feature/Public/PressPageTest.php` to verify published links, source checksums, dimensions, and archive contents.

The press page reads its download catalog from the generated manifest.
Platform presets are convenient export sizes, not permanent platform requirements. Check the final crop when uploading.

## Blade Components

- `resources/views/components/brand/logomark.blade.php`:
  - Use as `<x-brand.logomark size="sm|md|lg" class="..." />`
  - Renders the symbol-only mark via the lockup source-of-truth component.

- `resources/views/components/brand/wordmark.blade.php`:
  - Use as `<x-brand.wordmark class="..." />`
  - Uses `currentColor` for light/dark adaptation.

- `resources/views/components/brand/logo-lockup.blade.php`:
  - Use as `<x-brand.logo-lockup ... />`
  - Props:
    - `size="sm|md|lg"`
    - `showWordmark="true|false"`

## Current Usage

- Marketing header, footer, and mobile menu use lockup component:
  - `resources/views/components/layout/header.blade.php`
  - `resources/views/components/layout/footer.blade.php`
  - `resources/views/components/layout/mobile-menu.blade.php`
- Filament app panel logo uses symbol-only component:
  - `resources/views/filament/app/logo.blade.php`
- Email HTML header uses static PNG for client compatibility:
  - `resources/views/vendor/mail/html/header.blade.php`

## Basic Rules

- Minimum size:
  - Logomark: do not render below `h-6`.
  - Wordmark: do not render below `h-4`.
- Prefer lockup for primary navigation.
- Use logomark-only component for tight app/panel/sidebar spaces.
- Keep wordmark monochrome (`currentColor`) in app UI for theme consistency.
- Treat `public/brand/*` as source assets for external/static consumers; use `x-brand.*` components in Blade UI.
