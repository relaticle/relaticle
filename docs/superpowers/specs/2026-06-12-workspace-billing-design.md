# Workspace Billing — Design Spec

**Date:** 2026-06-12 · **Issue:** [#95](https://github.com/relaticle/relaticle/issues/95) · **Foundation PR:** [#334](https://github.com/relaticle/relaticle/pull/334) (reworked by this spec)
**Scope:** self-serve paid plans for Relaticle Cloud — billing engine, `/app/billing` page, checkout, trial, pricing page, sysadmin visibility. Ships dark behind a feature flag.

## 1. Summary

Relaticle Cloud bills **per workspace, never per seat** — flat plan tiers gated by AI usage (credits, models, rate limits), keeping the published "No per-seat pricing. Ever." promise. Payments run on **Stripe (Laravel Cashier)** under a **Stripe Atlas Delaware LLC**, with **Stripe Managed Payments** (merchant of record, GA April 2026) enabled per checkout so Stripe registers, files, and remits global indirect tax. Because MP is transaction-level on our own Stripe account, turning it off later (own tax ops, lower fees) is a config flip — customers, payment methods, and subscriptions are ours from day one.

## 2. Locked decisions

| # | Decision | Choice |
|---|---|---|
| 1 | Spec scope | Phases 2+3 (billing page + checkout + pricing page = launchable) |
| 2 | Pro price | **$29/workspace/mo** |
| 3 | Annual | **$290/yr** ("2 months free") |
| 4–5 | Trial | **14-day Pro trial, no card, one per user**, full Pro allowance |
| 6 | Checkout | Hosted Stripe Checkout (no embed) |
| 7 | Roles | Owner-only actions; page visible to all members |
| 8 | Navigation | Tenant menu item; fix dead chat links |
| 9 | Pricing page | 3 cards (Free / Pro recommended / Self-Hosted) + Enterprise band |
| 10 | Credit transparency | Real numbers + credit definition published |
| 11 | Rollout | Pennant `Billing` flag, dark until Stripe org ready |
| 12 | Team deletion | Auto-cancel: `cancel()` at scheduling, `cancelNow()` at purge |
| 13 | Observability | Sentry + Horizon + Stripe dashboard; no new alerting |
| 14 | Cancel/payment UX | Stripe Billing Portal only |
| 15 | Sysadmin | Read-only Subscriptions resource (full visibility) |
| 16 | Provider | **Stripe Atlas LLC + Cashier + Managed Payments** (Polar dropped — see `.context/research/billing-provider-research.md` §11) |

Plans stay exactly `App\Enums\Plan`: Free (300 credits/mo, 10 rpm, fast models) · Pro (2,000 credits/mo, 30 rpm, all models) · Enterprise (10,000, 60 rpm, all models, **sales-led only** — manual invoicing + sysadmin grant, no self-serve checkout).

## 3. Architecture

```text
Billing page (Filament, tenant-scoped, flag-gated)
  ├─ Start trial ──────────► StartProTrial action (app-side, no Stripe objects)
  ├─ Upgrade ──────────────► Cashier hosted Checkout session
  │                            └ managed_payments[enabled] = config('services.stripe.managed_payments')
  └─ Manage subscription ──► Stripe Billing Portal

Stripe webhooks ─► Cashier /stripe/webhook (stock controller, maintains subscriptions tables)
                     └ WebhookHandled listener (customer.subscription.*)
                         └ SyncTeamPlanFromSubscription action
                             ├ price id → Plan   (config services.stripe.prices)
                             ├ valid sub → enforce plan; ended → Free (manual-grant guard)
                             └ plan change → CreditService::resetPeriod()
```

- `laravel/cashier` replaces `danestves/laravel-polar`; PR #334 is reworked in place (package, migrations, env, tests). Billable model = **Team** (`Cashier::useCustomerModel(Team::class)`).
- `services.stripe.managed_payments` (bool, per env) is the MoR switch and future off-ramp. **Implementation task #1 is a sandbox spike**: Cashier checkout session-options passthrough of `managed_payments[enabled]`. Fallback if passthrough fails: create the Checkout Session via `stripe-php` directly in our action — Cashier still syncs the resulting subscription via webhooks. Spike outcome changes one class.
- Pennant feature `App\Features\Billing` (`RELATICLE_FEATURE_BILLING`, default off in prod) gates: billing page + tenant menu item, chat upgrade links (hidden when off), pricing-page Pro card, trial CTAs.
- Stripe products carry **AIaaS business-use tax codes** (`txcd_10105002`); MP ToS accepted in dashboard before activation.

## 4. Data model

- Cashier published migrations, adapted (same treatment as the Polar foundation): customer columns (`stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`) on **`teams`**; `subscriptions` + `subscription_items` with **ULID owner keys**; `up()` only.
- `users.pro_trial_used_at` (nullable timestamp) — one-trial-per-user enforcement.
- Price→plan map in `config/services.php`: `services.stripe.prices = ['pro_monthly' => env('STRIPE_PRICE_PRO_MONTHLY'), 'pro_yearly' => env('STRIPE_PRICE_PRO_YEARLY')]` — both resolve to `Plan::Pro`. Enterprise intentionally has no price ids.
- Drop the four `polar_*`/`webhook_calls` migrations from the unmerged branch (never deployed).
- Env: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_PRO_MONTHLY`, `STRIPE_PRICE_PRO_YEARLY`, `STRIPE_MANAGED_PAYMENTS=true`, `RELATICLE_FEATURE_BILLING` (replace `POLAR_*` in `.env.example`).

## 5. Plan sync (behavior preserved from PR #334)

`SyncTeamPlanFromSubscription` keyed off Cashier's `WebhookHandled` for `customer.subscription.created|updated|deleted`:

- Subscription **valid** (active/trialing/past_due/grace) → `team.plan` = mapped plan.
- Subscription **ended** → downgrade to Free **only if** the team's current plan equals the plan this subscription granted — sysadmin grants survive unrelated lapses.
- Unmapped price id → log warning, change nothing.
- Plan **transition** (and only a transition — replays are no-ops, usage is never wiped) → `CreditService::resetPeriod()` grants the new allowance immediately.
- Monthly renewal resets stay with the existing `chat:reset-credits` schedule (00:05 daily). Past-due keeps the plan until Stripe gives up (dunning configured in Stripe dashboard defaults).
- One structured log line per transition: team id, from→to, subscription id.

## 6. Trial

- **Start** (`StartProTrial` action): guards — actor is team owner, `pro_trial_used_at` null, team has never had a subscription, team on Free. Effects — `team.plan = Pro`, `teams.trial_ends_at = now()+14d`, stamp `users.pro_trial_used_at`, `resetPeriod()` (2,000 credits). No Stripe objects created (Cashier generic trial).
- **During**: days-remaining banner on the billing page; the chat side panel's existing plan line reads "Pro trial — N days left". `onGenericTrial()` drives state. "Subscribe now" → normal checkout; on `subscription.created/active`, clear `trial_ends_at`.
- **One daily command** (`billing:process-trials`, scheduled in `bootstrap/app.php`) does both trial jobs: sends the single "trial ends in 3 days" Mailable to owners of trials expiring in exactly 3 days, and downgrades expired trials — teams where `trial_ends_at` is past AND no valid subscription → `plan = Free`, `resetPeriod()` (300), clear `trial_ends_at`. Expiry is reflected in-app; no second email.

## 7. `/app/billing` page (Filament, tenant-scoped, slug `billing`)

State-driven single page; credits data read the same way the chat side panel reads it (`AiCreditBalance`).

| State | Shows | Actions (owner only) |
|---|---|---|
| Free, trial available | plan card, usage bar, Free-vs-Pro mini-compare | **Start 14-day Pro trial** (primary), Upgrade (secondary) |
| Free, trial used | same minus trial | Upgrade (monthly/yearly toggle) |
| Trialing | days-left banner, usage bar | Subscribe now |
| Pro active | renewal date + interval, usage bar | Manage subscription → Portal |
| Cancel scheduled | "Pro until {date}" | Resume via Portal |
| Past due | warning banner | Update payment method → Portal |
| Enterprise (manual) | plan card | "Managed by Relaticle — contact us" |
| Non-owner | identical info | "Ask {owner} to manage billing" |

Checkout return lands on `?checkout=success` → "Payment received — activating Pro…" with `wire:poll` until the webhook flips the plan. The two chat entry points (`chat-side-panel` link, `ChatController` `upgrade_url`) switch to the tenant route, rendered only when the flag is on.

## 8. Checkout & portal

- Checkout: Cashier `newSubscription('default', $priceId)` hosted session; `allow_promotion_codes` on; MP param per §3; success/cancel URLs → billing page. Guard: already-subscribed → redirect to Portal instead.
- Portal (Stripe dashboard config): cancel at period end, payment-method update, invoice history, price switch **between Pro monthly↔yearly only**. No pause.
- Customers also get Link's self-serve management (MP default) — receipts/statements show "Sold through Link" / `LINK.COM*` descriptor; support email kept current in Stripe settings (48h SLA or Stripe may auto-refund).

## 9. Team deletion

- Scheduling deletion → `$team->subscription()?->cancel()` (period end); confirmation modal states it.
- Purge (`app:purge-scheduled-deletions`) → `cancelNow()` if anything still active, before data removal.

## 10. Pricing page

Three cards: **Free** ($0 — 300 credits/mo, fast models, unlimited users & records) / **Pro, Recommended** ($29/mo · $290/yr toggle — 2,000 credits/mo, all models incl. premium, 30 req/min, email support; CTA "Try Pro free for 14 days — no card" → register) / **Self-Hosted** (unchanged). Below: slim Enterprise band ("Higher AI allowances, custom invoicing — talk to us") + credit definition line ("1 credit ≈ one AI chat message or record summary; allowances may evolve with notice"). H1 "No per-seat pricing. Ever." unchanged; meta description "No usage limits" → "Unlimited users and records". Flag off renders today's two-card page byte-identically.

## 11. Sysadmin

- New **read-only `SubscriptionResource`** (Cashier `subscriptions` table): team, price→plan, status badge, interval, current period end, origin column, status filters, deep links to the Stripe dashboard customer + subscription. No mutations. Origin derivation: valid subscription → `subscription`; else `trial_ends_at` in future → `trial`; else plan ≠ Free → `manual`; else `—`.
- `AiCreditBalanceResource` "Reset billing period" action: inline warning when the team has a valid subscription ("webhook sync will re-assert the subscribed plan — cancel in Stripe first").

## 12. Observability

Sentry (prod) + Horizon catch webhook/job failures; Stripe dashboard monitors delivery; structured transition logs per §5. No new alerting code. Future hardening (explicitly not built): nightly Stripe↔`team.plan` reconciliation command.

## 13. Testing

- **Webhook suite** (rework of the 8 Polar tests): through `POST /stripe/webhook` with computed `Stripe-Signature` — incomplete→no change, activation grants allowance, replay keeps usage, price-swap switches plan, deletion downgrade, manual-grant survives lapse, unmapped price inert, bad signature rejected.
- **Trial**: start guards (non-owner / second trial / already-subscribed / non-Free), expiry command, mid-trial conversion clears trial, T-3 email targeting.
- **Billing page**: render per state ×8 incl. non-owner gating; checkout + portal redirects (Stripe client faked at the action boundary).
- **Team deletion**: cancel-at-schedule, revoke-at-purge.
- **Pricing page & flags**: flag on/off for page, menu, chat links; credit numbers present.
- Gates: Pint, Rector, PHPStan, 100% type coverage, `mutates()` declarations, ULID-key migration test.

## 14. Rollout

Engineering never blocks on paperwork — Stripe **test mode works pre-activation**:

1. Rework PR #334 (this spec) → merge dark behind flag.
2. Human track (parallel): Atlas LLC ($500, ~2 days) → Stripe activation + **MP ToS** + banking (Stripe financial account or Mercury — verify Armenia not prohibited) → EIN (no-SSN: 15–25 business days official, 4–8 weeks real; payments work pre-EIN) → Armenian accountant consult (LLC repatriation/declarations) → production products + prices with AIaaS tax codes → webhook endpoint + secrets.
3. Staging E2E with test keys (checkout, trial, portal, cancel, deletion).
4. Flip `RELATICLE_FEATURE_BILLING` in prod.
5. Update public artifacts: `docs/billing.md` rewritten Stripe-shaped, issue #95 body, PR #334 description.
6. Compliance calendar: Delaware franchise tax (June 1), Form 5472 + pro-forma 1120 (≈April 15, filing service — $25k penalty if missed), registered agent renewal.

## 15. Out of scope

Credit top-ups / usage overage (phase 4 — Stripe Billing credit management fits later) · enterprise self-serve checkout · PO/wire automation (manual Stripe Invoicing per deal) · in-app cancel · embedded checkout · refund tooling · reconciliation command · custom multi-currency price points (MP Adaptive Pricing localizes display automatically) · any self-hosted/AGPL changes.

## 16. Open items (non-blocking, tracked in runbook)

1. Armenian accountant sign-off on the LLC structure (no-ECI 0% US income tax assumption + local declaration).
2. Banking path confirmed (Stripe financial account / Mercury Armenia check; keep a backup institution).
3. Sandbox spike result: Cashier session-options passthrough of `managed_payments[enabled]` (fallback path defined in §3).
