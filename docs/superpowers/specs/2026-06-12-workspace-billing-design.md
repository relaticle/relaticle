# Workspace Billing — Design Spec

**Date:** 2026-06-12 · **Revised:** 2026-07-16 · **Issue:** [#95](https://github.com/relaticle/relaticle/issues/95) · **Foundation PR:** [#334](https://github.com/relaticle/relaticle/pull/334) (reworked by this spec)
**Scope:** self-serve paid plans for Relaticle Cloud — billing engine, `/app/billing` page, checkout, trial, pricing page, sysadmin visibility. Ships dark behind a feature flag.

## 1. Summary

Relaticle Cloud bills **per workspace, never per seat** — flat plan tiers gated by AI usage (credits, models, rate limits), keeping the published "No per-seat pricing. Ever." promise. Payments run on **Stripe (Laravel Cashier)** under a **Stripe Atlas Delaware LLC**, with **Stripe Managed Payments** (merchant of record, GA April 2026) enabled per checkout so Stripe registers, files, and remits global indirect tax. Because MP is transaction-level on our own Stripe account, turning it off later (own tax ops, lower fees) is a config flip — customers, payment methods, and subscriptions are ours from day one.

## 2. Locked decisions

| # | Decision | Choice |
|---|---|---|
| 1 | Spec scope | Phases 2+3 (billing page + checkout + pricing page = launchable) |
| 2 | Pro price | **$24/workspace/mo** month-to-month |
| 3 | Annual | **$228/yr** ($19/mo equivalent, save 21%); shown by default with the total disclosed |
| 4–5 | Trial | **Automatic 14-day Pro trial, no card, one per user**, full Pro allowance |
| 6 | Checkout | Hosted Stripe Checkout (no embed) |
| 7 | Roles | Owner-only actions; page visible to all members |
| 8 | Navigation | Tenant menu item; fix dead chat links |
| 9 | Pricing page | 2 cards (Cloud Pro recommended / Self-Hosted free) + Enterprise band |
| 10 | Credit transparency | Real numbers + credit definition published |
| 11 | Rollout | Pennant `Billing` flag, dark until Stripe org ready |
| 12 | Team deletion | Auto-cancel: `cancel()` at scheduling, `cancelNow()` at purge |
| 13 | Observability | Sentry + Horizon + Stripe dashboard; no new alerting |
| 14 | Cancel/payment UX | Stripe Billing Portal only |
| 15 | Sysadmin | Read-only Subscriptions resource (full visibility) |
| 16 | Provider | **Stripe Atlas LLC + Cashier + Managed Payments** (Polar dropped — see `.context/research/billing-provider-research.md` §11) |
| 17 | Managed Cloud Free | **Removed for new workspaces**; existing workspaces grandfathered |
| 18 | Trial expiry / cancellation | Managed access pauses; no usable Cloud Free downgrade |
| 19 | Pricing metric | **Keep the flat workspace price while proving demand**; prefer AI-usage expansion over seats if the data supports it |

Plans stay exactly `App\Enums\Plan`: Free (300 credits/mo, 10 rpm, fast models) · Pro (2,000 credits/mo, 30 rpm, all models) · Enterprise (10,000, 60 rpm, all models, **sales-led only** — manual invoicing + sysadmin grant, no self-serve checkout). `Plan::Free` remains an internal capability bundle for self-hosted installs and grandfathered Cloud workspaces; managed-Cloud entitlement is evaluated separately.

## 3. Architecture

```text
Workspace onboarding (billing enabled)
  └─ Create workspace ─────► automatic StartProTrial action (app-side, no Stripe objects)

Billing page (Filament, tenant-scoped, flag-gated)
  ├─ Legacy trial fallback ► StartProTrial action
  ├─ Upgrade ──────────────► Cashier hosted Checkout session
  │                            └ managed_payments[enabled] = config('services.stripe.managed_payments')
  └─ Manage subscription ──► Stripe Billing Portal

Stripe webhooks ─► Cashier /stripe/webhook (stock controller, maintains subscriptions tables)
                     └ WebhookHandled listener (customer.subscription.*)
                         └ SyncTeamPlanFromSubscription action
                             ├ price id → Plan   (config services.stripe.prices)
                             ├ valid sub → enforce plan; ended → internal Free bundle (manual-grant guard)
                             │                                  └ managed access pauses unless grandfathered
                             └ plan change → CreditService::resetPeriod()
```

- `laravel/cashier` replaces `danestves/laravel-polar`; PR #334 is reworked in place (package, migrations, env, tests). Billable model = **Team** (`Cashier::useCustomerModel(Team::class)`).
- `services.stripe.managed_payments` (bool, per env) is the MoR switch and future off-ramp. **Implementation task #1 is a sandbox spike**: Cashier checkout session-options passthrough of `managed_payments[enabled]`. Fallback if passthrough fails: create the Checkout Session via `stripe-php` directly in our action — Cashier still syncs the resulting subscription via webhooks. Spike outcome changes one class.
- Pennant feature `App\Features\Billing` (`RELATICLE_FEATURE_BILLING`, default off in prod) gates: hosted-access enforcement, billing page + tenant menu item, chat upgrade links (hidden when off), pricing-page Pro card, and trials. With the flag off, self-hosted installs remain unrestricted.
- `HostedWorkspaceAccess` is the single entitlement policy. `EnsureHostedWorkspaceAccess` applies it to Filament (persistent Livewire middleware), REST, MCP, and chat HTTP routes; `ProcessChatMessage` rechecks it at queue execution time.
- Stripe products carry **AIaaS business-use tax codes** (`txcd_10105002`); MP ToS accepted in dashboard before activation.

## 4. Data model

- Cashier published migrations, adapted (same treatment as the Polar foundation): customer columns (`stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`) on **`teams`**; `subscriptions` + `subscription_items` with **ULID owner keys**; `up()` only.
- `users.pro_trial_used_at` (nullable timestamp) — one-trial-per-user enforcement.
- `teams.hosted_free_grandfathered_at` (nullable timestamp) — the migration stamps all existing workspaces; new workspaces default to `null`.
- Price→plan map in `config/services.php`: `services.stripe.prices = ['pro_monthly' => env('STRIPE_PRICE_PRO_MONTHLY'), 'pro_yearly' => env('STRIPE_PRICE_PRO_YEARLY')]` — both resolve to `Plan::Pro`. Enterprise intentionally has no price ids.
- Drop the four `polar_*`/`webhook_calls` migrations from the unmerged branch (never deployed).
- Env: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_PRO_MONTHLY`, `STRIPE_PRICE_PRO_YEARLY`, `STRIPE_MANAGED_PAYMENTS=true`, `RELATICLE_FEATURE_BILLING` (replace `POLAR_*` in `.env.example`).

## 5. Plan sync (behavior preserved from PR #334)

`SyncTeamPlanFromSubscription` keyed off Cashier's `WebhookHandled` for `customer.subscription.created|updated|deleted`:

- Subscription **valid** (active/trialing/past_due/grace) → `team.plan` = mapped plan.
- Subscription **ended** → return to the internal Free bundle **only if** the team's current plan equals the plan this subscription granted — sysadmin grants survive unrelated lapses. Managed access then pauses unless the workspace is grandfathered.
- Unmapped price id → log warning, change nothing.
- Plan **transition** (and only a transition — replays are no-ops, usage is never wiped) → `CreditService::resetPeriod()` grants the new allowance immediately.
- Monthly renewal resets stay with the existing `chat:reset-credits` schedule (00:05 daily). Past-due keeps the plan until Stripe gives up (dunning configured in Stripe dashboard defaults).
- One structured log line per transition: team id, from→to, subscription id.

## 6. Trial

- **Start** (`StartProTrial` action): automatic at hosted workspace creation. Guards — actor is team owner, `pro_trial_used_at` null, team has never had a subscription, team on Free. User and team rows are locked to make the one-trial rule concurrency-safe. Effects — `team.plan = Pro`, `teams.trial_ends_at = now()+14d`, stamp `users.pro_trial_used_at`, `resetPeriod()` (2,000 credits). No Stripe objects are created (Cashier generic trial). The Billing page retains a fallback start action for an eligible grandfathered workspace.
- **During**: days-remaining banner on the billing page; the chat side panel's existing plan line reads "Pro trial — N days left". `onGenericTrial()` drives state. "Subscribe now" → normal checkout; on `subscription.created/active`, clear `trial_ends_at`.
- **Expiry** is enforced synchronously by `HostedWorkspaceAccess`: once `trial_ends_at` is past, a non-grandfathered workspace is paused even before maintenance runs. The daily `billing:process-trials` command sends the single "trial ends in 3 days" Mailable and normalizes expired, unconverted trials to `plan = Free`, `resetPeriod()` (300), and clears `trial_ends_at`. No second email.

## 7. `/app/billing` page (Filament, tenant-scoped, slug `billing`)

State-driven single page; credits data read the same way the chat side panel reads it (`AiCreditBalance`).

| State | Shows | Actions (owner only) |
|---|---|---|
| New hosted workspace | Pro plan card, usage bar | Trial starts automatically during onboarding |
| Paused | data-retained notice + Cloud Pro card | Unlock workspace (monthly/yearly toggle) |
| Grandfathered Free | legacy status + usage bar + Cloud Pro card | Optional trial if unused; otherwise Upgrade |
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

Two cards: **Cloud Pro, Recommended** ($19/mo equivalent when billed $228 yearly, shown by default; $24 month-to-month — unlimited users and records, 2,000 credits/mo, all models incl. premium, REST API + 30-tool MCP, email support; CTA "Start your 14-day trial — no card" → register) / **Self-Hosted** (free forever, AGPL-3.0). There is no managed-Cloud Free card. The annual total is always disclosed beside the monthly equivalent. Below the cards: the Enterprise band ("Higher AI allowances, custom invoicing — talk to us") and credit definition line ("1 credit ≈ one AI chat message or record summary; allowances may evolve with notice"). H1 "No per-seat pricing. Ever." remains. The strategic comparison below is internal and is not rendered as a public competitor or team-cost matrix. Flag off renders the legacy page unchanged.

### Pricing rationale and benchmark (checked 2026-07-16)

The launch price is deliberately below established collaborative CRM price points while still charging from day one after the trial. It is a demand-validation price, not a promise that pricing never changes. Stripe's subscription-model guidance recommends choosing against customer value and competition, gathering feedback, and testing the result rather than treating the first number as permanent: [Stripe — SaaS subscription models](https://stripe.com/resources/more/saas-subscription-models-101-a-guide-for-getting-started).

Annual is the default presentation because it gives the buyer the lowest committed price and gives the business earlier cash collection. Monthly remains equally available. The UI follows the transparent convention used by products such as [Pipedrive](https://www.pipedrive.com/en/pricing): show the monthly equivalent and disclose the annual charge beside it. The $60 annual saving is 20.8%, displayed as 21%; [Paddle's annual-plan guidance](https://www.paddle.com/resources/annual-plans) describes 15–20% as a common starting range, so this launch offer is intentionally slightly more aggressive.

### Pricing model decision: workspace vs seats

The decision is whether to migrate Cloud Pro to per-seat billing before launch or keep the current workspace subscription. A third option is included because current SaaS pricing is increasingly hybrid:

| Option | Model | Best argument | Main risk |
|---|---|---|---|
| **A — Keep flat now** | $24/workspace monthly or $228/workspace yearly, with 2,000 shared AI credits | Lowest-friction demand test; every invite increases product value without increasing the bill | Weak automatic expansion revenue from larger teams |
| **B — Migrate to seats now** | One charge per billable member; exact price would require willingness-to-pay research | Familiar CRM metric and revenue expands as a customer adds people | Invite friction, implementation complexity, and seats do not directly track Relaticle's AI cost |
| **C — Workspace + AI usage later** | Keep a workspace subscription, then add larger allowances or paid credit usage after observing demand | Monetizes the resource and outcome that actually vary while preserving collaboration | Premature now; metering, spend controls, and overage UX add complexity |

#### Weighted decision matrix

Scores are 1–5, where 5 is best. The weights reflect the launch goal: prove paid demand without damaging collaboration. The weighted total is `sum(weight × score) / 5`.

| Criterion | Weight | A — Flat now | B — Seats now | C — Workspace + AI usage |
|---|---:|---:|---:|---:|
| Activation and invitation freedom | 20% | 5 | 2 | 5 |
| Clarity of the initial demand test | 15% | 5 | 3 | 3 |
| Expansion revenue / NRR potential | 15% | 2 | 5 | 4 |
| Alignment with AI value and marginal cost | 15% | 4 | 3 | 5 |
| Open-source / market differentiation | 10% | 5 | 2 | 4 |
| Buyer predictability | 10% | 5 | 4 | 3 |
| Solo and small-team entry | 10% | 4 | 5 | 4 |
| Billing and product simplicity | 5% | 5 | 2 | 1 |
| **Weighted total** | **100%** | **86 / 100** | **65 / 100** | **79 / 100** |

**Decision: A wins for launch.** C is the most credible expansion path once real usage and willingness-to-pay data exist. B is not justified before launch: its expansion benefit is real, but it scores against the current product, distribution, and validation goals.

#### Current market evidence

- Seats remain common, but pure seat pricing is no longer the whole market. SBI/Price Intelligently's 2025 survey of 321 SaaS operators reports that more than 80% use seats somewhere in pricing, while only 8% use seats as the sole value metric; 37% use seats as a primary metric augmented by another metric and 33% use them secondarily. The same report shows platform fees with included usage as the largest structure in its sample (37%). Source: [2025 State of SaaS Pricing](https://sbigrowth.com/hubfs/1-Research%20Reports/2025_Q2_Gated_SoSPR_Part1/SBI_Price_Intelligently_SaaSPricingReport_2025_Part1.pdf).
- Maxio's 2025 survey reports the highest median growth (21%) among hybrid subscription-plus-usage companies. This is directional evidence, not proof of causation, and the report also warns about added operational complexity. Source: [2025 SaaS Pricing Trends](https://www.maxio.com/resources/2025-saas-pricing-trends-report).
- Stripe describes the core trade-off cleanly: per-user pricing makes revenue scale linearly with customer headcount, while flat pricing is simple and predictable but must grow through acquisition or later packaging changes. Source: [Stripe subscription pricing models](https://stripe.com/en-pl/resources/more/subscription-pricing-models-a-guide-for-businesses).

Official annual-equivalent benchmarks show that Relaticle's market is mostly seat-priced, but not uniformly so:

| Product | Open-source posture | Current billing signal | Strategic lesson |
|---|---|---|---|
| [Twenty](https://twenty.com/pricing) | Open-source CRM with free self-hosting | Pro $9/user/mo; Organization $19/user/mo | The closest open-source CRM uses seats, so open source alone does not rule them out |
| [GitLab](https://about.gitlab.com/pricing/) | Open-core, cloud and self-managed | Premium $29/user/mo plus included and add-on AI/compute credits | A modern seat base can be augmented by usage |
| [folk](https://www.folk.app/pricing) | Proprietary CRM | Standard $24/member/mo | Per-member pricing is familiar in collaborative CRM |
| [Attio](https://attio.com/pricing?level=0) | Proprietary CRM | Plus $29/user/mo with seat and workspace credits | CRM pricing is moving toward seat-plus-usage hybrids |
| [Basecamp](https://basecamp.com/pricing) | Proprietary collaboration software | Pro Unlimited $299/mo billed annually for unlimited users | A fixed organization price can be a deliberate collaboration differentiator |

#### Relaticle-specific economics

- **Self-hosting changes the cloud trade-off.** Relaticle's AGPL self-hosted edition is free. A hosted bill that rises with every invite increases the financial incentive for a growing team to leave Cloud and self-host. Keeping Cloud flat monetizes convenience and managed operations without penalizing collaboration. This is an inference from Relaticle's distribution model, not a claim that open-source products cannot charge by seat.
- **Seats are not the present cost driver.** The plan includes a shared 2,000-credit AI allowance. AI work, storage, support, and automation load create cost; merely adding a member does not create equivalent marginal cost. The included allowance already bounds the largest variable cost more directly than a seat count.
- **Flat pricing has a real downside.** It under-monetizes a ten-person customer relative to per-seat competitors and provides little automatic expansion MRR. Unlimited users and records could also become uneconomic if storage or support, rather than AI, becomes the dominant cost. Those risks should be measured rather than assumed away.
- **The low launch price is a validation instrument.** $24 monthly / $228 yearly asks whether a workspace will pay at all. Introducing member counting, proration, invite warnings, and billable-role rules would test packaging mechanics at the same time as demand.

#### Review checkpoint and decision rules

Keep the workspace model through at least 90 days of paid use and a meaningful cohort (suggested: 25–50 paid workspaces). Review:

- paid conversion and retention by active-member band;
- invitations before and after conversion;
- AI-credit utilization, requests for more credits, and gross margin by workspace;
- storage/support cost by workspace; and
- willingness-to-pay feedback from solo users, small teams, and larger teams.

Then apply these rules:

1. If AI utilization or requests for higher allowances explain value and cost, evolve toward **C** with higher workspace tiers or controlled credit top-ups.
2. If active paid seats predict willingness to pay and retention substantially better than AI usage, and flat pricing materially undercaptures larger-team value, reopen **B** as an explicit strategy change.
3. If neither is true but conversion is healthy, raise the flat price for new customers or add a higher workspace tier before introducing a new billing metric.

No seat counting, proration, or billable-member logic is added in this launch. Changing the public no-per-seat commitment later would require a separate evidence-backed product decision, migration design, and customer communication.

## 11. Sysadmin

- New **read-only `SubscriptionResource`** (Cashier `subscriptions` table): team, price→plan, status badge, interval, current period end, origin column, status filters, deep links to the Stripe dashboard customer + subscription. No mutations. Origin derivation: valid subscription → `subscription`; else `trial_ends_at` in future → `trial`; else plan ≠ Free → `manual`; else `—`.
- `AiCreditBalanceResource` "Reset billing period" action: inline warning when the team has a valid subscription ("webhook sync will re-assert the subscribed plan — cancel in Stripe first").

## 12. Observability

Sentry (prod) + Horizon catch webhook/job failures; Stripe dashboard monitors delivery; structured transition logs per §5. No new alerting code. Future hardening (explicitly not built): nightly Stripe↔`team.plan` reconciliation command.

## 13. Testing

- **Webhook suite** (rework of the 8 Polar tests): through `POST /stripe/webhook` with computed `Stripe-Signature` — incomplete→no change, activation grants allowance, replay keeps usage, price-swap switches plan, deletion downgrade, manual-grant survives lapse, unmapped price inert, bad signature rejected.
- **Trial**: automatic onboarding start, concurrency-safe one-per-user guard, non-owner / second trial / already-subscribed / non-Free paths, expiry command, mid-trial conversion, T-3 email targeting.
- **Hosted entitlement**: active and expired trial, paid/manual, grandfathered, billing-disabled self-host, Filament redirect, REST/MCP/chat 402, and a queued chat turn that outlives access.
- **Billing page**: render per state ×8 incl. non-owner gating; checkout + portal redirects (Stripe client faked at the action boundary).
- **Team deletion**: cancel-at-schedule, revoke-at-purge.
- **Pricing page & flags**: flag on/off for page, menu, chat links; credit numbers present.
- Gates: Pint, Rector, PHPStan, 100% type coverage, `mutates()` declarations, ULID-key migration test.

## 14. Rollout

Engineering never blocks on paperwork — Stripe **test mode works pre-activation**:

1. Rework PR #334 (this spec) → merge dark behind flag.
2. Human track (parallel): Atlas LLC ($500, ~2 days) → Stripe activation + **MP ToS** + banking (Stripe financial account or Mercury — verify Armenia not prohibited) → EIN (no-SSN: 15–25 business days official, 4–8 weeks real; payments work pre-EIN) → Armenian accountant consult (LLC repatriation/declarations) → production products + prices with AIaaS tax codes → webhook endpoint + secrets.
3. Staging E2E with test keys (automatic trial, pause gates, grandfathering, checkout, portal, cancel, deletion).
4. Flip `RELATICLE_FEATURE_BILLING` in prod.
5. Update public artifacts: `docs/billing.md` rewritten Stripe-shaped, issue #95 body, PR #334 description.
6. Compliance calendar: Delaware franchise tax (June 1), Form 5472 + pro-forma 1120 (≈April 15, filing service — $25k penalty if missed), registered agent renewal.

## 15. Out of scope

Credit top-ups / usage overage (phase 4 — Stripe Billing credit management fits later) · enterprise self-serve checkout · PO/wire automation (manual Stripe Invoicing per deal) · in-app cancel · embedded checkout · refund tooling · reconciliation command · custom multi-currency price points (MP Adaptive Pricing localizes display automatically) · any self-hosted/AGPL changes.

## 16. Open items (non-blocking, tracked in runbook)

1. Armenian accountant sign-off on the LLC structure (no-ECI 0% US income tax assumption + local declaration).
2. Banking path confirmed (Stripe financial account / Mercury Armenia check; keep a backup institution).
3. Sandbox spike result: Cashier session-options passthrough of `managed_payments[enabled]` (fallback path defined in §3).
