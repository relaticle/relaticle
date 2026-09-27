# Billing Status Single Owner Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use sdd-lean (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `BillingStatus` the one owner of workspace billing state, so the sysadmin badge, access gate, sidebar, paused screen, and ended email can never disagree, and "Trial ended" survives the nightly job.

**Architecture:** `BillingStatus::fromWorkspace()` gains a `SubscriptionEnded` case and a durable `TrialEnded` predicate, plus `grantsAccess()`. `HostedWorkspaceAccess` shrinks to the billing feature flag plus `grantsAccess()`. The sidebar, the paused screen, and `ProEndedMail` read the enum instead of keeping their own rules and strings. Access behaviour does not change.

**Tech Stack:** PHP 8.5, Laravel, Cashier (Stripe), Filament v5, Livewire v4, Pest, PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-09-23-billing-status-single-owner-design.md`

## Global Constraints

- Branch `feat/trial-end-paywall` (PR #835). Conductor auto-pushes every commit.
- Access behaviour must not change: every workspace that reaches the app today still does, and every paused one stays paused.
- UI and email copy is unchanged. Only lang keys are renamed.
- Never write the em-dash character (U+2014) in code, comments, lang, docs, or commits. A hook rejects it.
- Comments state only a non-obvious why, at most 2 lines. Docblocks carry types only, except existing prose docblocks you edit in place. No comments in tests.
- Every closure and parameter is typed (type coverage must stay 100%). No new PHPStan ignores.
- `packages/SystemAdmin` is excluded from PHPStan: after adding an enum case, grep it for `match` over `BillingStatus`.
- Commits: conventional, lowercase, present tense, subject under 72 chars, no AI attribution.
- Iterate with targeted test files. Run the full suite once, in Task 5.

## Review Focus

1. A cancelled subscriber later granted Pro by a sysadmin keeps access and reads Granted. Pinned in Task 2 (dataset) and Task 3 (access test).
2. A grandfathered workspace whose subscription ended keeps access and reads Grandfathered. Pinned in Task 2 and Task 3.
3. A cancelled subscription followed by a newer abandoned checkout still reads SubscriptionEnded, not TrialEnded or Free. Pinned in Task 2.
4. A trial after the nightly downgrade (`plan = free`, `trial_ends_at` null) stays paused and reads TrialEnded. Pinned in Task 2 (job test) and Task 3 (access test).
5. A workspace whose subscription ended gets the sidebar's paused prompt, not silence. Pinned in Task 3.

---

### Task 1: One owner for never-granting Stripe statuses

**Files:**
- Modify: `app/Enums/StripeSubscriptionStatus.php`
- Modify: `app/Actions/Billing/SyncWorkspacePlanFromSubscription.php:19-25,83`
- Modify: `app/Http/Controllers/Billing/StripeWebhookController.php:17-23,55`
- Test: `tests/Feature/Billing/StripeWebhookTest.php` (existing, unchanged)

**Interfaces:**
- Produces: `StripeSubscriptionStatus::neverGranted(): array` returning `list<string>` `['incomplete', 'incomplete_expired']`.

This is a pure refactor. The existing webhook tests (incomplete checkout keeps Free, abandoned checkout restores the trial) are the gate.

- [ ] **Step 1: Add the method to the enum**

In `app/Enums/StripeSubscriptionStatus.php`, after `tooltipFor()`:

```php
    /** @return list<string> */
    public static function neverGranted(): array
    {
        return [self::Incomplete->value, self::IncompleteExpired->value];
    }
```

- [ ] **Step 2: Replace the constant in the sync action**

In `app/Actions/Billing/SyncWorkspacePlanFromSubscription.php`, delete the `NON_GRANTING_STATUSES` docblock and constant (lines 19-25). Add `use App\Enums\StripeSubscriptionStatus;`. Change the check in `targetPlan()`:

```php
        if (in_array($subscription->stripe_status, StripeSubscriptionStatus::neverGranted(), true)) {
            return null;
        }
```

Keep the comment above it.

- [ ] **Step 3: Replace the constant in the webhook controller**

In `app/Http/Controllers/Billing/StripeWebhookController.php`, delete the `NON_GRANTING_STATUSES` docblock and constant (lines 17-23). Add `use App\Enums\StripeSubscriptionStatus;`. Change `handleCustomerSubscriptionCreated()`:

```php
        if (! in_array($object['status'] ?? null, StripeSubscriptionStatus::neverGranted(), true)) {
            return parent::handleCustomerSubscriptionCreated($payload);
        }
```

- [ ] **Step 4: Confirm nothing else holds the list**

Run: `grep -rn "NON_GRANTING_STATUSES\|'incomplete_expired'\]" app packages`
Expected: no output.

- [ ] **Step 5: Run the webhook and trial tests**

Run: `php artisan test --compact tests/Feature/Billing/StripeWebhookTest.php tests/Feature/Billing/TrialLifecycleTest.php`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/StripeSubscriptionStatus.php app/Actions/Billing/SyncWorkspacePlanFromSubscription.php app/Http/Controllers/Billing/StripeWebhookController.php
git commit -m "refactor(billing): give never-granting stripe statuses one owner"
```

---

### Task 2: BillingStatus owns ended states and access

**Files:**
- Modify: `app/Enums/BillingStatus.php`
- Test: `tests/Feature/SystemAdmin/WorkspaceResourceTest.php:289-373`
- Test: `tests/Feature/Billing/TrialLifecycleTest.php`

**Interfaces:**
- Consumes: `StripeSubscriptionStatus::neverGranted(): array` (Task 1).
- Produces: `BillingStatus::SubscriptionEnded` (value `'subscription_ended'`), `BillingStatus::grantsAccess(): bool`. `TrialEnded` keeps the value `'trial_ended'`, `Free` keeps `'free'`. Tasks 3 and 4 rely on these three values as lang-key suffixes.

- [ ] **Step 1: Add the new arrangements to the dataset**

In `tests/Feature/SystemAdmin/WorkspaceResourceTest.php`, add these entries to the array in `billingStatusArrangements()`, after the `'an expired trial awaiting the nightly downgrade ...'` entry:

```php
        'a trial paused by the nightly downgrade still reads Trial ended' => [
            fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Free, 'pro_trial_used_at' => now()->subDays(15)])->save(),
            BillingStatus::TrialEnded,
        ],
        'a trial that ended beside an abandoned checkout reads Trial ended' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['pro_trial_used_at' => now()->subDays(15)])->save();
                Subscription::factory()->incompleteAndExpired()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::TrialEnded,
        ],
        'a cancelled subscription reads Subscription ended' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['pro_trial_used_at' => now()->subMonths(3)])->save();
                Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::SubscriptionEnded,
        ],
        'a cancelled subscription followed by an abandoned checkout still reads Subscription ended' => [
            function (Workspace $workspace): void {
                Subscription::factory()->canceled()->create([
                    'workspace_id' => $workspace->getKey(),
                    'created_at' => now()->subMonths(2),
                    'updated_at' => now()->subMonths(2),
                ]);
                Subscription::factory()->incompleteAndExpired()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::SubscriptionEnded,
        ],
        'a cancelled subscriber granted Pro by hand reads Granted' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['plan' => Plan::Pro])->save();
                Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::Granted,
        ],
        'a grandfathered workspace whose subscription ended reads Free (legacy)' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['hosted_free_grandfathered_at' => now()->subYear()])->save();
                Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::Grandfathered,
        ],
        'a checkout abandoned without a trial reads Free' => [
            function (Workspace $workspace): void {
                Subscription::factory()->incompleteAndExpired()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::Free,
        ],
```

- [ ] **Step 2: Re-key the filter test by arrangement name**

Replace the body of `it('filters workspaces by the badge they show, and by no other', ...)` with:

```php
it('filters workspaces by the badge they show, and by no other', function (): void {
    $arranged = [];

    foreach (billingStatusArrangements() as $name => [$arrange, $status]) {
        $workspace = billingStatusWorkspace();
        $arrange($workspace);
        $arranged[$name] = ['workspace' => $workspace->fresh(), 'status' => $status];
    }

    expect(collect($arranged)->map(fn (array $entry): string => $entry['status']->value)->unique()->values()->all())
        ->toEqualCanonicalizing(array_column(BillingStatus::cases(), 'value'));

    foreach ($arranged as $entry) {
        $others = collect($arranged)
            ->reject(fn (array $other): bool => $other['status'] === $entry['status'])
            ->map(fn (array $other): Workspace => $other['workspace'])
            ->values()
            ->all();

        livewire(ListWorkspaces::class)
            ->filterTable('billing_status', [$entry['status']->value])
            ->assertCanSeeTableRecords([$entry['workspace']])
            ->assertCanNotSeeTableRecords($others);
    }
});
```

- [ ] **Step 3: Add the job-then-badge test**

In `tests/Feature/Billing/TrialLifecycleTest.php`, add `use App\Enums\BillingStatus;` to the imports and this test after `'emails the owner once when their expired trial is paused'`:

```php
it('still reads Trial ended after the nightly downgrade pauses the trial', function (): void {
    Mail::fake();

    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill([
        'plan' => Plan::Pro,
        'trial_ends_at' => now()->subHour(),
        'pro_trial_used_at' => now()->subDays(14),
    ])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    expect($workspace->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded);
});
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/SystemAdmin/WorkspaceResourceTest.php tests/Feature/Billing/TrialLifecycleTest.php`
Expected: FAIL. `BillingStatus::SubscriptionEnded` is undefined, and the job test reads `free` instead of `trial_ended`.

- [ ] **Step 5: Implement the enum changes**

In `app/Enums/BillingStatus.php`:

1. Replace the class docblock's last paragraph with:

```php
 * Read-only and derived. Nothing persists it. It is the one owner of billing
 * state: the access gate, the sidebar, the paused screen, and the ended email
 * all read it. `HostedWorkspaceAccess` only layers the billing feature flag on
 * top of `grantsAccess()`.
```

2. Declare the new case between `Grandfathered` and `TrialEnded`:

```php
    case Grandfathered = 'grandfathered';

    case SubscriptionEnded = 'subscription_ended';

    case TrialEnded = 'trial_ended';
```

3. Replace everything in `fromWorkspace()` after the `Grandfathered` check with:

```php
        if ($workspace->plan === Plan::Free && self::hasChargedSubscription($workspace)) {
            return self::SubscriptionEnded;
        }

        if ($workspace->trial_ends_at !== null) {
            return self::TrialEnded;
        }

        // The nightly downgrade clears trial_ends_at, so only the trial marker is left.
        if ($workspace->plan === Plan::Free && $workspace->pro_trial_used_at !== null) {
            return self::TrialEnded;
        }

        if ($workspace->plan !== Plan::Free) {
            return self::Granted;
        }

        return self::Free;
```

4. Add `grantsAccess()` after `applyToQuery()`:

```php
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::PastDue, self::Subscribed, self::Enterprise, self::Trialing, self::Grandfathered, self::Granted => true,
            self::SubscriptionEnded, self::TrialEnded, self::Free => false,
        };
    }
```

5. Extend the three display `match`es:

```php
            self::SubscriptionEnded => 'Subscription ended',
```

```php
            self::SubscriptionEnded => 'Paid subscription ended with nothing behind it. Hosted access is paused until the owner subscribes again.',
```

```php
            self::PastDue, self::SubscriptionEnded, self::TrialEnded => 'danger',
```

6. Replace the `TrialEnded` arm in `constrain()` and add the `SubscriptionEnded` arm:

```php
            // Any row, not the latest: a later abandoned checkout must not hide one that ended.
            self::SubscriptionEnded => $query->where('plan', Plan::Free)->whereHas('subscriptions', self::charged(...)),
            self::TrialEnded => $query->where(fn (Builder $ended): Builder => $ended
                ->whereNotNull('trial_ends_at')
                ->orWhere(fn (Builder $downgraded): Builder => $downgraded->where('plan', Plan::Free)->whereNotNull('pro_trial_used_at'))),
```

7. Add the two helpers next to `pastDue()` and `valid()`:

```php
    private static function hasChargedSubscription(Workspace $workspace): bool
    {
        return $workspace->subscriptions->contains(
            fn (Subscription $subscription): bool => ! in_array($subscription->stripe_status, StripeSubscriptionStatus::neverGranted(), true),
        );
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    private static function charged(Builder $query): Builder
    {
        return $query->whereNotIn('stripe_status', StripeSubscriptionStatus::neverGranted());
    }
```

`StripeSubscriptionStatus` is in the same namespace, so it needs no import.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/SystemAdmin/WorkspaceResourceTest.php tests/Feature/Billing/TrialLifecycleTest.php`
Expected: all pass, including every arrangement through both the dataset test and the filter test.

- [ ] **Step 7: Sweep SystemAdmin for unchecked matches**

Run: `grep -rn "BillingStatus" packages/SystemAdmin | grep -i "match"`
Expected: no output. If a `match` over `BillingStatus` exists, add the `SubscriptionEnded` arm.

- [ ] **Step 8: Static analysis on the enum**

Run: `vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse app/Enums/BillingStatus.php app/Enums/StripeSubscriptionStatus.php`
Expected: no errors.

- [ ] **Step 9: Commit**

```bash
git add app/Enums/BillingStatus.php tests/Feature/SystemAdmin/WorkspaceResourceTest.php tests/Feature/Billing/TrialLifecycleTest.php
git commit -m "fix(billing): keep ended trials and subscriptions distinct for good"
```

---

### Task 3: Access gate and sidebar read the enum

**Files:**
- Modify: `app/Services/Billing/HostedWorkspaceAccess.php`
- Modify: `app/Services/Billing/SidebarBillingState.php`
- Modify: `docs/billing.md:23-25,77`
- Test: `tests/Feature/Billing/HostedWorkspaceAccessTest.php`
- Test: `tests/Feature/Billing/SidebarBillingStateTest.php`

**Interfaces:**
- Consumes: `Workspace::billingStatus(): BillingStatus`, `BillingStatus::grantsAccess(): bool`, `BillingStatus::PastDue`, `BillingStatus::Trialing` (Task 2).
- Produces: `HostedWorkspaceAccess::allows()` and `isPaused()` with unchanged signatures and behaviour. `SidebarBillingState` loses its constructor dependency; it is resolved from the container in `resources/views/filament/app/sidebar-footer.blade.php:14`, so no caller changes.

The new tests pin today's access behaviour. They pass before the refactor and must still pass after it.

- [ ] **Step 1: Add the access pins**

In `tests/Feature/Billing/HostedWorkspaceAccessTest.php`, add `use Laravel\Cashier\Subscription;` and these tests after `'preserves hosted access for a grandfathered Free workspace'`:

```php
it('pauses a workspace whose subscription ended', function (): void {
    Subscription::factory()->canceled()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->workspace->slug]))
        ->assertRedirect(route('filament.app.pages.billing', ['tenant' => $this->workspace->slug]));
});

it('keeps a trial paused after the nightly downgrade', function (): void {
    $this->workspace->forceFill(['plan' => Plan::Free, 'pro_trial_used_at' => now()->subDays(15)])->save();

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->workspace->slug]))
        ->assertRedirect(route('filament.app.pages.billing', ['tenant' => $this->workspace->slug]));
});

it('allows a cancelled subscriber that a sysadmin granted Pro', function (): void {
    $this->workspace->forceFill(['plan' => Plan::Pro])->save();
    Subscription::factory()->canceled()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->workspace->slug]))
        ->assertOk();
});

it('keeps a grandfathered workspace open after its subscription ended', function (): void {
    $this->workspace->forceFill(['hosted_free_grandfathered_at' => now()->subYear()])->save();
    Subscription::factory()->canceled()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->workspace->slug]))
        ->assertOk();
});
```

- [ ] **Step 2: Add the sidebar pin**

In `tests/Feature/Billing/SidebarBillingStateTest.php`, add `use Laravel\Cashier\Subscription;` and this test after `'asks a paused workspace to subscribe'`:

```php
it('asks a workspace whose subscription ended to subscribe', function (): void {
    Subscription::factory()->canceled()->create(['workspace_id' => $this->workspace->getKey()]);

    $state = resolve(SidebarBillingState::class)->for($this->workspace->fresh());

    expect($state)->not->toBeNull()
        ->and($state['label'])->toBe(__('billing.sidebar.paused'))
        ->and($state['action'])->toBe(__('billing.sidebar.subscribe'))
        ->and($state['urgent'])->toBeFalse();
});
```

- [ ] **Step 3: Run the pins against the old code**

Run: `php artisan test --compact tests/Feature/Billing/HostedWorkspaceAccessTest.php tests/Feature/Billing/SidebarBillingStateTest.php`
Expected: all pass. They describe today's behaviour. If one fails, stop: the enum from Task 2 disagrees with today's gate, and that is a spec question, not something to patch here.

- [ ] **Step 4: Rewrite `HostedWorkspaceAccess`**

Replace the class body in `app/Services/Billing/HostedWorkspaceAccess.php` and drop the now-unused `use App\Enums\Plan;`:

```php
final readonly class HostedWorkspaceAccess
{
    public function allows(Workspace $workspace): bool
    {
        if (! Feature::active(Billing::class)) {
            return true;
        }

        return $workspace->billingStatus()->grantsAccess();
    }

    public function isPaused(Workspace $workspace): bool
    {
        return ! $this->allows($workspace);
    }
}
```

- [ ] **Step 5: Rewrite `SidebarBillingState::for()`**

In `app/Services/Billing/SidebarBillingState.php`, remove the constructor and the `use App\Enums\Plan;` import, add `use App\Enums\BillingStatus;`, and replace `for()`, keeping its docblock:

```php
    public function for(Workspace $workspace): ?array
    {
        if (! Feature::active(Billing::class)) {
            return null;
        }

        $status = $workspace->billingStatus();

        if ($status === BillingStatus::PastDue) {
            return [
                'label' => __('billing.sidebar.past_due'),
                'action' => __('billing.sidebar.fix'),
                'urgent' => true,
            ];
        }

        if ($status === BillingStatus::Trialing) {
            return [
                'label' => trans_choice('billing.sidebar.trial_days_left', $this->daysLeft($workspace), [
                    'days' => $this->daysLeft($workspace),
                ]),
                'action' => __('billing.sidebar.keep_pro'),
                'urgent' => false,
            ];
        }

        if ($status->grantsAccess()) {
            return null;
        }

        return [
            'label' => __('billing.sidebar.paused'),
            'action' => __('billing.sidebar.subscribe'),
            'urgent' => false,
        ];
    }
```

Keep `daysLeft()` unchanged. Update the class docblock's first paragraph to say it derives from `BillingStatus`, the same owner the Billing page reads.

- [ ] **Step 6: Confirm every multi-workspace caller eager-loads subscriptions**

Run: `grep -rn "isPaused(\|->allows(\$\|billingStatus()" app packages resources | grep -v "Gate::"`
Expected: the only caller looping over several workspaces is `app/Providers/AppServiceProvider.php` (already `->with('subscriptions')`) plus the SystemAdmin tables (already eager-loaded, see their comments). Any other loop must add `->with('subscriptions')`.

- [ ] **Step 7: Run the tests to verify they still pass**

Run: `php artisan test --compact tests/Feature/Billing/HostedWorkspaceAccessTest.php tests/Feature/Billing/SidebarBillingStateTest.php tests/Feature/Billing/BillingPageTest.php tests/Feature/Chat/OutOfCreditsResponseTest.php tests/Feature/Commands/ResetDemoAccountCommandTest.php`
Expected: all pass.

- [ ] **Step 8: Update `docs/billing.md`**

Replace the hosted-access bullet (lines 23-25) with:

```markdown
- Managed-Cloud access is a separate entitlement. `App\Enums\BillingStatus` owns the state and
  `grantsAccess()` answers per state. `HostedWorkspaceAccess` only adds the billing feature flag,
  which is off on self-hosted installs. A live trial or subscription, a manual paid grant,
  Enterprise, or `teams.hosted_free_grandfathered_at` grants access. Trial ended, Subscription
  ended, and Free are paused, and the paused screen, sidebar, and ended email name which one.
```

In the Trials section (line 77), replace `one per user (\`users.pro_trial_used_at\`)` with `one per workspace (\`teams.pro_trial_used_at\`, never cleared)`.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Billing/HostedWorkspaceAccess.php app/Services/Billing/SidebarBillingState.php docs/billing.md tests/Feature/Billing/HostedWorkspaceAccessTest.php tests/Feature/Billing/SidebarBillingStateTest.php
git commit -m "refactor(billing): read hosted access and the sidebar from billing status"
```

---

### Task 4: Paused screen and ended email speak the enum's vocabulary

**Files:**
- Modify: `app/Filament/Pages/Billing.php:11,203-216`
- Modify: `resources/views/filament/pages/billing-paused.blade.php:155`
- Modify: `lang/en/billing.php:56-61`
- Modify: `app/Mail/ProEndedMail.php`
- Modify: `lang/en/mail.php:47-55`
- Test: `tests/Feature/Billing/BillingPageTest.php`
- Test: `tests/Feature/Email/ProEndedMailTest.php`
- Test: `tests/Feature/Billing/StripeWebhookTest.php:186`
- Test: `tests/Feature/Billing/TrialLifecycleTest.php:137`

**Interfaces:**
- Consumes: `Workspace::billingStatus(): BillingStatus` and case values `trial_ended`, `subscription_ended`, `free` (Task 2).
- Produces: view variable `$billingStatus` (a `BillingStatus`) on the paused screen, and `ProEndedMail::$status` (a `BillingStatus`) replacing `ProEndedMail::$cause`.

- [ ] **Step 1: Rename the keys and properties in the tests**

```bash
sed -i '' -e "s/billing\.paused\.heading\.trial'/billing.paused.heading.trial_ended'/g" \
  -e "s/billing\.paused\.heading\.subscription'/billing.paused.heading.subscription_ended'/g" \
  -e "s/billing\.paused\.heading\.paused'/billing.paused.heading.free'/g" tests/Feature/Billing/BillingPageTest.php
sed -i '' -e "s/mail\.pro_ended\.trial\./mail.pro_ended.trial_ended./g" \
  -e "s/mail\.pro_ended\.subscription\./mail.pro_ended.subscription_ended./g" tests/Feature/Email/ProEndedMailTest.php
```

Then by hand:
- `tests/Feature/Billing/StripeWebhookTest.php:186`: `$mail->cause === 'subscription'` becomes `$mail->status === BillingStatus::SubscriptionEnded`. Add `use App\Enums\BillingStatus;` if missing.
- `tests/Feature/Billing/TrialLifecycleTest.php:137`: `$mail->cause === 'trial'` becomes `$mail->status === BillingStatus::TrialEnded` (the import was added in Task 2).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Billing/BillingPageTest.php tests/Feature/Email/ProEndedMailTest.php tests/Feature/Billing/StripeWebhookTest.php tests/Feature/Billing/TrialLifecycleTest.php`
Expected: FAIL. The new lang keys do not exist, so `__()` returns the raw key, and `ProEndedMail` has no `status` property.

- [ ] **Step 3: Rename the lang keys**

`lang/en/billing.php`:

```php
    'paused' => [
        'heading' => [
            'trial_ended' => 'Your Pro trial has ended',
            'subscription_ended' => 'Your Cloud Pro subscription has ended',
            'free' => ':workspace is paused',
        ],
```

`lang/en/mail.php`: under `pro_ended`, rename `'trial' => [` to `'trial_ended' => [` and `'subscription' => [` to `'subscription_ended' => [`. Leave the nested `subject` and `heading` copy unchanged.

- [ ] **Step 4: Paused screen reads the status**

In `app/Filament/Pages/Billing.php`, replace `use App\Enums\StripeSubscriptionStatus;` with `use App\Enums\BillingStatus;` (confirm with `grep -n StripeSubscriptionStatus app/Filament/Pages/Billing.php` that nothing else uses it), then change `pausedViewData()`:

```php
    /** @return array{billingStatus: BillingStatus, reviewingPlan: bool, otherWorkspaces: Collection<int, Workspace>} */
    private function pausedViewData(Workspace $workspace): array
    {
        return [
            'billingStatus' => $workspace->billingStatus(),
            'reviewingPlan' => $this->step === 'plan'
                && $this->user()->ownsWorkspace($workspace)
                && $this->checkout !== 'success',
            'otherWorkspaces' => $this->user()->allWorkspaces()
                ->reject(fn (Workspace $other): bool => $other->is($workspace))
                ->values(),
        ];
    }
```

In `resources/views/filament/pages/billing-paused.blade.php:155`:

```blade
                            {{ __("billing.paused.heading.{$billingStatus->value}", ['workspace' => $workspace->name]) }}
```

Run `grep -rn "pausedCause" resources app` and expect no output.

- [ ] **Step 5: Email stores the status**

In `app/Mail/ProEndedMail.php`, add `use App\Enums\BillingStatus;` and change the constructor, named constructors, and key lookups:

```php
    private function __construct(
        public Workspace $workspace,
        public BillingStatus $status,
    ) {}

    public static function afterTrial(Workspace $workspace): self
    {
        return new self($workspace, BillingStatus::TrialEnded);
    }

    public static function afterSubscription(Workspace $workspace): self
    {
        return new self($workspace, BillingStatus::SubscriptionEnded);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("mail.pro_ended.{$this->status->value}.subject"));
    }
```

In `content()`, change the heading key to `"mail.pro_ended.{$this->status->value}.heading"`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Billing/BillingPageTest.php tests/Feature/Email/ProEndedMailTest.php tests/Feature/Email/MailPreviewTest.php tests/Feature/Billing/StripeWebhookTest.php tests/Feature/Billing/TrialLifecycleTest.php`
Expected: all pass.

- [ ] **Step 7: No stale keys left**

Run: `grep -rnE "pro_ended\.(trial|subscription)\.|paused\.heading\.(trial|subscription|paused)['\"]|->cause\b" app resources tests packages lang`
Expected: no output.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Pages/Billing.php resources/views/filament/pages/billing-paused.blade.php lang/en/billing.php app/Mail/ProEndedMail.php lang/en/mail.php tests/Feature/Billing/BillingPageTest.php tests/Feature/Email/ProEndedMailTest.php tests/Feature/Billing/StripeWebhookTest.php tests/Feature/Billing/TrialLifecycleTest.php
git commit -m "refactor(billing): name paused and ended states by billing status"
```

---

### Task 5: Gates, full suite, and browser walk

**Files:**
- Modify: whatever the gates flag. No planned source changes.

- [ ] **Step 1: Deterministic gates**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G
composer test:type-coverage
composer test:lint
```

Expected: rector reports no changes, phpstan reports no errors, type coverage is 100%, lint passes. Apply rector's changes if it suggests any, then re-run.

- [ ] **Step 2: Em-dash sweep over the added lines**

Run: `git diff origin/main...HEAD | grep '^+' | grep -n $'\xe2\x80\x94'`
Expected: no output, other than the allowlisted empty-value glyph literal if an added line happens to carry it.

- [ ] **Step 3: Full suite once**

Run: `composer test:pest:full`
Expected: all pass. If anything fails, diagnose first: a production bug, a wrong assertion, or state leaking between tests. Fix it at that layer.

- [ ] **Step 4: Browser walk**

Load the `agent-browser-relaticle` skill, derive this worktree's app and sysadmin URLs from it, and turn the billing feature flag on locally. Put one local workspace into each state with tinker, one state at a time. Trial ended:

```bash
php artisan tinker --execute '$w = App\Models\Workspace::query()->where("slug", "<slug>")->firstOrFail(); $w->forceFill(["plan" => App\Enums\Plan::Free, "trial_ends_at" => null, "pro_trial_used_at" => now()->subDays(15), "hosted_free_grandfathered_at" => null])->save();'
```

Subscription ended, on top of that:

```bash
php artisan tinker --execute '$w = App\Models\Workspace::query()->where("slug", "<slug>")->firstOrFail(); $w->subscriptions()->create(["type" => "default", "stripe_id" => "sub_local_ended", "stripe_status" => "canceled", "stripe_price" => config("services.stripe.prices.pro_monthly"), "quantity" => 1, "ends_at" => now()->subDay()]);'
```

Record the workspace's original column values before the first command. Capture into `.context/`, light and dark:
1. The paused screen reading "Your Pro trial has ended".
2. The paused screen reading "Your Cloud Pro subscription has ended".
3. The sysadmin workspace list showing the Subscription ended and Trial ended badges.
4. The billing status filter set to Subscription ended.

Afterwards, delete `sub_local_ended` and restore the recorded column values.

- [ ] **Step 5: Push state check**

Run: `git log origin/feat/trial-end-paywall..HEAD --oneline`
Expected: empty once Conductor has pushed. Report the PR #835 head SHA.
