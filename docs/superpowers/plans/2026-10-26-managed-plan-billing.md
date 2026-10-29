# Managed Plan Billing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `sdd-lean` to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A paid Stripe invoice switches a managed plan (Concierge, Enterprise) on, and Stripe's yearly subscription lifecycle renews or ends it.

**Architecture:** A sysadmin action creates a yearly `send_invoice` subscription of local type `managed_pending` on the workspace's Stripe customer. An `invoice.paid` webhook flips it to `managed`, cancels and refunds whatever it replaces (Pro, or an older managed subscription), and grants the plan. The existing plan sync learns that a managed subscription never grants and only ends a plan its own paid subscription granted.

**Tech Stack:** Laravel 13, Filament 5 (SystemAdmin panel), Cashier 16, stripe-php 21, Pest 4, PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-10-26-managed-plan-billing-design.md`. Read it first. Its anti-goals are the acceptance bar.

**Branch:** `feat/managed-plan-billing`, off `main` at `f8b122ef2` (PR #1023 merged).

## Global Constraints

- Actions are `final readonly` with one public `execute()`. App actions live in `app/Actions/Billing/`, sysadmin actions in `packages/SystemAdmin/src/Actions/`.
- `App` must not use `Relaticle\SystemAdmin` (`tests/Arch/ArchTest.php`).
- Every user-facing string goes through `__()` in `lang/en/billing.php` or `lang/en/mail.php`. No em-dash anywhere. Plan names in copy are "Cloud Concierge" and "Enterprise".
- Tests are Feature tests through real entry points: `POST /stripe/webhook`, the Filament actions, the billing page. No test reaches Stripe. Every Stripe call goes through `Tests\Helpers\StripeRecorder`. No comments in tests. Each new test file declares `mutates(...)`.
- Methods stay under 60 lines (`MethodLengthRule`). No new PHPStan ignores. `packages/SystemAdmin` is outside PHPStan, so Task 8's browser walk is its check.
- Dates are `CarbonImmutable` via `now()`. Never name `Carbon`.
- No migration. State lives in `subscriptions.type`, which already exists.
- Stripe ids and the due date come from config, never literals: `services.stripe.products.concierge`, `services.stripe.products.enterprise`, `services.stripe.portal_configurations.managed`, `services.stripe.managed_days_until_due` (default 30).
- The Concierge amount default is `config('relaticle.concierge.price_yearly')` (708). The Enterprise default is `config('relaticle.enterprise.starting_price_yearly')` (20,000). Amounts are whole US dollars in the form and cents in Stripe.
- Local loop per task: `vendor/bin/pint --dirty --format agent`, then the task's test files. Before the final push: `composer test:lint`, `composer test:refactor`, `composer test:types`, `php -d memory_limit=2G vendor/bin/pest tests/Arch`.

## Decisions the plan makes beyond the spec

These change the mechanism and keep the outcome. The PR body must state them.

1. **Pending and paid are two subscription types.** `managed_pending` means "sent, not paid". `managed` means "paid, granted". The type flip in Task 3 is the grant's idempotency marker, and the spec's "pick the paid one" rule is `subscription('managed')`.
2. **The billing email is our own mail.** The Stripe API cannot add CC recipients. Stripe's support page says that is Dashboard-only. When the sysadmin fills "Billing email", the app mails that address the hosted invoice link (`ManagedPlanInvoiceMail`). The customer email stays the owner's.
3. **`BillingStatus` is not touched.** A past-due managed workspace already reads as Concierge or Enterprise and keeps access. The overdue banner reads the managed subscription directly.
4. **The renewal line reads a cache entry** written by the webhooks from the fresh Stripe object (`RememberManagedRenewal`). A cache miss omits the line. The billing page makes no Stripe call.
5. **`payment_behavior: allow_incomplete`** is passed explicitly. Cashier's default `default_incomplete` could leave the subscription `incomplete`, and Cashier deletes an `incomplete_expired` row after 23 hours. The 2026-10-26 sandbox spike, with no `payment_behavior`, started `active`.
6. **Grant first.** The plan is granted before replaced subscriptions are cancelled, so a late Pro deleted event cannot downgrade a paying workspace; a failed refund retries without re-granting.

## Review Focus

1. **`invoice.paid` delivered twice.** It grants once, refunds once and cancels once. Task 3 owns the test "grants and refunds once when Stripe redelivers the paid invoice".
2. **`invoice.paid` for an invoice that is not a managed first invoice** (a Pro renewal, a credit pack, a managed renewal). It changes nothing and grants nothing. Task 3 owns "ignores a paid invoice that does not belong to a pending managed subscription".
3. **A Pro subscriber still on a Stripe trial (nothing charged) switches.** No refund call is made, Pro is cancelled and the plan is granted. Task 3 owns "switches a Pro trial with no refund".
4. **A paused workspace (trial ended) is sent an invoice.** It stays paused until the invoice is paid. Task 2 owns "keeps a paused workspace paused while the managed invoice is unpaid".
5. **Stripe fails mid-switch** (the refund call errors). The webhook returns 500 so Stripe retries. The plan is already granted, and the retry refunds once with the same key and amount. Task 3 owns "grants the plan and retries the switch refund when the refund call fails".

---

### Task 1: Managed plan facts and config

**Files:**
- Create: `app/Enums/SubscriptionType.php`
- Modify: `app/Enums/Plan.php`, `config/services.php` (the `stripe` block), `.env.example` (the Stripe section), `config/cashier.php` (`webhook.events`)
- Test: `tests/Feature/Billing/StripeWebhookTest.php`

**Interfaces:**
- Produces:
  - `enum SubscriptionType: string { case Default = 'default'; case Managed = 'managed'; case ManagedPending = 'managed_pending'; }`
  - `Plan::fromStripeProduct(?string $productId): ?Plan` maps the configured product ids to Concierge or Enterprise, and returns null otherwise.
  - `Plan::stripeProduct(): ?string` returns the configured product for a managed plan, null for Free and Pro.
  - `Plan::defaultYearlyPrice(): ?int` returns the config dollar amounts above for the managed plans, null otherwise.
  - Config keys `services.stripe.products.{concierge,enterprise}` (env `STRIPE_PRODUCT_CONCIERGE`, `STRIPE_PRODUCT_ENTERPRISE`), `services.stripe.portal_configurations.managed` (env `STRIPE_PORTAL_CONFIGURATION_MANAGED`), and `services.stripe.managed_days_until_due` as `(int) env('STRIPE_MANAGED_DAYS_UNTIL_DUE', 30)`.

- [ ] **Step 1: Write the failing test** in `StripeWebhookTest.php`, next to the existing "subscribes the Stripe endpoint" tests:

```php
it('subscribes the Stripe endpoint to paid invoices', function (): void {
    expect(config('cashier.webhook.events'))->toContain('invoice.paid');
});
```

- [ ] **Step 2: Run it.** `php artisan test --compact tests/Feature/Billing/StripeWebhookTest.php --filter='paid invoices'`. Expect FAIL.
- [ ] **Step 3: Implement.** Add `'invoice.paid'` to `cashier.webhook.events`, with a one-line comment that managed plans switch on there. Add the enum, the three `Plan` methods (each a `match` over every case with no `default`) and the config keys. Add the four env names to `.env.example`, empty.
- [ ] **Step 4: Run** the test and `vendor/bin/phpstan analyse app/Enums`. Expect PASS and 0 errors.
- [ ] **Step 5: Commit** `feat(billing): add managed plan stripe products and subscription types`.

### Task 2: Start a managed plan from sysadmin

**Files:**
- Create: `packages/SystemAdmin/src/Actions/StartManagedPlan.php`, `packages/SystemAdmin/src/Filament/Support/ManagedPlan.php`, `app/Mail/ManagedPlanInvoiceMail.php`, `resources/views/mail/managed-plan-invoice.blade.php`
- Modify: `packages/SystemAdmin/src/Policies/WorkspacePolicy.php`, `packages/SystemAdmin/src/Filament/Resources/WorkspaceResource.php` (record actions), `packages/SystemAdmin/src/Filament/Resources/WorkspaceResource/Pages/ViewWorkspace.php` (header actions), `lang/en/mail.php`, `tests/Helpers/StripeRecorder.php` (only if a canned response is missing)
- Test: create `tests/Feature/SystemAdmin/ManagedPlanActionsTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `WorkspacePolicy::manageManagedPlan(SystemAdministrator $admin, Workspace $workspace): bool`, true when `$admin->role->canManageCustomerAccess()`.
  - `StartManagedPlan::execute(SystemAdministrator $admin, Workspace $workspace, Plan $plan, int $yearlyAmount, ?string $billingEmail = null): Subscription`. It returns the local Cashier row of type `managed_pending`.
  - `ManagedPlan::startAction(): Filament\Actions\Action` (name `startManagedPlan`), and `ManagedPlan::withdrawAction()` (added in Task 5).
  - `ManagedPlanInvoiceMail::__construct(Workspace $workspace, Plan $plan, string $hostedInvoiceUrl, int $amount)`, queued.

What `execute()` does, in order:
1. Authorize with `Gate::forUser($admin)`.
2. Refuse, with a `ValidationException` on `plan`, when:
   - the plan is not managed,
   - its product isn't configured,
   - a `managed_pending` subscription is still valid,
   - or the Stripe customer's `balance` is not 0 ("Clear the customer's Stripe balance first").
3. Remember `trial_ends_at`, then `createOrGetStripeCustomer()`.
4. Create the subscription: `newSubscription(SubscriptionType::ManagedPending->value)` with `price(['price_data' => ['currency' => 'usd', 'product' => $plan->stripeProduct(), 'unit_amount' => $yearlyAmount * 100, 'recurring' => ['interval' => 'year']]])`. Then `->create(null, [], [...])` with these options:
   - `collection_method: send_invoice`
   - `days_until_due` from config
   - `payment_behavior: allow_incomplete`
   - `metadata: { workspace_id, type: managed_pending }`
5. Finalize and send the first invoice with `stripe()->invoices->sendInvoice($latestInvoiceId)`.
6. Restore `trial_ends_at` if Cashier cleared it.
7. When `$billingEmail` is set, mail it `ManagedPlanInvoiceMail` with the sent invoice's `hosted_invoice_url`.

The Filament action form has three fields:
- `plan`: Select over Concierge and Enterprise.
- `amount`: numeric, required, min 1, defaulting to `$plan->defaultYearlyPrice()` and live-updated when the plan changes.
- `billing_email`: email, optional.

The modal states: "Stripe emails a yearly invoice. The plan switches on when it is paid." It is visible to `manageManagedPlan`. Mail copy keys go under `mail.managed_plan_invoice.*`: subject "Your :plan invoice from Relaticle", one line with the amount, and a button "View and pay the invoice".

- [ ] **Step 1: Write the failing tests** in `ManagedPlanActionsTest.php`. Use the `WorkspaceResourceTest` setup: a sysadmin acting on the `sysadmin` guard, the current panel set, and `StripeRecorder::install()` with canned responses for `/v1/customers`, `/v1/subscriptions`, `/v1/invoices/{id}/send` (with a `hosted_invoice_url`) and the customer retrieve (`balance`).
  - "starts a Concierge subscription billed by invoice at the configured yearly price". Call `startManagedPlan` with `plan: concierge`. Assert the `/v1/subscriptions` params: `collection_method` `send_invoice`, `days_until_due` 30, `payment_behavior` `allow_incomplete`, `items.0.price_data.unit_amount` 70800, `items.0.price_data.product` the configured product, `items.0.price_data.recurring.interval` `year`, `metadata.workspace_id` the workspace key. Assert a `/send` request was made. Assert the local row's `type` is `managed_pending`, and that `workspace->plan` is unchanged.
  - "invoices Enterprise at the amount the sysadmin types". The amount is 32000, so `unit_amount` is 3200000.
  - "keeps a paused workspace paused while the managed invoice is unpaid". `resolve(HostedWorkspaceAccess::class)->allows($workspace)` is false after the action.
  - "keeps a running trial when the managed invoice is sent". `trial_ends_at` is unchanged.
  - "mails the billing contact the hosted invoice link". `Mail::fake()`. `ManagedPlanInvoiceMail` is queued to the typed address and its rendered body contains the hosted URL. Nothing is queued when the field is empty.
  - "refuses while another managed invoice is unpaid", "refuses a customer with a Stripe balance" and "refuses a plan with no Stripe product configured". Each gets `assertHasActionErrors(['plan'])` and no `/v1/subscriptions` request.
  - "hides the action from a sysadmin who cannot manage customer access". Use the role the `ExtendTrial` tests use for that negative.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/SystemAdmin/ManagedPlanActionsTest.php`. Expect FAIL (no action).
- [ ] **Step 3: Implement** the policy ability, the action, the Filament support class, the mail and its lang keys. Register `ManagedPlan::startAction()` beside `ExtendTrial::action()` in both places.
- [ ] **Step 4: Run** the file again. Expect PASS. Then run `tests/Feature/SystemAdmin/WorkspaceResourceTest.php`. Expect PASS, with no regressions in its action lists.
- [ ] **Step 5: Sandbox check, before Task 3 builds on it.** In tinker against `acct_1ThugED…`, run `StartManagedPlan` on a throwaway factory workspace.
  - Confirm the Stripe subscription is `active`, the first invoice is `open`, `hosted_invoice_url` is set, and the local row is `managed_pending`.
  - Cancel the subscription, delete the customer and the workspace.
  - Record the output in `.context/managed-billing/sandbox.md`.
  - If the status is `incomplete`, stop and report. Decision 5 is then wrong.
- [ ] **Step 6: Commit** `feat(sysadmin): start a managed plan billed by yearly invoice`.

### Task 3: Activate on the paid invoice, with the switch refund

**Files:**
- Create: `app/Actions/Billing/ActivateManagedPlan.php`, `app/Actions/Billing/CancelWithUnusedTimeRefund.php`, `app/Actions/Billing/RememberManagedRenewal.php`
- Modify: `app/Http/Controllers/Billing/StripeWebhookController.php` (adds `handleInvoicePaid`)
- Test: `tests/Feature/Billing/StripeWebhookTest.php`, with a helper `invoicePaidEvent(Workspace $workspace, array $overrides = []): array` next to `invoicePaymentFailedEvent`

**Interfaces:**
- Consumes: Task 1. Task 2's row shape: type `managed_pending`, Stripe metadata `workspace_id`.
- Produces:
  - `StripeWebhookController::handleInvoicePaid(array $payload): Response`
  - `ActivateManagedPlan::execute(Workspace $workspace, string $stripeSubscriptionId, string $invoiceId): void`
  - `CancelWithUnusedTimeRefund::execute(Subscription $subscription, string $idempotencyKey, CarbonImmutable $asOf): int`. It returns the cents refunded, 0 when nothing was charged. `$asOf` is the managed invoice's paid time, and the action appends `-{proInvoiceId}` to the key.
  - `RememberManagedRenewal::execute(array $stripeSubscription): void`, with `public const string CACHE_PREFIX = 'managed-renewal:'`. The value is `['renews_at' => int timestamp, 'amount' => int cents, 'currency' => string]`, read from `items.data.0.current_period_end` and `items.data.0.price.unit_amount`. It is stored forever and keyed by the Stripe subscription id.

How the pieces behave:
- **`handleInvoicePaid`** acts only when all of these hold:
  - `billing_reason` is `subscription_create`,
  - `parent.subscription_details.subscription` is a string,
  - the local row with that `stripe_id` has type `managed_pending`, or `managed` for a retry that finishes the switch,
  - and its workspace's `stripe_id` equals the invoice `customer`.

  Any other invoice returns `successMethod()`. A customer mismatch is also logged as a warning, mirroring `fulfillCreditPackCheckout()`.
- **`ActivateManagedPlan`** retrieves the subscription from Stripe for its product and metadata. Its plan is `Plan::fromStripeProduct(product)`, and it refuses when the metadata `workspace_id` differs.
  1. Refuse unless the Stripe status is `active`, `past_due` or `trialing`. A paid invoice on a cancelled subscription grants nothing, is reported, and returns 200.
  2. In one transaction with the workspace row locked: set the row's type to `managed`, set `workspace->plan`, and `CreditService::resetPeriod($workspace)`. Then set the Stripe metadata `type` to `managed`.
  3. For the workspace's valid `default` subscription and every other valid `managed` subscription created at or before the invoice's `paid_at`, call `CancelWithUnusedTimeRefund` with key `"managed-switch-{$invoiceId}-{$replacedStripeId}"`.
  4. Finally, call `RememberManagedRenewal` with the retrieved object.

  A row already of type `managed` skips step 2 and retries step 3 while the row is still valid. A completed switch makes no Stripe write on redelivery.
  A refund Stripe rejects for good (an invoice with no card payment, an already refunded charge, no subscription period) is reported and skipped, and the cancel still runs. A transient Stripe error returns 500 so Stripe retries.
- **`CancelWithUnusedTimeRefund`** finds the latest paid invoice of that subscription (`invoices->all(['subscription' => id, 'status' => 'paid', 'limit' => 1])`) and its payment intent (`invoicePayments->all(['invoice' => id])`).
  - The refund is `floor(amount_paid * (period_end - now) / (period_end - period_start))`, clamped to 0..amount_paid. It is skipped at 0.
  - It calls `refunds->create([...], ['idempotency_key' => $key])`, then `$subscription->noProrate()->cancelNow()`.

- [ ] **Step 1: Write the failing tests** in `StripeWebhookTest.php`. Use canned `StripeRecorder` responses for `/v1/subscriptions/{id}` (product = the configured Concierge product, metadata `workspace_id`), `/v1/invoices` (a paid Pro invoice: `amount_paid` 2400, a period with half its time remaining), `/v1/invoice_payments` and `/v1/refunds`.
  - "switches a managed plan on when its first invoice is paid". The row type is `managed`, the plan is Concierge, and `credits_remaining` is 5000.
  - "cancels Pro and refunds its unused time when the managed invoice is paid". The `/v1/refunds` params have `amount` 1200 and the Pro intent. A `/v1/subscriptions/{pro}` cancel was requested with `prorate` false. The local Pro row is canceled. The plan is Concierge.
  - "switches a Pro trial with no refund" (`amount_paid` 0). There is no `/v1/refunds` request, Pro is cancelled and the plan is granted.
  - "replaces an older managed subscription without ending the plan". The workspace holds a paid Concierge `managed` row and pays an Enterprise pending invoice. The old row is refunded and canceled, the plan is Enterprise, and no `ProEndedMail` is queued.
  - "grants and refunds once when Stripe redelivers the paid invoice". Send the same event twice. Exactly one `/v1/refunds` request is made, and `resetPeriod` ran once: credits used before stay reset only once (assert one `AiCreditTransaction` reset row).
  - "ignores a paid invoice that does not belong to a pending managed subscription". Use a first Pro invoice (`subscription_create` on a `default` row), a `subscription_cycle` Pro renewal and a `managed` row's renewal. The plan is unchanged and no Stripe request is made.
  - "ignores a paid invoice whose customer is not the workspace's". Nothing is granted and a warning is logged.
  - "fails the paid webhook without granting when the refund call fails". `respondTo('/v1/refunds', ...)` with an error status, using the existing failed-fetch test pattern. `withoutExceptionHandling()` throws, and the row is still `managed_pending`.
  - "remembers the renewal date and amount for the billing page". `Cache::get(RememberManagedRenewal::CACHE_PREFIX.$id)` equals the canned period end and 70800.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Billing/StripeWebhookTest.php --filter='invoice is paid|redelivers|does not belong|not the workspace|refund call fails|Pro trial|older managed|renewal date'`. Expect FAIL.
- [ ] **Step 3: Implement** the three actions and the handler. If `StripeRecorder` cannot return an error status, give `respondTo()` an optional `int $status = 200`.
- [ ] **Step 4: Run** the filter, then the whole `StripeWebhookTest.php`. Expect PASS.
- [ ] **Step 5: Commit** `feat(billing): switch a managed plan on when its invoice is paid`.

### Task 4: End only what was granted

**Files:**
- Modify: `app/Actions/Billing/SyncWorkspacePlanFromSubscription.php`, `app/Mail/ProEndedMail.php`, `resources/views/mail/pro-ended.blade.php` (only if the heading needs the plan), `lang/en/mail.php`
- Test: `tests/Feature/Billing/StripeWebhookTest.php`

**Interfaces:**
- Consumes: Task 1 (`SubscriptionType`, `Plan::fromStripeProduct`). Task 3 (the `managed` and `managed_pending` rows).
- Produces: `ProEndedMail::afterManagedPlan(Workspace $workspace, Plan $plan): self`. It uses keys `mail.pro_ended.managed_ended.subject` ("Your :plan plan has ended") and `.heading` (":plan for :workspace has ended").

Rules added to `execute()` and `targetPlan()`:
- Resolve the subscription's plan by price first, then by its first item's `stripe_product` through `Plan::fromStripeProduct`.
- A `managed_pending` row returns null.
- A valid `managed` row returns null. `ActivateManagedPlan` grants.
- An ended `managed` row returns null while another valid `managed` row exists for the workspace.
- Otherwise the existing "plan equals the subscription's plan, so return `Plan::default()`" path runs, and it queues `afterManagedPlan` instead of `afterSubscription` when that plan is managed.

- [ ] **Step 1: Write the failing tests:**
  - "drops a lapsed Concierge workspace to Free and tells the owner". A paid `managed` row receives `customer.subscription.deleted`. The plan is Free, `HostedWorkspaceAccess` is denied, and `ProEndedMail` is queued with subject "Your Cloud Concierge plan has ended". Use the `Plan` label the copy uses. Confirm `Plan::Concierge->getLabel()` versus `billing.plans.cloud_concierge`, and pick `billing.plans.cloud_concierge` for user copy.
  - "keeps a past-due Concierge workspace on its plan". `customer.subscription.updated` with `past_due` leaves the plan Concierge and allows access.
  - "leaves a hand-granted plan alone when an unpaid managed invoice is cancelled". The workspace is on Enterprise by hand, and a `managed_pending` row is deleted. The plan stays Enterprise and no mail is queued.
  - "does not end the plan when a replaced managed subscription is deleted". It follows Task 3's replacement. A late `deleted` for the old row leaves Enterprise and queues no mail.
- [ ] **Step 2: Run** those tests. Expect FAIL.
- [ ] **Step 3: Implement** the rules and the mail variant.
- [ ] **Step 4: Run** `tests/Feature/Billing`, in parallel: `php artisan test --compact tests/Feature/Billing --parallel --processes=4`. Expect PASS.
- [ ] **Step 5: Commit** `feat(billing): end a managed plan only when its paid subscription ends`.

### Task 5: Withdraw an unpaid managed invoice

**Files:**
- Create: `packages/SystemAdmin/src/Actions/WithdrawManagedInvoice.php`
- Modify: `packages/SystemAdmin/src/Filament/Support/ManagedPlan.php`, `WorkspaceResource.php`, `ViewWorkspace.php`
- Test: `tests/Feature/SystemAdmin/ManagedPlanActionsTest.php`

**Interfaces:**
- Consumes: Task 2 (`manageManagedPlan`, the pending row).
- Produces:
  - `WithdrawManagedInvoice::execute(SystemAdministrator $admin, Workspace $workspace): void`. It finds the valid `managed_pending` row and calls `stripe()->invoices->voidInvoice()` on its latest open invoice (retrieved from the subscription's `latest_invoice`). Then it calls `cancelNow()`, and throws a `ValidationException` when no pending row exists.
  - `ManagedPlan::withdrawAction()` (name `withdrawManagedInvoice`). It is visible only while a valid `managed_pending` row exists. Its modal reads "Voids the unpaid invoice and cancels the subscription. The workspace's plan does not change."

- [ ] **Step 1: Write the failing tests:**
  - "voids the unpaid invoice and cancels its subscription". A `/void` request is made, a cancel request is made, the row is canceled and the plan is unchanged.
  - "hides withdraw when no managed invoice is waiting".
  - "lets the sysadmin start again after withdrawing". Start then succeeds without the pending-refusal error.
- [ ] **Step 2: Run** them. Expect FAIL.
- [ ] **Step 3: Implement** the action and register it next to the start action.
- [ ] **Step 4: Run** `ManagedPlanActionsTest.php`. Expect PASS.
- [ ] **Step 5: Commit** `feat(sysadmin): withdraw an unpaid managed invoice`.

### Task 6: What the customer sees

**Files:**
- Create: `app/Notifications/ManagedInvoiceOverdueNotification.php`
- Modify: `app/Http/Controllers/Billing/StripeWebhookController.php` (`handleCustomerSubscriptionUpdated`), `app/Filament/Pages/Billing.php` (`getViewData`, `managePortal`), `resources/views/filament/pages/billing.blade.php`, `lang/en/billing.php`, `packages/Documentation/resources/content/help/workspace/billing-and-plans.md`
- Test: `tests/Feature/Billing/BillingPageTest.php`, `tests/Feature/Billing/StripeWebhookTest.php`

**Interfaces:**
- Consumes: Task 3 (`RememberManagedRenewal::CACHE_PREFIX`, `managed` rows).
- Produces:
  - Billing view data `managedSubscription` (`?Subscription`, `$workspace->subscription('managed')` when the plan is managed), `managedPastDue` (bool) and `renewal` (`?array` from the cache).
  - `ManagedInvoiceOverdueNotification extends InboxNotification`, with reason `System`, `groupKey` `"managed-overdue:{$workspaceId}"`, `visibleInEveryWorkspace` true, facts `['workspace_id', 'plan']`, and a `present()` that links to the billing page.

Behaviour:
- **`managePortal`** passes `['configuration' => config('services.stripe.portal_configurations.managed')]` when the plan is managed and that config is set.
- **The managed card** gets three changes:
  - It shows "Renews :date · $:amount" when `renewal` is set.
  - It shows a "Manage billing" button (portal) when `managedSubscription` exists.
  - A danger banner appears when `managedPastDue`: title "Your :plan invoice is overdue", body "Pay it from :button to keep the plan. If it stays unpaid, Stripe cancels the plan." The button label is `billing.manage.button`. Stripe's cancel delay is a Dashboard setting, so the copy names no number.
- **`handleCustomerSubscriptionUpdated`** acts after the parent handler. On a transition (`data.previous_attributes.status` present and not `past_due`, fresh status `past_due`) for a local `managed` row whose plan equals `workspace->plan`, it notifies the owner once. It also calls `RememberManagedRenewal` for `managed` rows.
- **The help article's Concierge section** adds one sentence: "Stripe sends the invoice each year. If a renewal stays unpaid, the workspace returns to Free." The em-dash rule applies, and there is no bare `: ` in front matter.

- [ ] **Step 1: Write the failing tests:**
  - `BillingPageTest`:
    - "shows the managed renewal date and amount". Seed the cache, and the page shows "Renews" with "$708".
    - "omits the renewal line when nothing is cached".
    - "warns the owner when the managed invoice is overdue". A `managed` row has `stripe_status` `past_due`.
    - "opens the Stripe portal with the managed configuration". `StripeRecorder` `paramsFor('/billing_portal/sessions')['configuration']` equals the config value.
    - "shows a member the managed plan without billing controls".
  - `StripeWebhookTest`:
    - "tells the owner once when a managed invoice becomes overdue". Two updated events with the transition produce one bell row.
    - "stays quiet about an overdue invoice for a plan the workspace does not hold". The row is `managed_pending`, or the plan differs.
- [ ] **Step 2: Run** them. Expect FAIL.
- [ ] **Step 3: Implement** the view data, the view changes, the notification, the webhook addition, the copy and the help sentence.
- [ ] **Step 4: Run** `tests/Feature/Billing` in parallel, plus `tests/Feature/Documentation --parallel --processes=4`. Expect PASS.
- [ ] **Step 5: Commit** `feat(billing): show managed renewal, overdue state and portal`.

### Task 7: Gates

- [ ] **Step 1:** Run `composer test:lint`, `composer test:refactor`, `composer test:types`, then `php -d memory_limit=2G vendor/bin/pest tests/Arch`. Expect all green. Fix at the layer that failed. Never add an ignore.
- [ ] **Step 2:** Run scoped suites in parallel: `tests/Feature/Billing`, `tests/Feature/SystemAdmin` and `tests/Feature/Chat --filter='(Credit|ModelGate|OutOfCredits)'`. Expect PASS.
- [ ] **Step 3: Commit** any fixes. Push, open the PR against `main`, and watch the `Tests` workflow as a background task.

### Task 8: Sandbox loop and browser walk

Not code. This is the proof the spec owes. Record everything in `.context/managed-billing/`.

- [ ] **Step 1: Sandbox loop.** Run it on `acct_1ThugED…` with `stripe listen` forwarding to `https://toronto.test/stripe/webhook`. Run `stripe login` for the sandbox first: the profile warns the default login forwards production events.
  1. A throwaway workspace buys Pro monthly through Checkout with `STRIPE_MANAGED_PAYMENTS=true` in this checkout's `.env`. Restore the value afterwards.
  2. The sysadmin starts Concierge with a billing email. The invoice reaches Herd's mail catcher, and the hosted link opens.
  3. Pay the invoice on the hosted page with the 4242 card.
  4. Check that the plan is Concierge, Pro is canceled, and the refund exists in Stripe with the expected amount.
  5. Start Enterprise for $1 and pay it. Check that Concierge is replaced with no "plan ended" mail.
  6. Withdraw a second unpaid invoice.
  7. Cancel the Enterprise subscription in Stripe. Check that the workspace drops to Free and the managed-ended mail arrives.
  8. Tear down every Stripe object and local row.
- [ ] **Step 2: Browser walk** in Chromium, then WebKit via the profile's Playwright check:
  - the sysadmin start and withdraw modals,
  - the owner's billing page before payment, after payment (renewal line), and past due (banner),
  - the member view,
  - and the portal opening, with its cancel option absent.
- [ ] **Step 3:** Write the PR body to the global PR rules, including the decisions above and the deploy steps:
  - Live products, the portal configuration and their env vars.
  - `invoice.paid` added to the live webhook endpoint (`cashier:webhook` only provisions new endpoints).
  - The account setting to cancel 30 days past due.
  - Stripe Tax on invoices.
