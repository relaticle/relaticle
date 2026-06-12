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

Payments run through [Polar.sh](https://polar.sh) as the merchant of record, which handles
global VAT/sales-tax collection and remittance. Integration is via
[`danestves/laravel-polar`](https://github.com/danestves/laravel-polar) with `Team` as the
billable model.

```text
Polar checkout (Team-scoped metadata)
        │  webhooks (standard-webhooks signature)
        ▼
POST /polar/webhook ─► spatie/laravel-webhook-client ─► ProcessWebhook job
        │  subscription.created / .active / .updated / .canceled / .revoked
        ▼
SyncPlanOnPolarSubscriptionChange (listener)
        ▼
SyncTeamPlanFromSubscription (action)
   ├─ maps Polar product id → Plan  (config/services.php → services.polar.products)
   ├─ subscription valid    → team.plan = mapped plan
   ├─ subscription ended    → team.plan = free  (only if the plan came from this subscription)
   └─ on any plan change    → CreditService::resetPeriod() grants the new allowance
```

Plan changes are idempotent: a replayed webhook that produces no plan transition does not
touch the credit ledger. Monthly renewals are handled by the existing
`chat:reset-credits` scheduled command (daily at 00:05), not by webhooks.

A sysadmin-granted plan survives subscription revocation unless it is the same plan the
subscription itself granted — manual Enterprise grants are never clobbered by a lapsed
self-serve subscription.

## Configuration

| Env var | Purpose |
|---|---|
| `POLAR_ACCESS_TOKEN` | Organization access token (Polar dashboard → Settings → Developers) |
| `POLAR_ORGANIZATION_ID` | Optional; needed for license-key endpoints only |
| `POLAR_SERVER` | `sandbox` (default) or `production` |
| `POLAR_WEBHOOK_SECRET` | Secret for the `/polar/webhook` endpoint (Polar dashboard → Webhooks) |
| `POLAR_PRODUCT_PRO` | Polar product id that maps to the `pro` plan |
| `POLAR_PRODUCT_ENTERPRISE` | Polar product id that maps to the `enterprise` plan |

Local development runs against the [Polar sandbox](https://sandbox.polar.sh). Point a
webhook at `https://relaticle.test/polar/webhook` (or use a tunnel) with the events:
`subscription.created`, `subscription.updated`, `subscription.active`,
`subscription.canceled`, `subscription.revoked`, `order.created`, `order.updated`.

## Roadmap

1. ✅ Foundation: billable `Team`, webhook → plan + credit lifecycle (this document)
2. `/app/billing` page: current plan, credit usage, upgrade checkout, customer portal
3. Pricing page: Pro tier, keeping the no-per-seat promise
4. Later: annual billing, AI-credit top-ups / usage overage via Polar meters
