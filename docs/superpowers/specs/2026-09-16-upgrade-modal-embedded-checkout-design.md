# In-app upgrade modal with embedded Stripe Checkout

Date: 2026-09-16
Status: approved, not implemented

## Problem

Upgrading to Pro sends the workspace owner away from the app. The sidebar row links to
the Billing page, the upgrade card posts to `CreateProCheckout`, and Cashier redirects
the browser to a Stripe-hosted page. The owner leaves the product to pay for it.

Card entry should happen in a modal over the app, with no navigation.

## Decision log

Three decisions moved during design. Each is recorded with the evidence that moved it,
because the reasoning is not recoverable from the final code.

### 1. Pricing model stays flat

The reference mockup prices per seat and sells a credit add-on. Relaticle prices per
workspace and publishes "Per workspace. Never per seat." in `lang/en/billing.php` and
`docs/billing.md`. The modal copies the mockup's structure, not its pricing. No seat
counter, no add-on row. Credit packs stay on the Billing page.

### 2. The trial is carried, not forfeited

Today an upgrade mid-trial charges immediately and the remaining trial days are lost.
The modal passes `trialUntil($workspace->trial_ends_at)` so the card is saved now and
the first charge lands when the trial ends. This matches the "Keep Pro" wording on the
sidebar row that opens the modal.

### 3. Embedded Checkout, not a custom Payment Element

The first two designs used a custom Payment Element. Both were wrong, for the same
reason, found late.

`docs/billing.md` records Stripe Managed Payments as the merchant-of-record posture.
Stripe handles global VAT registration, filing and remittance. `config/services.php`
defaults `managed_payments` to true and production has it on.

`managed_payments` is a create parameter only on `Checkout\Session` and `PaymentLink`.
On `Subscription`, `PaymentIntent` and `SetupIntent` it is a read-only property
inherited from the session. Verified in stripe-php 21.3.2:

    grep -c managed_payments Service/SubscriptionService.php  -> 0
    grep -c managed_payments Service/SetupIntentService.php   -> 0
    grep -c managed_payments Service/PaymentIntentService.php -> 0

So `newSubscription()->create($paymentMethod)` cannot enable Managed Payments. A custom
Payment Element would make Relaticle merchant of record for every new Pro subscription
and move global VAT operations in-house. That is a tax decision, not an engineering one.

The fallback design used a Checkout Session with `ui_mode: elements`, which keeps the
session and therefore should keep Managed Payments. Stripe rejects the combination:

    Invalid ui_mode: elements. Managed Payments currently only supports
    ui_mode: hosted_page and ui_mode: embedded_page.

`hosted_page` is the redirect we are removing. `embedded_page` is the only remaining
option, and it works. Probed against the test API:

    ui_mode: embedded_page
      + managed_payments: enabled
      + redirect_on_completion: if_required
      + allow_promotion_codes: true
      + branding_settings: {background_color, button_color, border_style}
      + subscription_data.trial_end
    -> OK, client_secret returned on every combination

`redirect_on_completion: if_required` is the load-bearing part. A card payment needs no
redirect, so the modal completes in place with no navigation, which was the original ask.

`never` was the first choice and is wrong. Stripe's embedded checkout reference defines
`onComplete` as firing for `if_required`, and `never` additionally disables every
redirect-based payment method. `if_required` keeps those methods available and falls back
to `return_url` only when one is actually used.

The cost is layout. Stripe renders the order summary and the card fields inside its
frame. The modal chrome, the plan header and the billing-period toggle stay ours.

## Design

### Entry points

One Livewire component, `App\Livewire\App\Billing\UpgradeModal`, mounted once through
the panel's `BODY_END` render hook in `AppPanelProvider`. Both entry points dispatch
`open-modal` to it:

- the sidebar footer row (`resources/views/filament/app/sidebar-footer.blade.php`)
- the Billing page upgrade card (`resources/views/filament/pages/billing.blade.php`)

`BODY_END` rather than the sidebar footer, because `SidebarBillingState::for()` returns
null for a grandfathered free workspace while the Billing page still offers it an
upgrade. Mounting inside the footer would leave that workspace with a button and no
modal. `BODY_END` also avoids teleporting a Filament modal out of the collapsible
sidebar.

Non-owners keep linking to the Billing page, which already tells them to ask the owner.
Only the owner ever opens the modal.

### Server

`CreateProCheckout` is modified, not replaced. It returns a client secret instead of a
redirect URL:

```php
$builder = $workspace
    ->newSubscription('default', $this->priceId($interval))
    ->allowPromotionCodes();

$builder = $workspace->onGenericTrial()
    ? $builder->trialUntil($workspace->trial_ends_at)
    : $builder->skipTrial();

return (string) $builder
    ->checkout($this->sessionOptions($workspace, $theme))
    ->asStripeCheckoutSession()
    ->client_secret;
```

The branch is not cosmetic. `trial_ends_at` is nullable, so a workspace that never
trialled would hand `trialUntil()` a null and raise a TypeError. A paused workspace is
worse: it keeps a `trial_ends_at` in the past, and `SubscriptionBuilder::checkout()`
clamps any trial it is given to `now + 48h + 10s`. Passing the stale date unconditionally
would grant a free two-day trial to exactly the workspaces that already used theirs.
`onGenericTrial()` is the only predicate that distinguishes the two.

Session options carry `ui_mode => 'embedded'`,
`redirect_on_completion => 'if_required'`, `client_reference_id`, `branding_settings`,
`managed_payments` when configured, and `return_url` set to
`<billing page>?checkout=success`.

The `return_url` is mandatory for two reasons. It is where a redirect-based payment
method lands, and `Checkout::create()` otherwise defaults it to `route('home')`, which
this application does not define. Omitting it throws `RouteNotFoundException` at session
creation. Cashier only discards `return_url` for `redirect_on_completion: 'never'`, so
under `if_required` the value we pass survives.

Cashier already handles this mode. `Checkout::create()` recognises embedded ui_modes,
suppresses `success_url` and `cancel_url` (Stripe forbids both here), and drops
`return_url` when `redirect_on_completion` is `never`. `StripeApiVersions` maps
`embedded` to `embedded_page` on the pinned `2026-08-26.dahlia` API version, so the
action passes `embedded` and lets Cashier transform it.

`trialUntil()` reaches `subscription_data.trial_end`. Cashier clamps it to at least 48
hours out, because a session lives 24 hours and Stripe requires a trial to outlast it.
A workspace with under two days left therefore gets a few extra hours of trial. That is
acceptable and deliberate.

### Client

Stripe.js loads from `https://js.stripe.com/dahlia/stripe.js`, matching the pinned API
version. No npm dependency is added; Stripe requires loading from their domain.

```js
const stripe = Stripe(publishableKey);
const checkout = await stripe.createEmbeddedCheckoutPage({
    fetchClientSecret: () => $wire.createSession(interval, theme),
    onComplete: () => $wire.completed(),
});
checkout.mount('#upgrade-checkout');
```

The panel runs `->spa()`, so `livewire:initialized` never fires on `wire:navigate`.
The script loads and mounts through Alpine `x-init` inside a `wire:ignore` container,
guarded against double-loading across navigations.

### Completion

A card payment requires no redirect, so `onComplete` fires, the modal switches to a
success state, and it polls until `$workspace->subscribed()` becomes true.

The local `subscriptions` row is written by the `customer.subscription.created` webhook,
not by session creation, so the modal can outrun it. The Billing page already solves
this with its `activating` panel: a spinner, then a "taking longer than expected" notice
after 60 seconds. That markup moves into a shared Blade component.

Both surfaces keep it. Under `if_required` a redirect-based payment method still leaves
the page and returns to `<billing page>?checkout=success`, so the page's `checkout` URL
property and its `activating` branch stay exactly as they are. The modal renders the
same component for the in-place path. The `credits` property is untouched; credit packs
keep hosted Checkout.

### Billing period toggle

A Checkout Session is priced at creation, so switching between annual and monthly
creates a new session and remounts the frame via `checkout.destroy()`. The toggle sits
in our chrome above the frame. One Stripe call per toggle, debounced.

### Theme

Embedded Checkout takes no client-side appearance object. Colours come from
`branding_settings` on the session: background, button colour, border style, font.
The client passes the active Filament theme when requesting the session, and a theme
change remounts through the same path as the interval toggle.

## Behaviour change that falls out

Cashier's webhook nulls `workspaces.trial_ends_at` when a subscription is created. With
the trial carried, `onGenericTrial()` goes false while the Stripe subscription sits in
`trialing`. The Billing page would read "Active" and "Renews automatically" before any
money moved.

The page must consult `$subscription->onTrial()` and say "First charge on `<date>`".

`BillingStatus` is left alone. `Subscribed` is honest for a workspace with a card on
file, and the enum feeds SystemAdmin widgets that are excluded from PHPStan.

## Guards

Enforced inside the action, not the component:

- billing feature flag active
- user owns the workspace
- workspace is not already subscribed and is not Enterprise
- interval pinned to `monthly` or `yearly` before any config lookup
- 10 session creations per workspace per 10 minutes

Stripe hosts the card fields, so card-testing abuse stays on their surface with their
protections. The rate limit covers session-creation looping only.

## Files

New:

- `app/Livewire/App/Billing/UpgradeModal.php`
- `resources/views/livewire/app/billing/upgrade-modal.blade.php`
- `resources/views/components/billing/activating.blade.php`

Changed:

- `app/Actions/Billing/CreateProCheckout.php`
- `app/Filament/Pages/Billing.php`
- `app/Providers/Filament/AppPanelProvider.php`
- `resources/views/filament/pages/billing.blade.php`
- `resources/views/filament/app/sidebar-footer.blade.php`
- `lang/en/billing.php`
- `docs/billing.md`

## Testing

`BillingCheckoutRedirectTest` asserts a redirect that no longer happens. Its upgrade
cases are rewritten to assert a client secret and an opened modal. The canned HTTP
client in that file already answers `/checkout/sessions`; it gains a `client_secret`.

Feature coverage, through the Livewire component:

- a non-owner cannot create a session
- an already-subscribed or Enterprise workspace cannot
- an unknown interval is rejected before any config lookup
- a running trial produces `subscription_data.trial_end`
- a paused workspace produces no trial and charges immediately
- completion syncs the plan and the sidebar prompt disappears

Browser, with agent-browser: card `4242424242424242` for success,
`4000002500003155` for 3DS, `4000000000009995` for a decline. Light and dark
screenshots, plus mobile.

## Local prerequisites

Two gaps block a production-shaped local walk. Both are Stripe test-mode data, not code:

1. The test Pro product has `tax_code: NULL`. Managed Payments rejects the session with
   "the product tax code is missing". Local `STRIPE_MANAGED_PAYMENTS=false` hides this
   today. A tax code must be set on the test product.
2. The test yearly price is $290/year. The app displays $228. Test fixtures have drifted
   from production pricing.

## Settled open items

All three were probed against the test API on 2026-09-16, using a throwaway product
carrying tax code `txcd_10103000`, archived afterwards.

1. `branding_settings` alongside `managed_payments` on `embedded_page` is accepted, with
   dark background, button colour and border style. Dark mode is reachable inside the
   frame. `allow_promotion_codes` with `managed_payments` is accepted too.
2. `onComplete` is an option on `createEmbeddedCheckoutPage`, documented as firing for
   `redirect_on_completion: if_required`. This is what moved the design off `never`.
3. `subscription_data.trial_end` rides alongside all of the above.

The only remaining blocker is local data, not design: the real test-mode Pro product
still carries `tax_code: NULL`.

## Out of scope

- Per-seat billing.
- Credit packs in the modal. They keep hosted Checkout unchanged.
- The public pricing page's hardcoded $19 and $24. Stripe renders the authoritative
  figure inside the frame, so the modal cannot disagree with the charge, but the
  marketing page remains a separate source of truth.
