# Relaticle Brand Assets

## Asset Map

- `public/brand/logomark.svg`: Symbol-only mark.
- `public/brand/wordmark.svg`: Lowercase `relaticle` vector wordmark.
- `public/brand/logo-lockup.svg`: Horizontal lockup (logomark + wordmark).

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
