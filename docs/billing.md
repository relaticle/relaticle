# Billing

Relaticle Cloud bills **per workspace, never per seat**. This is a product commitment — the
pricing page says "No per-seat pricing. Ever." and the billing architecture enforces it:
nothing in the billing pipeline reads or multiplies by member count.

## Model

- Plans are flat, workspace-scoped tiers: `App\Enums\Plan` (`free`, `pro`, `enterprise`).
- A plan gates **AI usage** (monthly credit allowance, model access, request rate limits) —
  the value metric is the work AI does for a workspace, not how many people log in.
- Member counts are tracked for visibility and enterprise license compliance only.
- Enterprise is sales-assisted: manual invoicing plus a sysadmin plan grant. Self-serve
  checkout covers Free → Pro.

## Provider

Payments run through **Stripe** via [Laravel Cashier](https://laravel.com/docs/billing) under a
US entity (Stripe Atlas LLC), with **Stripe Managed Payments** (merchant of record, GA April
2026) enabled per checkout. Managed Payments handles global VAT/sales-tax registration, filing,
and remittance. `Team` is the Cashier billable model.

Because Managed Payments is enabled per transaction on our own Stripe account, the customers,
payment methods, and subscriptions are ours. Disabling it later (own tax ops, lower fees) is a
config flip — `services.stripe.managed_payments` — with no other code change.

```text
Billing page (/app/{team}/billing, flag-gated)
  ├─ Start trial ──────────► StartProTrial (app-side Cashier generic trial, no Stripe objects)
  ├─ Upgrade ──────────────► CreateProCheckout → hosted Stripe Checkout
  │                            └ managed_payments[enabled] = config('services.stripe.managed_payments')
  └─ Manage subscription ──► Stripe Billing Portal (redirectToBillingPortal)

Stripe webhooks ─► Cashier POST /stripe/webhook (stock controller, maintains subscriptions tables)
                     └ WebhookHandled listener (SyncPlanOnStripeSubscriptionChange)
                         └ SyncTeamPlanFromSubscription
                             ├ price id → Plan   (config services.stripe.prices)
                             ├ subscription valid → team.plan = mapped plan
                             ├ subscription ended → team.plan = free (only if the plan came from this subscription)
                             └ on any plan change → CreditService::resetPeriod() grants the new allowance
```

Plan changes are idempotent: a replayed webhook that produces no plan transition does not touch
the credit ledger. Monthly renewals are handled by the existing `chat:reset-credits` scheduled
command, not by webhooks. A sysadmin-granted plan survives subscription end unless it is the
same plan the subscription itself granted.

## Trials

14-day Pro trial, no card, one per user (`users.pro_trial_used_at`). `StartProTrial` sets
`team.plan = pro` and `teams.trial_ends_at` (Cashier generic trial — no Stripe objects until
conversion). The daily `billing:process-trials` command emails a "3 days left" reminder and
downgrades expired trials that never converted. Converting mid-trial is a normal checkout; the
`customer.subscription.*` webhook takes over and clears the trial marker.

## Configuration

| Env var | Purpose |
|---|---|
| `STRIPE_KEY` / `STRIPE_SECRET` | Stripe API keys (test mode for local/staging) |
| `STRIPE_WEBHOOK_SECRET` | Signing secret for `POST /stripe/webhook` |
| `STRIPE_MANAGED_PAYMENTS` | `true` enables the MoR layer per checkout (the off-ramp switch) |
| `STRIPE_PRICE_PRO_MONTHLY` | Stripe price id mapped to the `pro` plan (monthly) |
| `STRIPE_PRICE_PRO_YEARLY` | Stripe price id mapped to the `pro` plan (yearly) |
| `RELATICLE_FEATURE_BILLING` | Pennant flag — gates every billing surface; keep `false` in prod until Stripe is live |

Stripe products must carry an eligible Managed Payments tax code (AIaaS — Business Use,
`txcd_10105002`), and the Managed Payments Terms of Service must be accepted in the Stripe
dashboard before activation.

## Rollout runbook

Engineering never blocks on paperwork — Stripe **test mode works before activation**.

1. Code merges to `main` dark behind `RELATICLE_FEATURE_BILLING` (off in prod).
2. Human track (parallel): Atlas LLC → Stripe activation + Managed Payments ToS + banking
   (Stripe financial account or Mercury) → EIN (no-SSN: 15–25 business days; payments work
   before it) → Armenian accountant consult (LLC repatriation/declarations) → production
   products + prices with AIaaS tax codes → webhook endpoint + `STRIPE_WEBHOOK_SECRET`.
3. Staging E2E with test-mode keys (see checklist).
4. Flip `RELATICLE_FEATURE_BILLING` in prod.

### Staging E2E checklist (test-mode keys)

- [ ] Upgrade → Checkout (monthly) → webhook flips plan to Pro, allowance granted
- [ ] Upgrade → Checkout (yearly) → same, yearly price recorded
- [ ] Start trial (no card) → Pro for 14 days; convert mid-trial → subscription takes over
- [ ] Billing Portal → cancel at period end → page shows "Pro until {date}" → resume
- [ ] Past-due simulation → page shows payment-issue state
- [ ] Schedule team deletion → subscription set to cancel at period end
- [ ] Replay a `customer.subscription.updated` webhook → no usage reset

### Compliance calendar

- Delaware franchise tax — June 1 annually
- Form 5472 + pro-forma 1120 — ~April 15 annually (filing service; $25k penalty if missed)
- Registered agent renewal — annually

## Observability

Sentry (prod) and Horizon catch webhook-job and plan-sync failures; the Stripe dashboard
monitors webhook delivery. Each plan transition emits one structured log line (team, from→to,
subscription id). No bespoke alerting. Future hardening (not built): a nightly Stripe↔`team.plan`
reconciliation command.

## Roadmap

1. ✅ Foundation: billable `Team`, webhook → plan + credit lifecycle, trial, billing page,
   pricing page, sysadmin visibility (this document)
2. Later: annual-billing UX polish, AI-credit top-ups / usage overage via Stripe Billing meters
