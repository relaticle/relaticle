# Managed plan billing: invoiced subscriptions for Concierge and Enterprise

Date: 2026-10-26. Branch: `fix/pricing-page-polish` (PR #1023). Status: decisions complete, awaiting the author's approval of this document.

## Why

PR #1023 adds Cloud Concierge ($708 a year) and keeps Enterprise as plans "granted by hand and
invoiced outside Stripe Managed Payments" (`Plan::isManaged()`). Today nothing connects the money
to the access. A sysadmin sends an invoice from the Stripe Dashboard, watches for the payment, then
grants the plan with Credit balances > Reset period. Nothing renews the term, nothing ends access
when a renewal goes unpaid, and a failed or overdue payment raises no alert in the app.

This design makes a paid Stripe invoice the thing that switches a managed plan on, and Stripe's own
subscription lifecycle the thing that renews or ends it.

## Goals

- A sysadmin starts a managed plan for a workspace with one action, and Stripe emails the invoice.
- The plan switches on only when the first invoice is paid.
- A paying Pro subscriber moving to a managed plan keeps Pro until the payment lands. At that
  moment Pro is cancelled and the unused Pro time is refunded to their card.
- Renewal invoices go out yearly from Stripe. An unpaid renewal follows Stripe's dunning, and the
  workspace drops to Free with hosted access paused, as an unpaid Pro workspace does.
- The owner can open the Stripe portal to see and pay invoices and update payment details, with no
  self-serve cancel.

## Non-goals

- Self-serve purchase of Concierge or Enterprise. The sales conversation stays in charge.
- Milestone or multi-invoice Enterprise contracts. Enterprise here is one yearly amount.
- Replacing the hand grant. Sysadmin > Credit balances > Reset period stays for comps and fixes.
- Moving Pro off Managed Payments, or changing how Pro is sold.
- Automatic tax registration. Stripe Tax on these invoices is a Dashboard decision outside the code.

## Anti-goals (a technically working result that would still be a failure)

- A managed plan, its credits, or hosted access granted before the first invoice is paid.
- Value from a Managed Payments sale (Pro) applied to the managed invoice through the customer
  balance. Stripe is the merchant of record for Pro, and mixing the two sales mixes tax liability.
- Two live subscriptions billing the same workspace after the managed invoice is paid.
- A workspace stuck on a plan nobody pays for after a managed invoice is never paid.
- A Stripe call that can block the billing page from rendering.

## Verified in the Stripe sandbox (2026-10-26, `acct_1ThugED…`, test mode)

A throwaway customer bought Pro monthly through a Checkout session with `managed_payments` enabled
(VAT added, adaptive pricing in GEL). Then, on the same customer:

- A `send_invoice` yearly subscription with inline `price_data` was created through the API. It
  started `active` with its first invoice in `draft` ($708.00, `starting_balance` 0). Finalized,
  the hosted invoice reads "From Relaticle", not Link, and the invoice has no `managed_payments`
  flag. A Managed Payments customer can hold an invoiced subscription that is not a Managed
  Payments sale.
- Paying that invoice fired `invoice.paid` with `billing_reason: subscription_create`.
- `refunds.create` on the Pro payment intent for part of the amount succeeded. The platform can
  refund a Managed Payments charge through the API.
- Cancelling Pro with `prorate: false` left the customer balance at 0.

Every spike object was cancelled, deleted or archived afterwards.

## Decisions (made by the author on 2026-10-26)

1. **What the customer pays: a yearly Stripe subscription billed by invoice**
   (`collection_method: send_invoice`). It is created through the API, not Checkout, so it sits
   outside Managed Payments. Stripe sends the invoice, the reminders and every renewal.
2. **Who starts it: a sysadmin action, "Start managed plan"**, on the SystemAdmin Workspace
   resource. The app creates the Stripe customer when the workspace has none, then the
   subscription on that customer with `workspace_id` in the subscription metadata.
   - **One action for both managed plans.** The sysadmin picks Concierge or Enterprise and the
     yearly amount. The amount defaults to `config('relaticle.concierge.price_yearly')` (708) for
     Concierge and `config('relaticle.enterprise.starting_price_yearly')` (20,000) for Enterprise,
     and is editable. The subscription item uses inline `price_data` on one Stripe product per
     managed plan, so Concierge's invoice amount and the pricing page read the same config value.
   - **The plan is resolved from the Stripe product**, not the price id, for managed plans. Product
     ids live in `config('services.stripe.products.concierge')` and `...enterprise`. Pro keeps
     resolving by price id as today.
3. **When it switches on: on the first paid invoice.** An `invoice.paid` webhook for a subscription
   whose item product is a managed product grants the plan and resets credits to that plan's
   allowance. Before that, the workspace keeps whatever it had (trial, Pro or Free), and the open
   managed subscription grants nothing.
4. **Unpaid renewal or term end: follow Stripe's dunning.** While the managed subscription is
   `past_due`, access and the full managed allowance stay, and the owner sees a past-due banner.
   When Stripe cancels the subscription, the workspace drops to Free with hosted access paused,
   through the same path as an ended Pro subscription. The owner's messages name the managed plan,
   not Pro.
5. **A live Pro subscription: switch on payment.** "Start managed plan" only creates the managed
   subscription and sends the invoice. Pro keeps running. In the `invoice.paid` handler, before the
   plan is granted, Pro is cancelled immediately and its unused time is refunded to the card through
   Stripe's refund on the Pro payment. If the managed invoice is never paid, Pro simply continues.
6. **Customer self-service: the Stripe Customer Portal with a managed-plan configuration.**
   Invoices and payment details are on, cancellation is off. The configuration id lives in config.
   The billing page shows the next renewal date and amount.

7. **Invoice recipient: an optional "Billing email" field on the action.** When filled, the
   address is added as an extra invoice recipient. The Stripe customer's own email, and with it the
   Pro receipts and portal mail, stays the owner's. Legal name and tax ID are edited in the Stripe
   Dashboard.
8. **Moving between managed plans, or a new price: start again and replace on payment.**
   "Start managed plan" on a workspace that already has a live managed subscription creates the
   new one and sends its invoice. Access stays on the current managed plan. When the new invoice is
   paid, the `invoice.paid` handler cancels the old managed subscription and refunds its unused time,
   exactly as it does for Pro (decision 5), then grants the new plan. The old subscription's
   cancellation is a replacement, so it never runs the ended path: no downgrade, no "plan ended"
   mail.
9. **Withdrawing an unpaid managed invoice: a sysadmin action "Withdraw managed invoice".** It voids
   the open first invoice and cancels the unpaid managed subscription. Nothing was granted, so the
   workspace's plan does not change. It is offered only while the workspace has a managed
   subscription whose first invoice is unpaid.

## Builder calls (made while writing, open to the author's correction)

These follow from the decisions and the code. A builder may change one if the code proves it wrong,
and says so in the plan.

- **Subscription type `managed`.** The managed subscription is a Cashier subscription of type
  `managed`, not `default`. Pro's `default` subscription and every check built on
  `$workspace->subscription()` and `subscribed()` stay exactly as they are, so an open, unpaid
  managed subscription cannot unpause a paused workspace (anti-goal 1). Reads that need the managed
  subscription use `$workspace->subscription('managed')`: the billing page's past-due banner,
  renewal line and portal button, and `BillingStatus::fromWorkspace()`'s past-due check for a
  managed plan.
- **Granting stays in one place.** `SyncWorkspacePlanFromSubscription` keeps owning plan changes
  from Stripe. It learns two rules for a managed-product subscription. It never grants on a status
  change, because only the paid handler grants. And when the subscription ends while the workspace
  holds that managed plan, it returns the workspace to `Plan::default()`, exactly as it does for Pro.
- **Idempotency.** The `invoice.paid` handler keys its grant and its Pro refund on the invoice id,
  the way credit packs key on `pack-{sessionId}`. A redelivered event grants once and refunds once.
- **Ownership check.** The handler acts only when the invoice's customer equals the workspace's
  `stripe_id` and the subscription metadata names that workspace, mirroring
  `fulfillCreditPackCheckout()`. A mismatch is logged and acknowledged, never acted on.
- **Pro refund amount.** Unused Pro time = the latest paid Pro invoice's amount paid, times the
  unused fraction of its current period, rounded down to the cent. A Pro trial with no charge
  refunds nothing. Pro is cancelled with no proration credit (`prorate: false`), so no customer
  balance is created.
- **Owner notice on overdue.** When a managed subscription moves to `past_due`, the owner gets one
  notification with managed-plan wording. `invoice.payment_failed` does not fire for an invoice
  nobody tried to charge, so this rides `customer.subscription.updated`.
- **The "plan ended" mail** gets a managed-plan variant of `ProEndedMail::afterSubscription()`.
- **Renewal line without a blocking Stripe call.** The billing page reads the renewal date and amount
  from data stored at sync time, or from a cached Stripe read with a short timeout and a fallback
  that omits the line. It never fails the page.
- **Due date.** `days_until_due` comes from config, default 30, which matches Cashier's
  `createAndSendInvoice()` default.
- **Refusals.** "Start managed plan" refuses while another managed subscription is still waiting
  on its first payment (withdraw it first, decision 9). It does not refuse a live Pro subscription
  (decision 5) or a paid managed one (decision 8).
- **One open replacement at a time.** Between "Start" and payment a workspace can hold two
  subscriptions of type `managed`: the paid one and the new unpaid one. Every read of "the managed
  subscription" picks the one whose first invoice is paid, never Cashier's latest-created default.
- **Finalize and send at once.** An API-created `send_invoice` subscription leaves its first invoice
  in `draft` for about an hour. The action finalizes it and sends it immediately, so the customer
  receives it when the sysadmin clicks.
- **Nothing granted, nothing taken.** A managed subscription whose first invoice was never paid has
  granted nothing. When Stripe cancels it, the sync leaves the workspace's plan alone. This is the
  same rule `StripeSubscriptionStatus::neverGranted()` applies to an abandoned Pro checkout today. It
  protects a hand-granted plan from an unrelated unpaid invoice.
- **Past-due messages only for a plan the workspace holds.** The past-due banner and the overdue
  notice show only when the workspace's plan is the managed plan that subscription sells. A Pro
  owner whose Concierge invoice is merely unpaid sees nothing new.
- **Customer balance.** The action refuses while the Stripe customer's balance is not zero, and says
  so. Stripe applies a credit balance to the next finalized invoice, which would carry Managed
  Payments value onto the managed invoice (anti-goal 2). The sysadmin clears it in Stripe first.
- **Trial kept until payment.** Cashier clears the workspace's generic trial when a subscription is
  created. The action restores it the way `handleCustomerSubscriptionCreated()` restores it after an
  abandoned checkout, so a trial workspace keeps its trial until the managed invoice is paid.
- **The Pro refund includes tax.** Stripe refunds the tax portion with the amount. In some
  jurisdictions Stripe keeps the original tax and the account balance drops by it (Stripe's Managed
  Payments refund rules). The owner-facing copy states the refund amount Stripe reports.
- **No migration.** `subscriptions.type` and `subscription_items.stripe_product` already exist.

## Constraints

- PostgreSQL only. Actions are `final readonly` with one `execute()`. Every write from the sysadmin
  panel goes through an action. `packages/SystemAdmin` is outside PHPStan, so the browser walk is the
  check on any `match` there.
- `config('relaticle.concierge.price_yearly')` stays the single owner of the Concierge price.
- New user-facing strings go through `__()` in `lang/en/billing.php` or `lang/en/mail.php`, with no
  em-dash.
- Every Stripe call in a test goes through `Tests\Helpers\StripeRecorder`. No test reaches Stripe.
- Deploy-time steps, recorded in the PR body, not in code:
  - Create the two products and the portal configuration in the live Stripe account, then set
    their ids in production env.
  - Set Billing > Subscriptions and emails > "Manage invoices sent to customers" to cancel the
    subscription 30 days after an invoice becomes past due. This is an account-wide setting.
  - Enable Stripe Tax on invoices if the accountant confirms it.

## Proof the builder owes

- Feature tests through the real entry points: both sysadmin actions, `POST /stripe/webhook` for
  `invoice.paid`, `customer.subscription.updated` (past due, then cancelled), redelivery, wrong
  customer, a Pro subscriber switching (refund amount, Pro cancelled, plan granted once), a Concierge to
  Enterprise replacement (old subscription cancelled and refunded, no ended path), and a withdrawn
  invoice (voided, plan unchanged).
- A sandbox loop on `acct_1ThugED…`:
  - Start Concierge on a workspace that bought Pro through a Managed Payments Checkout.
  - Pay the invoice through the hosted page.
  - Check the Pro refund and the plan.
- A browser walk of the sysadmin action, the owner's billing page before and after payment, the
  past-due banner and the portal.

## Left to the builder

Class and method names, how the renewal data is stored or cached, the exact copy (to the writing
rules), and test organisation within the existing billing test files.
