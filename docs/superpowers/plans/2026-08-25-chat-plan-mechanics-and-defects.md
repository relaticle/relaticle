# Chat Plan Mechanics and Defects Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `sdd-lean` to implement this plan task-by-task (this project's CLAUDE.md mandates `sdd-lean` over superpowers:subagent-driven-development). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the five production chat defects and resolve the batching/reference design conflict, so a multi-entity plan links its records on the first attempt instead of paying extra approval round-trips.

**Architecture:** All changes are inside `packages/Chat`. Plan references gain an optional `#<index>` suffix so a reference can address one record inside a batched proposal, and an already-approved reference resolves to its real record id instead of erroring. Four independent defect fixes follow the same shape: a failing Feature test in the file that already owns the class, then the minimal change.

**Tech Stack:** PHP 8.5, Laravel, Pest 5, PostgreSQL, `laravel/ai`.

**Spec:** `docs/superpowers/specs/2026-08-25-chat-prompt-and-plan-redesign-design.md`

## Global Constraints

- PostgreSQL only. No SQLite/MySQL compatibility branches. Migrations have `up()` only, no `down()`.
- Every write path goes through an action class in `app/Actions/<Domain>/`. Eloquent writes inside chat tools fail PHPStan (`EloquentWriteOutsideActionRule`).
- `final readonly` classes, constructor property promotion, typed properties, explicit return types including `void`. Short nullable syntax `?Type`.
- No new PHPStan ignores without approval. PHPStan level 7, no baseline. Type coverage must stay at 100%.
- Tests live in `tests/Feature/`. Extend the existing file that covers the class; do not create a new test file where one exists.
- Never assert on prompt wording in a new test. Assert on behaviour.
- Pre-commit, in order: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:type-coverage`, then the targeted Pest run.
- Conventional commits, subject under 72 chars, lowercase, present tense. No AI attribution anywhere in commits, code or comments.
- **Always prefix test commands with `DB_DATABASE=relaticle_testing_dhaka`.** The default
  `relaticle_testing` is shared by 13 checkouts, and a concurrent suite causes `40P01` deadlocks or
  transient "column does not exist" errors on columns that are present, which reads like a broken
  migration and is not. This workspace now has its own migrated database. `phpunit.xml` declares
  `DB_DATABASE` without `force`, so a shell environment variable wins. Verified 2026-08-25: 19
  tests ran green here while another checkout ran a full parallel suite against the shared one.
  Do NOT edit `.env.testing` or `phpunit.xml` to achieve this. Both are tracked and shared.
- To see whether another checkout is mid-run, use `ps -Ao command | grep vendor/bin/pest`.
  `pgrep -fl pest` matches unrelated shell wrappers and will report a false negative.
- Never run a test suite as a background task and then end your turn waiting on it. Run it in the
  foreground. Three separate implementer turns were lost to that pattern on 2026-08-25.

---

### Task 1: Inactive custom fields become visible to the model

The only user-visible wrong answer in the audit. On 2026-08-25 the assistant told a user "Tasks don't have a priority field in Relaticle" when a Priority field existed but was deactivated. `CustomFieldsSchemaDescriber` filters `->active()`, so a deactivated field is invisible in the injected schema while `ListCustomFieldsTool` reports it.

**Files:**
- Modify: `packages/Chat/src/Services/Tools/CustomFieldsSchemaDescriber.php:23-30`
- Test: `tests/Feature/Chat/CustomFieldsBridge/SchemaDescriberTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `CustomFieldsSchemaDescriber::describe(Team $team, string $entityType): string` keeps its signature. Its output now contains a line per inactive field marked `(inactive, not settable)`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Chat/CustomFieldsBridge/SchemaDescriberTest.php`:

```php
it('lists a deactivated field and marks it not settable', function (): void {
    $team = Team::factory()->create();

    CustomField::factory()->for($team, 'tenant')->create([
        'entity_type' => 'task',
        'code' => 'priority',
        'name' => 'Priority',
        'active' => false,
    ]);

    $description = resolve(CustomFieldsSchemaDescriber::class)->describe($team, 'task');

    expect($description)
        ->toContain('priority')
        ->toContain('inactive, not settable');
});
```

Check the factory helpers already used at the top of that file and match them; if the existing tests build custom fields with a different helper, use that one rather than `CustomField::factory()`.

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --compact --filter=SchemaDescriber`
Expected: FAIL. The description will not contain `priority`, because `->active()` excludes it.

- [ ] **Step 3: Make the minimal change**

In `describe()`, drop `->active()` from the query and order active fields first so the settable ones lead:

```php
$fields = CustomField::query()
    ->where('tenant_id', $team->getKey())
    ->where('entity_type', $entityType)
    ->orderByDesc('active')
    ->orderBy('code')
    ->with(['options:id,custom_field_id,name'])
    ->get();
```

In the `foreach` that builds `$lines`, suffix inactive fields:

```php
foreach ($fields as $field) {
    $line = '- '.$this->describeField($field);

    if (! $field->active) {
        $line .= ' (inactive, not settable: the workspace owner can reactivate it in custom field settings)';
    }

    $lines[] = $line;
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: `php artisan test --compact --filter=SchemaDescriber`
Expected: PASS.

- [ ] **Step 5: Confirm no regression in the write path**

An inactive field must still be rejected on write. Run the custom-field write suites:

Run: `php artisan test --compact --filter="AllCustomFieldsViaChat|CustomFieldsBridge"`
Expected: PASS. If a test now fails because an inactive code reaches the validator, that is the real bug this task must not introduce: `CustomFieldValidationService` is the layer that must reject it, not the describer.

- [ ] **Step 6: Commit**

```bash
git add packages/Chat/src/Services/Tools/CustomFieldsSchemaDescriber.php tests/Feature/Chat/CustomFieldsBridge/SchemaDescriberTest.php
git commit -m "fix(chat): show deactivated custom fields to the assistant"
```

---

### Task 2: Indexed plan references

`$ref:<pending_action_id>` cannot address one record inside a batched proposal, so a plan that batches two contacts and links a deal to one of them is unexpressible. `approveItem()` already stores `result_data['items'][$index]['id']`, so no new storage is needed.

**Files:**
- Modify: `packages/Chat/src/Support/PlanReference.php`
- Modify: `packages/Chat/src/Services/Tools/PlanReferenceValidator.php:45-72`
- Modify: `packages/Chat/src/Services/PlanReferenceResolver.php:35-62`
- Test: `tests/Feature/Chat/PlanProposalTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `PlanReference::to(string $pendingActionId, ?int $index = null): string`
  - `PlanReference::actionId(string $target): string` — the part before `#`
  - `PlanReference::index(string $target): ?int` — the part after `#`, or null
  - `PlanReference::target()`, `targetsIn()` and `rewrite()` keep their current signatures and now carry the full `id#index` string as the target.

- [ ] **Step 1: Write the failing test**

Append inside the existing `describe` block in `tests/Feature/Chat/PlanProposalTest.php` that holds the reference-rejection tests:

```php
it('resolves a reference to one record inside a batched proposal', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics'], ['name' => 'Globex']],
    ]));

    $companies = ($this->proposalFor)('company');

    $result = ($this->tool)(CreatePersonTool::class)->handle(new Request([
        'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $companies->getKey(), 1)]],
    ]));

    expect($result)->not->toContain('ambiguous')
        ->and($result)->not->toContain('error');
});

it('rejects an indexed reference outside the batch bounds', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics'], ['name' => 'Globex']],
    ]));

    $companies = ($this->proposalFor)('company');

    $result = ($this->tool)(CreatePersonTool::class)->handle(new Request([
        'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $companies->getKey(), 7)]],
    ]));

    expect($result)->toContain('only has 2 records');
});
```

Rewrite the existing `it('rejects a reference to a multi-record proposal')` so it asserts the bare (unindexed) form is still rejected, keeping its `ambiguous` assertion and adding a sentence to the message that points at the indexed form.

- [ ] **Step 2: Run and confirm failure**

Run: `php artisan test --compact --filter=PlanProposal`
Expected: FAIL. `PlanReference::to()` does not accept a second argument yet.

- [ ] **Step 3: Extend `PlanReference`**

```php
public static function to(string $pendingActionId, ?int $index = null): string
{
    return self::PREFIX.$pendingActionId.($index === null ? '' : '#'.$index);
}

/**
 * The pending action id inside a target, with any `#<index>` suffix removed.
 */
public static function actionId(string $target): string
{
    $hash = strpos($target, '#');

    return $hash === false ? $target : substr($target, 0, $hash);
}

/**
 * The record index inside a batched proposal, or null when the target names
 * a single-record proposal.
 */
public static function index(string $target): ?int
{
    $hash = strpos($target, '#');

    if ($hash === false) {
        return null;
    }

    $index = substr($target, $hash + 1);

    return ctype_digit($index) ? (int) $index : null;
}
```

- [ ] **Step 4: Teach the validator about indexes**

In `PlanReferenceValidator::error()`, replace `->whereKey($target)` with `->whereKey(PlanReference::actionId($target))`, then replace the batch rejection with a bounds check:

```php
$index = PlanReference::index($target);
$payload = ProposalPayload::from($referenced);

if ($payload->isBatch && $index === null) {
    return "Step reference `{$value}` points at a multi-record proposal. Add the record's index, for example `{$value}#0`, or propose that record in its own tool call.";
}

if ($index !== null) {
    $count = $payload->count();

    if (! $payload->isBatch) {
        return "Step reference `{$value}` has an index but points at a single-record proposal. Drop the index.";
    }

    if ($index < 0 || $index >= $count) {
        return "Step reference `{$value}` is out of range: that proposal only has {$count} records.";
    }
}
```

`ProposalPayload::count()` exists at `packages/Chat/src/Support/ProposalPayload.php:72`, alongside `isBatch`, `batchRecordAt(int $index)` and `recordAtOrEmpty(int $index)`. Use `count()` for the bounds check.

- [ ] **Step 5: Teach the resolver about indexes**

In `PlanReferenceResolver::recordIdFor()`, resolve the action by `actionId` and read the indexed item when an index is present:

```php
$referenced = PendingAction::query()
    ->whereKey(PlanReference::actionId($target))
    ->where('team_id', $context->team_id)
    ->first();
```

Then, after the `Approved` check, replace the flat id read:

```php
$resultData = is_array($referenced->result_data) ? $referenced->result_data : [];
$index = PlanReference::index($target);

if ($index === null) {
    $recordId = $resultData['id'] ?? null;
} else {
    $items = is_array($resultData['items'] ?? null) ? $resultData['items'] : [];
    $item = is_array($items[$index] ?? null) ? $items[$index] : [];
    $recordId = $item['id'] ?? null;
}
```

Leave the existing `throw_unless` on `$recordId` in place; it already produces the right user-facing message when a step produced no record.

- [ ] **Step 6: Run and confirm the tests pass**

Run: `php artisan test --compact --filter=PlanProposal`
Expected: PASS, including the rewritten multi-record test.

- [ ] **Step 7: Update the prompt to match**

In `packages/Chat/src/Agents/CrmAssistant.php`, the `## Writes` section, replace the sentence forbidding references to multi-record proposals with the indexed form. The three rules that currently conflict (batch everything into one call, chain with `$ref`, never `$ref` a batch) become one coherent statement: batch records of one type into a single call, and when a later record must link to one of them, reference it by index.

Do not add emphasis. State it once.

- [ ] **Step 8: Fix the prompt-wording tests that now fail**

Run: `php artisan test --compact --filter=CrmAssistantInstructions`
Any assertion pinning the old sentence fails. Update those assertions to the new wording for now; Task 8 of the follow-up plan replaces this whole file with behavioural tests.

- [ ] **Step 9: Commit**

```bash
git add packages/Chat/src/Support/PlanReference.php packages/Chat/src/Services/Tools/PlanReferenceValidator.php packages/Chat/src/Services/PlanReferenceResolver.php packages/Chat/src/Agents/CrmAssistant.php tests/Feature/Chat/PlanProposalTest.php tests/Feature/Chat/CrmAssistantInstructionsTest.php
git commit -m "feat(chat): reference one record inside a batched proposal"
```

---

### Task 3: An approved reference resolves instead of erroring

The model emits several tool calls in one turn and the user can approve card 1 while card 3 is still being validated. `PlanReferenceValidator` requires `Pending` and returns an error. The record exists and its id is known, so the error is unnecessary. This fired in production twelve minutes after #506 deployed.

**Files:**
- Modify: `packages/Chat/src/Services/Tools/PlanReferenceValidator.php:65-68`
- Modify: `packages/Chat/src/Tools/` — whichever base tool rewrites the payload before building `actionData` (find with `grep -rn "PlanReferenceValidator" packages/Chat/src/Tools`)
- Test: `tests/Feature/Chat/PlanProposalTest.php`

**Interfaces:**
- Consumes: `PlanReference::actionId()` and `PlanReference::index()` from Task 2.
- Produces: no signature changes. `PlanReferenceValidator::error()` returns null for an approved target that has a resolvable record id.

- [ ] **Step 1: Write the failing test**

Rewrite the existing `it('rejects a reference to an already decided proposal')` and add its sibling:

```php
it('resolves a reference whose target was approved mid-turn', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics']],
    ]));

    $company = ($this->proposalFor)('company');
    resolve(PendingActionService::class)->approve($company, $this->user);

    $result = ($this->tool)(CreatePersonTool::class)->handle(new Request([
        'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $company->getKey())]],
    ]));

    expect($result)->not->toContain('already approved');

    $person = ($this->proposalFor)('people');
    $data = $person->action_data;

    expect($data['company_id'] ?? null)->toBe((string) $company->refresh()->result_data['id']);
});

it('still rejects a reference whose target was rejected', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics']],
    ]));

    $company = ($this->proposalFor)('company');
    resolve(PendingActionService::class)->reject($company, $this->user);

    $result = ($this->tool)(CreatePersonTool::class)->handle(new Request([
        'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $company->getKey())]],
    ]));

    expect($result)->toContain('rejected');
});
```

Confirm the reject method name on `PendingActionService` before writing the second test; `grep -n "public function reject" packages/Chat/src/Services/PendingActionService.php`.

- [ ] **Step 2: Run and confirm failure**

Run: `php artisan test --compact --filter=PlanProposal`
Expected: FAIL on the first test with `already approved`.

- [ ] **Step 3: Replace the status rejection with a resolve-or-reject branch**

In `PlanReferenceValidator::error()`, replace the `!== PendingActionStatus::Pending` block:

```php
if ($referenced->status === PendingActionStatus::Approved) {
    // The record exists. Erroring here would make the model re-plan a link the
    // user already approved on the card, so the reference is rewritten to the
    // real id by the caller instead.
    return null;
}

if ($referenced->status !== PendingActionStatus::Pending) {
    return "Step reference `{$value}` points at a proposal that was {$referenced->status->value}. Propose that record again, or use a record that exists.";
}
```

- [ ] **Step 4: Rewrite approved references in the payload before the proposal is stored**

Returning null is not enough on its own: the stored `action_data` must carry the real id, because `PlanReferenceResolver` only runs at approval time and would then find an approved target with an index-free lookup. In the write tool's payload preparation, after validation passes, rewrite every reference whose target is approved to its record id, using the same `result_data` read added in Task 2 Step 5. Extract that read into a small shared method so the resolver and this path cannot drift:

```php
// packages/Chat/src/Services/PlanReferenceResolver.php
public function recordIdIfApproved(PendingAction $referenced, ?int $index): ?string
```

Call it from both `recordIdFor()` and the write-tool path.

- [ ] **Step 5: Run and confirm the tests pass**

Run: `php artisan test --compact --filter=PlanProposal`
Expected: PASS, including the rejected-target test.

- [ ] **Step 6: Run the whole chat Feature suite**

Run: `php artisan test --compact tests/Feature/Chat`
Expected: PASS. This is the first task that changes approval-time behaviour, so a broad run is warranted before committing.

- [ ] **Step 7: Commit**

```bash
git add packages/Chat/src tests/Feature/Chat/PlanProposalTest.php
git commit -m "fix(chat): resolve a plan reference approved mid-turn"
```

---

### Task 4: A no-op record no longer aborts a batch update

`BaseWriteUpdateTool` returns from inside the per-record loop, so one record already matching its values killed an 18-record proposal in production and cost an extra model turn.

**Files:**
- Modify: `packages/Chat/src/Tools/BaseWriteUpdateTool.php:193-199`
- Test: `tests/Feature/Chat/BatchUpdateProposalTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: the tool result JSON gains an optional `skipped` key, a list of `{index, reason}` objects. Absent when nothing was skipped.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Chat/BatchUpdateProposalTest.php`:

```php
it('skips a no-op record and proposes the rest', function (): void {
    $keep = Company::factory()->for($this->user->currentTeam)->create(['name' => 'Unchanged']);
    $change = Company::factory()->for($this->user->currentTeam)->create(['name' => 'Before']);

    $result = ($this->tool)(UpdateCompanyTool::class)->handle(new Request([
        'records' => [
            ['id' => (string) $keep->getKey(), 'name' => 'Unchanged'],
            ['id' => (string) $change->getKey(), 'name' => 'After'],
        ],
    ]));

    expect($result)->not->toContain('Already up to date')
        ->and($result)->toContain('skipped');

    $proposal = PendingAction::query()->where('entity_type', 'company')->latest('id')->firstOrFail();

    expect($proposal->action_data['_batch'] ?? null)->toBeNull();
});
```

The last assertion encodes that one surviving record proposes as a flat single-record action, not a `_batch` of one. Match the file's existing helpers for building the tool and team.

- [ ] **Step 2: Run and confirm failure**

Run: `php artisan test --compact --filter=BatchUpdateProposal`
Expected: FAIL with `Already up to date` in the result.

- [ ] **Step 3: Collect skipped records instead of returning**

Declare `$skipped = [];` beside `$actionRecords` and `$items`. Replace the two no-op returns:

```php
if ($requestedRows === []) {
    $skipped[] = ['index' => $index, 'reason' => 'Nothing to update: no field that changes was passed.'];

    continue;
}

if ($displayData['fields'] === []) {
    $skipped[] = ['index' => $index, 'reason' => "Already up to date: every value matches what the {$label} has now."];

    continue;
}
```

Leave every other `return` in the loop untouched. A record that does not exist, or that the user cannot update, or that fails custom-field validation is a genuine error the model must fix, and aborting is correct there.

- [ ] **Step 4: Handle the all-skipped case and emit `skipped`**

After the loop, before the `$isBatch` line:

```php
if ($actionRecords === []) {
    return (string) json_encode([
        'error' => 'Nothing to update: every record passed already matches its stored values.',
        'skipped' => $skipped,
    ], JSON_UNESCAPED_SLASHES);
}
```

Add `skipped` to the success payload only when `$skipped !== []`, so an all-changed batch keeps its current shape.

- [ ] **Step 5: Run and confirm the tests pass**

Run: `php artisan test --compact --filter="BatchUpdateProposal|BatchCreateProposal|BatchSizeLimit"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/Chat/src/Tools/BaseWriteUpdateTool.php tests/Feature/Chat/BatchUpdateProposalTest.php
git commit -m "fix(chat): skip no-op records instead of failing the batch"
```

---

### Task 5: Do not propose writes to system-defined custom fields

The assistant offered to reactivate a system-defined Priority field, the user approved, and the write failed. The guard exists in the action; the tool should not propose the action at all.

**Files:**
- Modify: `packages/Chat/src/Tools/CustomField/UpdateCustomFieldTool.php:100-112`
- Test: `tests/Feature/Chat/UpdateCustomFieldToolTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: no signature change. The error is returned before a `PendingAction` is created.

- [ ] **Step 1: Write the failing test**

```php
it('refuses to propose a change to a system-defined field', function (): void {
    $field = CustomField::factory()->for($this->user->currentTeam, 'tenant')->create([
        'entity_type' => 'task',
        'code' => 'priority',
        'system_defined' => true,
    ]);

    $result = ($this->tool)(UpdateCustomFieldTool::class)->handle(new Request([
        'records' => [['entity_type' => 'task', 'code' => 'priority', 'active' => true]],
    ]));

    expect($result)->toContain('System-defined')
        ->and(PendingAction::query()->where('entity_type', 'custom_field')->count())->toBe(0);
});
```

Confirm the column that marks a system field; `isSystemDefined()` is used at `app/Actions/CustomFields/UpdateCustomField.php:20`, so read that method on the model and set whatever it reads.

- [ ] **Step 2: Run and confirm failure**

Run: `php artisan test --compact --filter=UpdateCustomFieldTool`
Expected: FAIL. A `PendingAction` is created, so the count assertion fails.

- [ ] **Step 3: Move the guard ahead of proposal creation**

The existing check at line ~108 already produces the right message. Confirm it runs before `createProposal()` is called; if it does not, move it into the per-record validation loop so no proposal is stored. Extend the message so the model has somewhere to send the user:

```php
return (string) json_encode(['error' => "records[{$index}]: System-defined custom fields cannot be modified from chat. The workspace owner can change them in custom field settings."], JSON_UNESCAPED_SLASHES);
```

- [ ] **Step 4: Run and confirm the test passes**

Run: `php artisan test --compact --filter=UpdateCustomFieldTool`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/Chat/src/Tools/CustomField/UpdateCustomFieldTool.php tests/Feature/Chat/UpdateCustomFieldToolTest.php
git commit -m "fix(chat): stop proposing writes to system-defined fields"
```

---

### Task 6: Raise the proposal expiry to 24 hours

Sixteen proposals expired across fourteen conversations. One user reported the approve button was gone by the time they returned. A proposal is inert until approved, so a fifteen-minute window buys no safety.

**Files:**
- Modify: `packages/Chat/config/chat.php:56`
- Test: `tests/Feature/Chat/` — find the file that already asserts expiry with `grep -rln "expires_at\|pending_action_expiry" tests/Feature/Chat`

**Interfaces:**
- Consumes: nothing.
- Produces: `config('chat.pending_action_expiry_minutes')` is `1440`.

- [ ] **Step 1: Write the failing test**

In the file found above, assert the window rather than the literal, so the test states intent:

```php
it('gives the user a full day to decide a proposal', function (): void {
    expect(config('chat.pending_action_expiry_minutes'))->toBe(1440);
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `php artisan test --compact --filter=<that file's filter>`
Expected: FAIL, actual 15.

- [ ] **Step 3: Change the config default**

```php
'pending_action_expiry_minutes' => 1440,
```

- [ ] **Step 4: Run and confirm it passes, then check the sweeper**

Run: `php artisan test --compact --filter="PendingAction|Expir"`
Expected: PASS. Confirm the scheduled command that expires stale proposals still runs on a cadence shorter than the new window; it is registered in `bootstrap/app.php` via `withSchedule()`.

- [ ] **Step 5: Commit**

```bash
git add packages/Chat/config/chat.php tests/Feature/Chat
git commit -m "fix(chat): give proposals 24 hours before they expire"
```

---

### Task 7: Paginate the activity tool

`ListActivityTool` returns at most 50 entries with no way to reach the rest. A user asked three times for the remaining 27 of 44 events. The tool currently emits `has_more` without `next_page` and documents that choice in a comment; this task reverses that decision, so the comment changes with it.

**Files:**
- Modify: `packages/Chat/src/Tools/Activity/ListActivityTool.php:71-135`
- Test: `tests/Feature/Chat/ListActivityToolTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: the request schema gains `page` (integer, default 1). The payload gains `next_page` when more entries exist, matching `BaseReadListTool`'s contract at `packages/Chat/src/Tools/BaseReadListTool.php:258-262`.

- [ ] **Step 1: Write the failing test**

This file already provides `activityPayload(array $input = []): array` and
`nextActivityRequest(): void`, and it deliberately runs without a Filament tenant. Use both, and
do not add `Filament::setTenant()`.

```php
it('returns a second page of activity entries', function (): void {
    // ENTRY_LIMIT is 50 and entries are grouped per save, so 55 separate
    // requests produce more than one page.
    foreach (range(1, 55) as $n) {
        nextActivityRequest();
        resolve(CreateCompany::class)->execute($this->user, ['name' => "Paged Co {$n}"]);
    }

    $first = activityPayload(['days' => 30]);

    expect($first['has_more'])->toBeTrue()
        ->and($first['next_page'])->toBe(2);

    $second = activityPayload(['days' => 30, 'page' => 2]);

    expect($second['data'])->not->toBeEmpty()
        ->and($second['data'])->not->toEqual($first['data'])
        ->and($second['next_page'])->toBeNull();
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `php artisan test --compact --filter=ListActivityTool`
Expected: FAIL. `next_page` is absent by design today.

- [ ] **Step 3: Add the page parameter**

In `entitySchema()` beside `days`:

```php
'page' => $schema->integer()->description('Which page of entries to return (default 1).')->default(1),
```

Add a `private function page(Request $request): int` mirroring the existing `days()` clamp, with a floor of 1.

- [ ] **Step 4: Offset the query and emit `next_page`**

Replace `->limit(self::ENTRY_LIMIT)` with an offset window:

```php
->forPage($page, self::ENTRY_LIMIT)
```

Then extend the payload, and rewrite the comment above it so it no longer claims the tool has no `page` parameter:

```php
'page' => $page,
'has_more' => $totalEntries > $page * self::ENTRY_LIMIT,
'next_page' => $totalEntries > $page * self::ENTRY_LIMIT ? $page + 1 : null,
```

Grouping by save happens after the fetch, so `showing` can be smaller than `ENTRY_LIMIT`. Keep `total` as `countEntries($scope)` and let `has_more` derive from it, not from the grouped count.

- [ ] **Step 5: Run and confirm the tests pass**

Run: `php artisan test --compact --filter=ListActivityTool`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/Chat/src/Tools/Activity/ListActivityTool.php tests/Feature/Chat/ListActivityToolTest.php
git commit -m "feat(chat): paginate the activity history tool"
```

---

### Task 8: Full gate and browser verification

Chat changes are not done until the production-shaped loop has been walked. This task carries the environment setup because nothing before it needs the queue.

**Files:** none modified. This task produces evidence.

- [ ] **Step 1: Start Horizon for this workspace**

The running workers belong to another checkout and Redis prefixes isolate the queues, so nothing currently drains this workspace's default queue.

Run: `php artisan horizon` in a background terminal, then `php artisan queue:monitor default` to confirm a worker is attached.

- [ ] **Step 2: Confirm the shared database question with the user before seeding**

`DB_DATABASE=relaticle_app` is shared with the `hong-kong` checkout. Ask before creating or mutating demo data; do not split the database unilaterally.

- [ ] **Step 3: Run the local quality gate in order**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
```

Expected: clean. If rector reports a phar path under a deleted workspace, clear the stale global cache with `rm -rf "$TMPDIR/cache"` and re-run; that error masks real findings.

- [ ] **Step 4: Run the full suite once**

Run: `pgrep -fl pest` first and wait if another checkout is running.
Run: `composer test:pest`
Expected: PASS.

- [ ] **Step 5: Walk the loop in a real browser**

Using agent-browser against this workspace's Herd URL, with Horizon running and Reverb up: send a message that creates a company and a contact linked to it in one turn, confirm one plan card renders, approve it, and confirm both records exist and are linked. Then repeat with a batched two-contact proposal referencing index 1, which is the case Task 2 exists for.

Capture a screenshot of the plan card and of the approved result.

- [ ] **Step 6: Report**

Summarise which tasks landed, paste the gate output, and attach the two screenshots. Do not claim the browser leg passed without them.

---

## Follow-up plan, not in scope here

The prompt restructure from spec sections 4 and 7 (registry-generated block map, tool names out of prose, `page_summary`, server-side block placement, replacing the 35 wording assertions, and the compliance harness) is a separate plan. It carries different risk, it depends on the registry work, and Tasks 2 and 3 above already prove whether the reference model is right before the prompt is rewritten around it.

Recommend writing that plan once Tasks 1 to 8 have landed and the browser walk is green.

## Correction: Task 5's defect never existed

Task 5 was written from the production audit, which read a `System-defined custom fields cannot be
modified.` tool error on 2026-08-25 as the assistant proposing a doomed write. It was not.

The guard at `UpdateCustomFieldTool.php:107-109` sits inside the per-record loop and returns before
`createProposal()` at line 158, so no proposal is ever stored, for any record in the batch. It was
introduced in `5c67199d0` (PR #367) and survived the batch refactor in `e135b618e` (PR #506).

The production transcript confirms correct behaviour end to end:
  10:49:16 assistant offers to reactivate the deactivated Priority field
  10:49:32 the tool refuses, and the assistant escorts the user to Custom Fields settings with a
           working deep link in the same turn
That is the No Dead Ends contract working as designed.

Task 5 therefore shipped regression tests only (`f758b1202`), with no production change.

**Lesson for the remaining tasks and for the audit method.** A production transcript tells you what
happened, not whether it was wrong. Two of the six "defects" in this plan turned out to be intended,
tested behaviour: both `$ref` guards and this one. Verify against the code before calling a
transcript observation a defect.

## Findings raised during execution, not in scope, awaiting a decision

Both surfaced in the Task 1 review. Neither is fixed. They need the user's call before becoming tasks.

1. **The read and filter path still hides deactivated custom fields.**
   `app/Mcp/Schema/CustomFieldFilterSchema.php:148` (`resolveFilterableFields()`) still applies
   `->active()`, and the result is cached for 60 seconds. Task 1 fixed the write-schema
   description only, so "show me the high-priority tasks" can still produce the original wrong
   answer on a workspace where Priority is deactivated, even though the values are still stored.
   This is a direct sibling of the bug Task 1 fixed and is the more likely user-facing path.

2. **Encrypted option labels can render as ciphertext for inactive fields.**
   `packages/Chat/src/Tools/CustomField/ListCustomFieldsTool.php:49-63` bypasses the activable
   scope, so `CustomFieldOption::name()` sees a null `customField` relation and returns the raw
   encrypted string instead of decrypting. `SelectFieldType` is encryptable and field encryption
   is enabled, so it is reachable. Task 1's describer no longer reaches this path because
   inactive fields render code and type only, so the exposure is confined to that one tool.
