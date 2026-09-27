# Billing status: one owner for workspace billing state

Date: 2026-09-23. Branch: `feat/trial-end-paywall` (PR #835).

## Problem

Five surfaces answer "what billing state is this workspace in", each with its own rules:

| Surface | Where | How it decides "ended" |
|---|---|---|
| Sysadmin badge | `BillingStatus::fromWorkspace()` | `trial_ends_at !== null` |
| Access gate | `HostedWorkspaceAccess::allows()` | Its own ordering of the same checks |
| Sidebar prompt | `SidebarBillingState::for()` | A third ordering, then defers to the gate |
| Paused screen | `Billing::pausedViewData()` | Canceled or unpaid subscription, else `pro_trial_used_at` |
| Ended email | `ProEndedMail::$cause` | Free strings `'trial'` and `'subscription'` |

They drift. Reproduced on this branch: an expired trial reads `trial_ended` until the nightly
`billing:process-trials` run, which nulls `trial_ends_at`, and then reads `free`. The paused
screen still says "Your Pro trial has ended". No surface but the paused screen can name a
cancelled subscription.

## Goal

`BillingStatus` is the single owner of a workspace's billing state and of every fact that is a
pure function of that state. Every other surface reads the enum. The state survives the nightly
job, and a trial that ended, a subscription that ended, and a workspace that never started stay
distinct forever.

Access behaviour does not change. Every workspace that can reach the app today still can, and
every paused workspace stays paused.

## Decisions

1. **Owner.** `BillingStatus` derives the state and answers what each state allows
   (`grantsAccess()`). `HostedWorkspaceAccess` keeps only the billing feature-flag layer.
   Rejected: a new `WorkspaceBillingState` value object (a new pattern for no gain) and moving
   the rules into `HostedWorkspaceAccess` (a bool cannot carry the reason).
2. **Durable trial marker.** "Trial ended" after the nightly job reads `pro_trial_used_at` with
   `plan = Free`. No schema change. Rejected: keeping `trial_ends_at` after expiry (breaks the
   once-only email, which relies on the job clearing it) and a new `trial_ended_at` column (one
   more writer to keep in step).
3. **Vocabulary.** The enum value is the lang-key suffix for the paused heading and the email.
   `ProEndedMail` stores a `BillingStatus` case instead of a string.
4. **Never-granting Stripe statuses** (`incomplete`, `incomplete_expired`) get one owner on
   `StripeSubscriptionStatus`. Today the list is copied in `SyncWorkspacePlanFromSubscription`
   and `StripeWebhookController`; the new case would have made a third copy.

## Status precedence

`fromWorkspace()` returns the first case whose predicate holds, in declaration order.
`applyToQuery()` already reproduces that order from the case list.

| # | Case | Predicate (earlier cases already excluded) | Grants access |
|---|---|---|---|
| 1 | PastDue | latest default subscription is `past_due` | yes |
| 2 | Subscribed | latest default subscription is `valid()` | yes |
| 3 | Enterprise | `plan = enterprise` | yes |
| 4 | Trialing | generic trial running | yes |
| 5 | Grandfathered | `hosted_free_grandfathered_at` set | yes |
| 6 | SubscriptionEnded (new) | `plan = free` and any subscription whose status is not never-granting | no |
| 7 | TrialEnded | `trial_ends_at` set, or `plan = free` and `pro_trial_used_at` set | no |
| 8 | Granted | `plan != free` | yes |
| 9 | Free | everything else | no |

Why each clause:

- Row 7's first clause covers the window between trial expiry and the nightly job, when `plan`
  is still `pro`. It must rank above Granted or that window would grant access.
- The `plan = free` conditions on rows 6 and 7 let a later sysadmin Pro grant read Granted.
- Row 6 ranks above row 7, so a workspace that trialled, subscribed, then cancelled reads
  SubscriptionEnded.
- An abandoned checkout (`incomplete_expired`) is never-granting, so a trial that ended with one
  reads TrialEnded, and a workspace that never trialled reads Free.
- Row 6 reads every subscription, not only the latest. A cancelled subscription followed by an
  abandoned checkout still reads SubscriptionEnded, which is what the paused screen shows today.

Worked cases:

| Workspace | Status |
|---|---|
| Trial expired, job not yet run (`plan = pro`, `trial_ends_at` past) | TrialEnded |
| Trial expired, job ran (`plan = free`, `pro_trial_used_at` set) | TrialEnded |
| Trialled, subscribed, cancelled | SubscriptionEnded |
| Cancelled subscriber later granted Pro by a sysadmin | Granted |
| Trial ended with an abandoned checkout | TrialEnded |
| Cancelled, then a later checkout abandoned | SubscriptionEnded |
| Never trialled, abandoned checkout | Free |
| Grandfathered, subscribed, cancelled | Grandfathered |

## Changes

**`app/Enums/StripeSubscriptionStatus.php`**
- `public static function neverGranted(): array` returns the `incomplete` and
  `incomplete_expired` values, typed `list<string>`.

**`app/Enums/BillingStatus.php`**
- New case `SubscriptionEnded = 'subscription_ended'`, declared between Grandfathered and
  TrialEnded, with label "Subscription ended", a description, and colour `danger`.
- `fromWorkspace()` and `constrain()` implement rows 6 and 7 above. Row 6 reads the
  `subscriptions` relation, not `latestDefaultSubscription`, because it asks about history.
- New `grantsAccess(): bool`, an exhaustive `match`.
- Class docblock: name it the owner that the other surfaces read.

**`app/Services/Billing/HostedWorkspaceAccess.php`**
- `allows()` becomes `! Feature::active(Billing::class) || $workspace->billingStatus()->grantsAccess()`.
- The multi-row callers must eager-load `subscriptions` (`AppServiceProvider` already does). The
  plan verifies every caller.

**`app/Services/Billing/SidebarBillingState.php`**
- `for()` matches on the status: PastDue gives the urgent fix prompt, Trialing gives days left,
  any status without access gives the paused prompt, and everything else gives `null`. The
  output is identical to today's.

**`app/Filament/Pages/Billing.php` and `resources/views/filament/pages/billing-paused.blade.php`**
- `pausedCause` is replaced by `billingStatus` (`$workspace->billingStatus()`). The heading reads
  `billing.paused.heading.{$billingStatus->value}`.

**`lang/en/billing.php`**
- `paused.heading` keys become `trial_ended`, `subscription_ended`, and `free`. Copy is unchanged.

**`app/Mail/ProEndedMail.php`, `lang/en/mail.php`**
- `public BillingStatus $status` replaces `public string $cause`. `afterTrial()` and
  `afterSubscription()` stay and pass TrialEnded and SubscriptionEnded.
- `pro_ended.trial` and `pro_ended.subscription` become `pro_ended.trial_ended` and
  `pro_ended.subscription_ended`. Copy is unchanged.
- The cause stays explicit, not derived at send time. A grandfathered workspace reads
  Grandfathered after its subscription ends and still receives its own email variant.

**`app/Actions/Billing/SyncWorkspacePlanFromSubscription.php`, `app/Http/Controllers/Billing/StripeWebhookController.php`**
- Drop the private `NON_GRANTING_STATUSES` constants. Read `StripeSubscriptionStatus::neverGranted()`.

**`docs/billing.md`**
- The hosted-access paragraph names `BillingStatus` as the owner and lists the three ended or
  unstarted states.

**`packages/SystemAdmin`** (excluded from PHPStan)
- Swept: no `match` over `BillingStatus` there. The workspace filter reads the enum cases, so it
  gains Subscription ended on its own.

## Testing

- `tests/Feature/SystemAdmin/WorkspaceResourceTest.php` `billingStatusArrangements()`: add the
  worked cases above. Re-key the filter test by arrangement name: it keys by status today, so a
  second arrangement for one status overwrites the first and never reaches the query side.
- `tests/Feature/Billing/TrialLifecycleTest.php`: after `billing:process-trials` pauses a trial,
  the workspace reads TrialEnded. This is the reproduced bug.
- Access regression: the existing gate, middleware, sidebar, paused-screen, MCP, and chat tests
  pass with assertions unchanged, apart from renamed lang keys.
- `ProEndedMailTest`, `BillingPageTest`, `StripeWebhookTest`, `TrialLifecycleTest`: update for
  the renamed keys and for `$mail->status`.
- Gates: pint, rector, phpstan, type coverage, targeted Pest, then the full suite once.
- Browser: walk the paused screen for a trial-ended and a subscription-ended workspace in light
  and dark, and check the sysadmin badge and filter.

## Out of scope

- An "effective plan" so a trial stops writing `plan = pro`. Seventeen files read `->plan`, which
  is too big for this PR. It would remove the nightly state flip and can come later.
- `Billing::trialAvailable()` keeps its own rule. Grandfathered workspaces keep the trial escape
  hatch on purpose, so it is not a pure function of the status.
- The concurrent-webhook duplicate email noted during review needs a row lock in
  `SyncWorkspacePlanFromSubscription`. It is a separate fix.
