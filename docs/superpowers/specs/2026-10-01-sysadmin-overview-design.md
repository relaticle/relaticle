# SysAdmin Overview: one page that answers four questions

Date: 2026-10-01. Branch: `ManukMinasyan/registered-user-activation-workflow`. Ships as one PR.

Status: built. The PR adds no migrations, so recorded AI cost, provider budgets, the provider
cost sync, setup exit reasons and wizard step tracking were cut. Each needs a schema change.

## Problem

The SystemAdmin panel has two dashboards and ten widgets, yet it cannot answer the questions a
founder asks every week:

- **Money.** There is no MRR, so nothing says what customers actually pay each month.
- **Value.** The activation rate counts one own record, but most workspaces that create one never
  come back. Nothing shows repeat use or cohort retention, which every early-stage metrics source
  ranks first.
- **Sales.** No list says which non-paying workspaces use the product for real.
- **Problems.** Trial farming (signups using the Pro trial's premium models as a free chatbot)
  looks like growth in every signup and activation number, and there is no way to see or stop it.

## Goal

One SystemAdmin page, **Overview**, with four stacked rows. Each row is a plain question with one
to four numbers. Every number states what it counts, turns green, amber or red by a stated rule,
and opens the list behind it. Suspected abuse and internal workspaces are left out of every number
except the abuse row.

Alongside it: premium models stay locked during a Pro trial until the workspace adds its own
data, and title, suggestion and cancelled calls land on the credit ledger with their real model
and tokens.

## Decisions

1. **One page replaces two dashboards.** `Overview` becomes the panel's home page. The old
   `Dashboard` and `EngagementDashboard`, their ten widgets and those widgets' tests are deleted
   in this PR, after `Overview` exists. Rejected: adding widgets to the existing dashboards (the
   reason they fail today is that nothing on them is a sentence).
2. **No migrations in this PR.** Everything ships on the existing schema. Rejected: per-call
   cost columns, a provider cost table, budget and cache-price settings, and exit-reason and
   wizard-step columns. The features that needed them are cut, not deferred.
3. **"End trial now" sets `trial_ends_at` to now.** `BillingStatus` then reads `TrialEnded` and
   `HostedWorkspaceAccess` pauses the workspace at once: the app, chat, API and MCP stop, and the
   paused screen offers the plan choice and checkout. The nightly `billing:process-trials` run
   moves the plan to Free, resets credits and sends the standard "trial ended" email. This is
   the same pay-to-unlock gate every ended trial gets. Rejected: an immediate full downgrade
   (duplicates the nightly logic), locking premium models only (too soft for a flagged
   workspace), blocking the owner's account (they could never reach the pay screen, and a wrong
   flag would lock a genuine buyer out of every workspace).
4. **The premium lock has one owner: `Relaticle\Chat\Services\ModelAccess`** (built).
5. **The own-data predicate becomes a scope.** `HasCreator` gains `#[Scope] ownData()`
   (`creation_source` is not `system`). Metrics use it on the five CRM models.
   `WorkspaceActivationFacts` keeps its single-query union for speed.
6. **Weeks are UTC, starting Monday**, matching the analytics clone.
7. **A workspace is internal when its owner's email matches a SysAdmin's email.** No setup:
   on production today this catches all six of the founder's workspaces, including the paying
   one. It matches the owner, never a member, so a customer workspace a SysAdmin joins to help
   is never hidden. Rejected: a per-workspace switch (new test workspaces pollute the numbers
   until flipped), an ID list in `.env` (needs a deploy per change).
8. **MRR is what each customer actually paid.** For each active subscription on a non-internal
   workspace, MRR uses the latest invoice's `total_excluding_tax`, divided by the price's billing
   period in months. Stripe is read once per subscription per day (cached). Rejected: list
   prices (wrong under the 50%-off promo), amounts in config (a second copy of a Stripe fact).
9. **Cancelled turns record their real usage.** When the user presses Stop, the stream still runs
   to the end, so its usage is known. The ledger records the resolved model and its tokens; the
   user is still charged the one-credit minimum. A turn that dies from a provider error has no
   usage and stays `incomplete`. Rejected: charging full credits for a stopped answer, really
   stopping generation (a separate streaming change).
10. **Every click opens an existing SysAdmin resource, already filtered.** Filament 5 list pages
    bind filters and sort to the URL (`?filters[...]`, `?sort=`), so tiles link to the Users,
    Workspaces, AI credit balances, Subscriptions and Chat feedback lists with a filter applied.
    Rejected: new drill-down pages (more surface for the same table).
11. **Predicates that span tables are `Scope` classes.** `tests/Arch/ConventionsTest.php` allows a
    public method to take a query builder only on a model, an enum, or a class implementing
    `Illuminate\Database\Eloquent\Scope`. SystemAdmin may not add scopes to `App\Models` that
    read SystemAdmin data, so each shared predicate is a `Scope` class in
    `Relaticle\SystemAdmin\Metrics\Scopes`, applied with `->withGlobalScope(Name::class, new Name)`
    on a query. Tile numbers and filters use the same class, so they cannot disagree.

## Shared definitions

| Term | Definition | Owner |
|---|---|---|
| Internal workspace | Its owner's canonical email equals a `system_administrators.email` (lower-cased) | `Scopes\InternalWorkspace` (and its inverse `Scopes\ExternalWorkspace`) |
| Own data | A company, person, opportunity, task or note whose `creation_source` is not `system` | `HasCreator::ownData()` scope |
| Active day | A day on which a user created own data or sent a typed chat message (`AgentConversationMessage::typed()`) | `Metrics\ActivityDays` |
| Trial farmer | A workspace that used its Pro trial (`pro_trial_used_at` set) with no own data, and either at least half its chat credits spent on models whose `min_plan` is above Free, or an owner timezone in `system-admin.abuse_timezones`. Holds after the trial ends | `Scopes\TrialFarmer` |
| Abuse suspect | A `Trialing` trial farmer: the ones End trial now can still act on | `Scopes\AbuseSuspect` |
| Genuine signup | Verified; not an invited teammate (no membership in someone else's workspace within 24 hours of signing up); owns no trial-farmer and no internal workspace | `Scopes\GenuineSignup` |
| First value | A genuine signup who created own data within 7 days of signing up | `Scopes\ReachedFirstValue` |
| Habit | A non-internal workspace with an active day in at least 3 of the last 4 complete weeks | `Scopes\FormedHabit` |
| Stuck after setup | A genuine owner's personal workspace, 3 to 30 days old, with no own data and no typed chat message in its first 3 days | `Scopes\StuckAfterSetup` |

The organic rule moves from `FunnelWidget::countOrganicSignups()` into `Scopes\GenuineSignup`
before the widget is deleted. `system-admin.abuse_timezones` is a package config list
(`packages/SystemAdmin/config/system-admin.php`, merged by the panel provider) of IANA zones,
defaulting to Iran's and Russia's, overridable with `SYSTEM_ADMIN_ABUSE_TIMEZONES`.

Known gap in the abuse rule: with the premium lock live, a new farmer can no longer spend on
premium models without own data, so the premium arm stops firing for new trials and the rule
rests on the timezone arm. A farmer elsewhere chatting off-topic on a free model is not caught,
and credit volume cannot separate them (on the production snapshot both groups spent under 20
credits). Detecting off-topic chat is out of scope.

## The page

`Relaticle\SystemAdmin\Filament\Pages\Overview` extends Filament's dashboard page, is the panel's
home, takes one column, and holds five lazy widgets in this order: `MoneyStats`, `ValueStats`,
`CohortTable`, `SalesLeads`, `ProblemsStats`. The stats widgets are `StatsOverviewWidget`s whose
heading is the row's question; each `Stat` carries its value, a one-line description (delta and
short context), its color, a hover tooltip with the definition, and a `url()` to the filtered
list. Numbers are cached for ten minutes under a shared version key; a **Refresh** header action
bumps the version. A number with no data shows the empty-value placeholder used elsewhere in
SystemAdmin and a one-line reason.

### Row 1: Are we making money? (`MoneyStats`)

| Tile | Value | Color rule | Opens |
|---|---|---|---|
| MRR | Sum over active subscriptions (Cashier `active()`, past due included) on external workspaces of the latest invoice's `total_excluding_tax`, divided by the billing period in months. Delta vs one week earlier (subscriptions active at that time, same amounts) | Neutral; grey when Stripe is unavailable | Subscriptions list, filter "Counts toward MRR" |

With the Billing feature off (self-hosted), the row is hidden.

### Row 2: Is anyone getting value? (`ValueStats`, `CohortTable`)

| Tile | Value | Color rule | Opens |
|---|---|---|---|
| Real signups | Genuine signups in the latest complete week at least 7 days old. Delta vs the week before | Neutral, with arrow | Users list, filters "Genuine signup" and that week |
| Reached first value | First-value share of that same week | Red under 20%, amber under 30%, green from 30% | Same list plus filter "Reached first value" |
| Formed a habit | Habit workspaces now. Delta vs one week earlier | Green up, amber flat, red down | Workspaces list, filter "Formed a habit" |

`CohortTable` renders the last six signup weeks (genuine signups): size, then the share with an
active day in the signup week and each of the next three weeks. A week that has not happened yet
shows a dot, never 0%.

### Row 3: Who should I talk to next? (`SalesLeads`)

A `TableWidget` of external workspaces with own data and no paid or Enterprise plan, ten a page,
split by stage (`Relaticle\SystemAdmin\Enums\LeadStage`). Stage buttons above the table show
each count; the list opens on Trialing. A trial ends inside 14 days, so ranking trials by active
days in 30 buried the ones about to end. Ended trials with no own data are left out. Outreach is
tracked in the Relaticle HQ workspace, so the panel stores no contact state.

| Stage | Billing statuses | Order | Stage column |
|---|---|---|---|
| Trialing | Trialing | Trial end, soonest first | "N days left" |
| Trial ended | Trial ended | Trial end, most recent first (`trial_ends_at`, or `pro_trial_used_at` plus 14 days once the nightly downgrade clears it) | "Ended N days ago" |
| Free | Free, grandfathered, granted, subscription ended | Active days in 30, then own records | Billing status label |

| Column | Content |
|---|---|
| Workspace | Name, linking to the SysAdmin workspace view |
| Stage | See above |
| Why | Own records (marked imported when any were), active days (30d), chat credits used, teammates, and "uses API" or "uses MCP" when present |
| Last active | Latest active day |
| Actions | **Open as user** (`Impersonate::workspaceOwner()`), **Email owner** (`mailto:`) |

The workspace view gains a **Journey** section: signup date and method (password or the Socialite
provider), first own record date, active days (30d), typed chat messages, credits used this
period, and whether the workspace is internal.

### Row 4: What's going wrong? (`ProblemsStats`)

| Tile | Value | Color rule | Opens |
|---|---|---|---|
| Trial abuse suspects | Count of abuse suspects | Red when any suspect spent credits in the last 7 days, green at zero | Workspaces list, filter "Abuse suspect"; **End trial now** is a row action, a bulk action and a view-page action there |
| Stuck after setup | Stuck share of genuine owners whose workspace is 3 to 30 days old | Red from 60%, amber from 40% | Workspaces list, filter "Stuck after setup" |
| Left the setup wizard | Share of genuine verified signups of the last 30 days with no workspace, split by password and Socialite provider | Amber from 10% | Users list, filters "Genuine signup", "No workspace" and "Signed up" covering the last 30 days, with the signup-method column shown |
| Thumbs down this week | `chat_message_feedback` rated down this week | Green 0, amber 1 to 2, red from 3 | Chat feedback list, `?filters[rating][value]=down` |

With the Billing feature off, the abuse tile is hidden.

## Resource additions (the filtered lists)

| Resource | Adds |
|---|---|
| Users | Filters: Genuine signup, Signed up in week (date range), Reached first value, No workspace. Column: Signup method |
| Workspaces | Filters: Internal, Abuse suspect, Formed a habit, Stuck after setup. Actions: End trial now (row, bulk, view page) |
| AI credit balances | Filter: Trialing |
| Subscriptions | Filter: Counts toward MRR (active, external workspace) |

## Product changes

### Premium lock during the trial (built)

`ChatController` answers `model_not_allowed` with "Add your own records to unlock premium models
during your trial." for a model the workspace's own plan allows; the picker shows the same
sentence from the same snapshot as the gate; the lock lifts on the first own record.

### Ledger rows for internal and cancelled calls

- Titles and suggestions write `AiCreditType::Internal` rows with zero credits through
  `CreditService::recordInternalUsage()`, naming the requested cheapest model, wrapped in
  `rescue()`.
- `CreditService::settleReservedMinimum()` takes an optional model and `TextUsage`. The cancel
  branch of `ProcessChatMessage` passes the resolved model and `$response->usage`; the row then
  names that model and carries its tokens, with `credits_charged` still the reserved minimum and
  `metadata.reason` still `cancelled`. The provider-error branch passes neither and stays
  `incomplete`.

## Schema

None. `system-admin.abuse_timezones` is the only new config.

## Error handling

- Stripe unreachable: the MRR tile shows the placeholder with "Stripe unavailable"; the rest of
  the row renders. A subscription with no latest invoice counts as zero.
- "End trial now" on a workspace that is not trialing: the action is hidden, and the action class
  re-checks and refuses.

## Build order (one PR)

1. Premium lock, internal and cancelled ledger rows.
2. Shared predicates: `ownData()`, the `Scopes` classes, `Metrics\ActivityDays`.
3. Money row: `Metrics\Revenue` (Stripe), `MoneyStats`, the Overview page as home.
4. Problems row: abuse filter, End trial now, `ProblemsStats`.
5. Sales row: `SalesLeads`, the Journey section.
6. Value row: `ValueStats`, `CohortTable`, the Users filters.
7. Delete the old dashboards, their ten widgets and tests; full gates; browser walk.

## Testing

All through real entry points, per `.ai/rules/relaticle/testing`. Never set `chat.models` before a
test's first database touch (it seeds that catalog into the test database).

- `tests/Feature/SystemAdmin/Overview/*Test.php`, one file per row: each tile's value, color and
  link against factory data that includes one of each trap (an invited teammate, an internal
  workspace owned by a SysAdmin's email, an abuse suspect, sample data only, a week not yet
  complete).
- Each new resource filter returns exactly the rows its tile counts (same `Scope` class).
- MRR with Stripe faked through `Stripe\ApiRequestor::setHttpClient()` (the pattern in
  `SubscriptionTransferActionTest`): a yearly and a monthly subscription, a 50%-off invoice, an
  internal workspace excluded, Stripe failing.
- Cancelled turn: the row names the resolved model with its tokens and one credit charged; the
  provider-error path stays `incomplete`.
- End trial now: the workspace reads `TrialEnded` and is paused immediately; hidden and refused
  for a paying workspace.
- Architecture: the new classes pass `ArchTest` and `ConventionsTest`.
- Browser: walk the Overview in agent-browser, light and dark, with data and with an empty
  database; click every tile through to its filtered list; run End trial now on a test workspace.

## Out of scope

- Fixing the onboarding drop-offs. A separate investigation prompt lives at
  `.context/onboarding-investigation-prompt.md`.
- Recorded AI cost, provider budgets and billed-cost sync, setup exit reasons, and wizard step
  tracking. Each needs a migration.
- Region blocking (a legal question), card-required trials, daily credit drip, off-topic chat
  detection, really stopping generation on Stop.
- A PMF survey, session recordings, a weekly email digest, revenue beyond MRR, runway.
- Updating the analytics toolkit's `metrics.md`, which names `FunnelWidget` as the owner of the
  organic rule; it follows this PR.
