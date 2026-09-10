---
paths:
  - 'resources/views/filament/**'
  - 'app/Filament/**'
  - 'packages/*/src/Filament/**'
  - 'packages/*/resources/views/**'
---

# Linking out of a Filament panel

A panel link to a public marketing or docs route must be built against the
primary host, not with a bare `route()`:

```blade
<x-filament::button tag="a" :href="url()->getPublicUrl(route('contact', absolute: false))">
```

`route()` resolves against the *request* host. Public routes carry no domain
constraint, so from the app-panel host (`APP_PANEL_DOMAIN`) it yields
`https://app.<domain>/contact`. `AppPanelProvider` calls `->spa()`, Filament's
`is_app_url()` sees a matching host and adds `wire:navigate`, then
`RedirectToPrimaryHost` 301s the fetch to `APP_URL`. That crossing is
cross-origin, the marketing host sends no `Access-Control-Allow-Origin`, and
Livewire's `performFetch` swallows the CORS error in a bare `.catch(() => {})`.
The button goes dead with nothing in the console. This shipped in PR #654 and
reached production.

`getPublicUrl` puts the primary host in the href, `is_app_url()` returns false,
Filament omits `wire:navigate`, and the browser navigates natively.

Plain `<a>` tags and `target="_blank"` links are unaffected: they never get
`wire:navigate` and follow the 301 normally.

A test only catches this when the two hosts differ. Set
`config()->set('app.url', 'https://marketing.test')` before rendering and
assert the absolute href, as `tests/Feature/Billing/BillingPageTest.php` does.
