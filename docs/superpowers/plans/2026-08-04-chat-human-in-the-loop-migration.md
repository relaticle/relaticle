# Chat Human-in-the-Loop Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the custom `pending_actions` approval system with `laravel/ai`'s human-in-the-loop tool approval, so approving a proposed write resumes the paused generation turn instead of ending it.

**Architecture:** Write tools become `Approvable`. The framework pauses the generation loop before `handle()` runs and persists the pause in `agent_conversation_messages.approval_state`. The dock renders the card from the paused call's arguments, and the user's decision dispatches a job that resumes the same turn with a `Decisions` map. The tool's `handle()` then executes the `App\Actions\*` call inside the resumed loop, and the model continues — possibly pausing again for the next write.

**Tech Stack:** PHP 8.4, Laravel 13, Filament 5, Livewire 4, Alpine 3, Pest 5, PostgreSQL, Reverb, Horizon, `laravel/ai` 0.10.2.

## Prerequisite

**PR 1 must be merged before starting.** This plan is written against the 0.10 API and the migrated schema. Confirm before Task 1:

```bash
composer show laravel/ai | grep versions
php artisan tinker --execute 'echo Schema::hasColumn("agent_conversation_messages", "approval_state") ? "ready" : "NOT READY";'
```

Expected: `v0.10.2` and `ready`.

## Global Constraints

- PostgreSQL only. No SQLite/MySQL compatibility layers, driver checks, or conditional SQL.
- Migrations have `up()` only. Never write a `down()` method.
- All write operations go through `App\Actions\<Domain>\` classes. `EloquentWriteOutsideActionRule` (PHPStan) fails any Eloquent write in a tool, controller, Livewire component, or Filament class. Tools call actions; they never write directly.
- User-facing strings must be wrapped in `__()`. Two PHPStan rules enforce this on guarded methods and static properties.
- Chat tools automatically support every active custom field via the bridge services in `packages/Chat/src/Services/Tools/`. Never add per-field schema slots, coercion, or display rows.
- Any write path outside a Filament panel request or `SetApiTeamContext` must set `TenantContextService::setTenantId()` before saving custom fields, wrapped in try/finally restoring the previous id. Otherwise `saveCustomFields()` iterates every tenant.
- Writing null/empty for a custom field is how a value is cleared. Never filter out "empty" values on save; only absent keys are left untouched.
- Pre-commit gate, in order: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:type-coverage` (100%), `php artisan test --compact`.
- Chat changes must be verified against Horizon + `QUEUE_CONNECTION=redis` + Reverb in a real browser. A sync queue masks message ordering, approval races, and duplicate proposals.
- Do not add new PHPStan ignores. When you delete a grandfathered file, remove its ignore entry from `phpstan.neon`.

## Key API facts, verified against vendor source

These were checked in `vendor/laravel/ai` rather than taken from the docs. Rely on them.

- A resume does **not** write a user message row. `RememberConversation::handle()` guards the `storeUserMessage()` call with `if (! $prompt->hasApprovalDecisions())`.
- `Decision::edit()` executes with the edited arguments and does **not** re-gate the call, so there is no approval loop (`TextGenerationLoop::resolveApprovalResults`).
- A tool that throws during a resume is caught and returned to the model as `The tool call failed: …`. The exception does not escape into the job.
- `Promptable::providersForApprovalContinuation()` only disables failover — it does **not** pin the provider that produced the pause. The caller must pass the original provider and model. `agent_conversation_messages.meta` carries both (`Laravel\Ai\Responses\Data\Meta` has `provider` and `model`).
- `approval_state` is shaped `{"pending": {"<toolCallId>": "<reason|null>"}}`.
- `storeApprovalResults()` is inherited from `DatabaseConversationStore` and needs no override.

---

### Task 1: Make BaseWriteCreateTool approvable and executing

The exemplar for Tasks 2 and 3. `handle()` stops creating a `PendingAction` and instead executes `CreateCompany`-style actions; validation moves to `needsApproval()` so invalid input never produces a card.

The fifteen concrete tool subclasses do **not** change. `entitySchema()`, `validateRecord()`, `extractRecordData()`, and `buildRecordDisplay()` keep their signatures — only their call timing shifts.

**Files:**
- Modify: `packages/Chat/src/Tools/BaseWriteCreateTool.php`
- Test: `tests/Feature/Chat/ApprovableWriteToolTest.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces:
  - `BaseWriteCreateTool implements Approvable, Tool` using `InteractsWithApprovals`.
  - `protected function needsApproval(Request $request): Approval|bool` — returns `false` when the request is invalid, `Approval::required(string $reason)` otherwise.
  - `public function handle(Request $request): string` — returns a JSON string shaped
    `{"created": [{"id": string, "type": string, "label": string}], "skipped": string[], "count": int}`
    on success, or `{"error": string}` on validation failure.
  - `private function validationFor(Request $request): array{error: ?string, records: list<array<string,mixed>>, displays: list<array<string,mixed>>}` — memoized per tool-call id.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Chat/ApprovableWriteToolTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;
use Relaticle\CustomFields\Services\TenantContextService;

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    TenantContextService::setTenantId($this->user->current_team_id);
});

it('gates a valid create behind approval instead of writing', function (): void {
    $tool = new CreateCompanyTool;

    expect($tool)->toBeInstanceOf(Approvable::class);

    $request = new Request(['records' => [['name' => 'Acme Corp']]], 'call_abc');

    expect($tool->shouldRequestApproval($request))->toBeInstanceOf(Approval::class)
        ->and(Company::query()->count())->toBe(0);
});

it('does not gate an invalid create, so the model gets the error immediately', function (): void {
    $tool = new CreateCompanyTool;

    $request = new Request(['records' => [['name' => 'Acme', 'account_owner_id' => 'not-a-member']]], 'call_bad');

    expect($tool->shouldRequestApproval($request))->toBeNull();

    $result = json_decode($tool->handle($request), true);

    expect($result)->toHaveKey('error')
        ->and(Company::query()->count())->toBe(0);
});

it('executes the action when handle runs after approval', function (): void {
    $tool = new CreateCompanyTool;

    $request = new Request(['records' => [['name' => 'Acme Corp']]], 'call_abc');

    $result = json_decode($tool->handle($request), true);

    expect($result['count'])->toBe(1)
        ->and($result['created'][0]['label'])->toBe('Acme Corp')
        ->and(Company::query()->where('name', 'Acme Corp')->exists())->toBeTrue();
});

it('commits each batch record independently so one failure does not lose the rest', function (): void {
    $tool = new CreateCompanyTool;

    $request = new Request(['records' => [
        ['name' => 'First'],
        ['name' => 'Second'],
    ]], 'call_batch');

    $result = json_decode($tool->handle($request), true);

    expect($result['count'])->toBe(2)
        ->and(Company::query()->whereIn('name', ['First', 'Second'])->count())->toBe(2);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact tests/Feature/Chat/ApprovableWriteToolTest.php
```

Expected: FAIL — `CreateCompanyTool` is not an instance of `Approvable`.

- [ ] **Step 3: Rewrite the base class**

Replace `packages/Chat/src/Tools/BaseWriteCreateTool.php`. The abstract hooks and the `records`/`plan` schema are unchanged from the current file — copy them across verbatim. What changes is the class declaration, the addition of `needsApproval()` plus the memoized `validationFor()`, and a `handle()` that executes.

```php
<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Enums\CreationSource;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Services\Tools\CustomFieldsDisplayFormatter;
use Relaticle\Chat\Services\Tools\CustomFieldsRequestValidator;
use Relaticle\Chat\Services\Tools\CustomFieldsSchemaDescriber;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;
use Throwable;

abstract class BaseWriteCreateTool implements Approvable, Tool
{
    use InteractsWithApprovals;
    use WithConversationContext;

    /**
     * Memoized validation keyed by tool-call id. shouldRequestApproval() and
     * handle() both need the parsed records, and the framework calls them in
     * the same pass — once at the pause, once again on resume.
     *
     * @var array<string, array{error: ?string, records: list<array<string, mixed>>, displays: list<array<string, mixed>>}>
     */
    private array $validated = [];

    /** @return class-string */
    abstract protected function actionClass(): string;

    abstract protected function entityType(): string;

    abstract public function description(): string;

    /** @return array<string, mixed> */
    abstract protected function entitySchema(JsonSchema $schema): array;

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    abstract protected function buildRecordDisplay(array $record): array;

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    abstract protected function extractRecordData(array $record): array;

    /**
     * Entity-specific per-record validation beyond custom fields. Return an
     * error message to abort, or null to proceed.
     *
     * @param  array<string, mixed>  $record
     */
    protected function validateRecord(array $record, User $user): ?string
    {
        return null;
    }

    public function schema(JsonSchema $schema): array
    {
        $user = auth()->user();

        $customFieldsDescription = $user instanceof User
            ? resolve(CustomFieldsSchemaDescriber::class)->describe($user->currentTeam, $this->entityType())
            : 'Custom field values as key-value pairs.';

        $recordProperties = array_merge(
            $this->entitySchema($schema),
            ['custom_fields' => $schema->object()->description($customFieldsDescription)],
        );

        return [
            'records' => $schema->array()
                ->items($schema->object($recordProperties))
                ->required()
                ->description(
                    "The {$this->entityType()} records to create. Pass ONE item for a single record,"
                    .' or up to '.config('chat.max_batch_size').' items to create them all in ONE proposal'
                    .' (never loop one call per record).',
                ),
        ];
    }

    /**
     * Invalid input must not produce an approval card. Returning false here
     * skips the pause so handle() runs immediately and hands the model its
     * error — the same self-correction loop the proposal flow had — without
     * writing anything.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        if ($this->validationFor($request)['error'] !== null) {
            return false;
        }

        $count = count($this->validationFor($request)['records']);

        return Approval::required(__('Creating :count :entity in your CRM.', [
            'count' => $count,
            'entity' => Str::plural($this->entityType(), $count),
        ]));
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $validation = $this->validationFor($request);

        if ($validation['error'] !== null) {
            return (string) json_encode(['error' => $validation['error']]);
        }

        $action = resolve($this->actionClass());
        $created = [];
        $skipped = [];

        // Each record commits on its own so a later failure keeps earlier
        // progress — the property the old per-item batch approval provided.
        foreach ($validation['records'] as $index => $record) {
            try {
                /** @var Model $model */
                $model = DB::transaction(
                    fn (): Model => $action->execute($user, $record, CreationSource::CHAT)
                );

                $created[] = [
                    'id' => (string) $model->getKey(),
                    'type' => $model->getMorphClass(),
                    'label' => (string) ($record['name'] ?? $record['title'] ?? ''),
                ];
            } catch (Throwable $exception) {
                $skipped[] = sprintf(
                    '%s: %s',
                    $record['name'] ?? $record['title'] ?? "record {$index}",
                    $exception->getMessage(),
                );
            }
        }

        return (string) json_encode([
            'created' => $created,
            'skipped' => $skipped,
            'count' => count($created),
        ], JSON_PRETTY_PRINT);
    }

    /**
     * @return array{error: ?string, records: list<array<string, mixed>>, displays: list<array<string, mixed>>}
     */
    private function validationFor(Request $request): array
    {
        $cacheKey = $request->toolCallId() ?? spl_object_hash($request);

        if (isset($this->validated[$cacheKey])) {
            return $this->validated[$cacheKey];
        }

        return $this->validated[$cacheKey] = $this->validate($request);
    }

    /**
     * @return array{error: ?string, records: list<array<string, mixed>>, displays: list<array<string, mixed>>}
     */
    private function validate(Request $request): array
    {
        $fail = fn (string $message): array => ['error' => $message, 'records' => [], 'displays' => []];

        /** @var User $user */
        $user = auth()->user();

        $records = $request['records'] ?? null;

        if (! is_array($records) || $records === []) {
            return $fail('Provide `records` — a non-empty array of records to create.');
        }

        $maxBatchSize = (int) config('chat.max_batch_size');

        if (count($records) > $maxBatchSize) {
            return $fail("Too many records — at most {$maxBatchSize} per proposal.");
        }

        $validator = resolve(CustomFieldsRequestValidator::class);
        $formatter = resolve(CustomFieldsDisplayFormatter::class);

        $actionRecords = [];
        $displays = [];

        foreach (array_values($records) as $index => $record) {
            if (! is_array($record)) {
                return $fail("records[{$index}] must be an object.");
            }

            $validation = $validator->validate($user, $this->entityType(), $record['custom_fields'] ?? null);

            if ($validation->error !== null) {
                return $fail("records[{$index}]: {$validation->error}");
            }

            $recordError = $this->validateRecord($record, $user);

            if ($recordError !== null) {
                return $fail("records[{$index}]: {$recordError}");
            }

            $data = $this->extractRecordData($record);

            if ($validation->cleanFields !== []) {
                $data['custom_fields'] = $validation->cleanFields;
            }

            $display = $this->buildRecordDisplay($record);
            $customFieldRows = $formatter->format($user, $this->entityType(), $validation->cleanFields, oldModel: null);

            if ($customFieldRows !== []) {
                $existingFields = $display['fields'] ?? [];
                $display['fields'] = array_merge(is_array($existingFields) ? $existingFields : [], $customFieldRows);
            }

            $actionRecords[] = $data;
            $displays[] = $display;
        }

        return ['error' => null, 'records' => $actionRecords, 'displays' => $displays];
    }
}
```

- [ ] **Step 4: Note the tool-call id accessor**

`Laravel\Ai\Tools\Request` in 0.10.2 is `__construct(protected array $arguments = [], protected ?string $toolCallId = null)`. The id is **protected** and reachable only through the public `toolCallId(): ?string` method — property access fatals. `TextGenerationLoop` constructs it as `new Request($toolCall->arguments, $toolCall->id)`, and the id is null for a tool invoked outside a tool-call context, which is why `validationFor()` falls back to `spl_object_hash()`.

No action needed beyond using `$request->toolCallId()` as written above.

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test --compact tests/Feature/Chat/ApprovableWriteToolTest.php
```

Expected: all four tests PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/Chat/src/Tools/BaseWriteCreateTool.php tests/Feature/Chat/ApprovableWriteToolTest.php
git commit -m "feat: make chat create tools approvable and executing"
```

---

### Task 2: Make BaseWriteUpdateTool approvable and executing

Same shape as Task 1. Update is never batched — one record per call — so `handle()` executes a single action.

**Files:**
- Modify: `packages/Chat/src/Tools/BaseWriteUpdateTool.php`
- Test: `tests/Feature/Chat/ApprovableWriteToolTest.php` (extend)

**Interfaces:**
- Consumes: the `Approvable` pattern established in Task 1.
- Produces:
  - `BaseWriteUpdateTool implements Approvable, Tool` using `InteractsWithApprovals`.
  - `handle()` returns `{"updated": {"id": string, "type": string, "label": string}}` or `{"error": string}`.
  - `private function validationFor(Request $request): array{error: ?string, model: ?Model, data: array<string, mixed>}` — memoized per tool-call id.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Chat/ApprovableWriteToolTest.php`:

```php
it('gates an update behind approval and applies it only on execute', function (): void {
    $company = Company::factory()->create([
        'team_id' => $this->user->current_team_id,
        'name' => 'Old Name',
    ]);

    $tool = new Relaticle\Chat\Tools\Company\UpdateCompanyTool;

    $request = new Request(['id' => (string) $company->getKey(), 'name' => 'New Name'], 'call_upd');

    expect($tool->shouldRequestApproval($request))->toBeInstanceOf(Approval::class)
        ->and($company->fresh()->name)->toBe('Old Name');

    $result = json_decode($tool->handle($request), true);

    expect($result['updated']['label'])->toBe('New Name')
        ->and($company->fresh()->name)->toBe('New Name');
});

it('does not gate an update to a record outside the team', function (): void {
    $other = User::factory()->withTeam()->create();
    $foreign = Company::factory()->create(['team_id' => $other->current_team_id, 'name' => 'Theirs']);

    $tool = new Relaticle\Chat\Tools\Company\UpdateCompanyTool;

    $request = new Request(['id' => (string) $foreign->getKey(), 'name' => 'Hijacked'], 'call_x');

    expect($tool->shouldRequestApproval($request))->toBeNull()
        ->and(json_decode($tool->handle($request), true))->toHaveKey('error')
        ->and($foreign->fresh()->name)->toBe('Theirs');
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact --filter="gates an update behind approval"
```

Expected: FAIL — `UpdateCompanyTool` is not `Approvable`.

- [ ] **Step 3: Rewrite the base class**

Modify `packages/Chat/src/Tools/BaseWriteUpdateTool.php`. Keep `schema()`, the abstract hooks, and `validateRequest()` exactly as they are today. Change the class declaration to `implements Approvable, Tool` with `use InteractsWithApprovals;`, and restructure the current `handle()` body into `validate()`:

Move into `validate(Request $request): array` — returning `['error' => ?string, 'model' => ?Model, 'data' => array]` — everything the current `handle()` does up to and including building `$actionData`, minus the `_record_id` and `_model_class` keys, which no longer exist because the model is resolved in-process. Specifically: the model lookup scoped by `whereBelongsTo($user->currentTeam)`, the `$user->cannot('update', $model)` check, the `CustomFieldsRequestValidator` call, `validateRequest()`, and `extractActionData()`.

Then:

```php
    protected function needsApproval(Request $request): Approval|bool
    {
        if ($this->validationFor($request)['error'] !== null) {
            return false;
        }

        return Approval::required(__('Updating this :entity in your CRM.', [
            'entity' => strtolower($this->entityLabel()),
        ]));
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $validation = $this->validationFor($request);

        if ($validation['error'] !== null) {
            return (string) json_encode(['error' => $validation['error']]);
        }

        /** @var Model $model */
        $model = $validation['model'];

        resolve($this->actionClass())->execute($user, $model, $validation['data']);

        $fresh = $model->fresh() ?? $model;

        return (string) json_encode([
            'updated' => [
                'id' => (string) $fresh->getKey(),
                'type' => $fresh->getMorphClass(),
                'label' => (string) ($fresh->getAttribute('name') ?? $fresh->getAttribute('title') ?? ''),
            ],
        ], JSON_PRETTY_PRINT);
    }
```

Add the same memoizing `validationFor()` helper as Task 1, with the update-shaped return type.

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test --compact tests/Feature/Chat/ApprovableWriteToolTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/Chat/src/Tools/BaseWriteUpdateTool.php tests/Feature/Chat/ApprovableWriteToolTest.php
git commit -m "feat: make chat update tools approvable and executing"
```

---

### Task 3: Make BaseWriteDeleteTool approvable and executing

Delete takes `ids[]` and may be a batch. Records the user cannot delete, or that do not exist, are reported as `skipped` at validation time rather than silently dropped.

**Files:**
- Modify: `packages/Chat/src/Tools/BaseWriteDeleteTool.php`
- Test: `tests/Feature/Chat/ApprovableWriteToolTest.php` (extend)

**Interfaces:**
- Consumes: the `Approvable` pattern from Task 1.
- Produces:
  - `BaseWriteDeleteTool implements Approvable, Tool` using `InteractsWithApprovals`.
  - `handle()` returns `{"deleted": string[], "skipped": string[], "count": int}` or `{"error": string}`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Chat/ApprovableWriteToolTest.php`:

```php
it('gates a batch delete and removes every approved record on execute', function (): void {
    $first = Company::factory()->create(['team_id' => $this->user->current_team_id, 'name' => 'First']);
    $second = Company::factory()->create(['team_id' => $this->user->current_team_id, 'name' => 'Second']);

    $tool = new Relaticle\Chat\Tools\Company\DeleteCompanyTool;

    $request = new Request(['ids' => [(string) $first->getKey(), (string) $second->getKey()]], 'call_del');

    expect($tool->shouldRequestApproval($request))->toBeInstanceOf(Approval::class)
        ->and(Company::query()->count())->toBe(2);

    $result = json_decode($tool->handle($request), true);

    expect($result['count'])->toBe(2)
        ->and(Company::query()->count())->toBe(0);
});

it('does not gate a delete when no requested record is deletable', function (): void {
    $tool = new Relaticle\Chat\Tools\Company\DeleteCompanyTool;

    $request = new Request(['ids' => ['01JQZZZZZZZZZZZZZZZZZZZZZZ']], 'call_none');

    expect($tool->shouldRequestApproval($request))->toBeNull()
        ->and(json_decode($tool->handle($request), true))->toHaveKey('error');
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact --filter="gates a batch delete"
```

Expected: FAIL — `DeleteCompanyTool` is not `Approvable`.

- [ ] **Step 3: Rewrite the base class**

Modify `packages/Chat/src/Tools/BaseWriteDeleteTool.php`. Keep `schema()`, `requestedIds()`, and the abstract hooks unchanged. Move the current `handle()` body up to and including the `$deletable`/`$skipped` computation into `validate(Request $request): array` returning `['error' => ?string, 'models' => Collection<int, Model>, 'skipped' => list<string>]`, and add the memoized `validationFor()`.

```php
    protected function needsApproval(Request $request): Approval|bool
    {
        if ($this->validationFor($request)['error'] !== null) {
            return false;
        }

        $count = $this->validationFor($request)['models']->count();

        return Approval::required(__('Permanently deleting :count :entity. This cannot be undone.', [
            'count' => $count,
            'entity' => Str::plural(strtolower($this->entityLabel()), $count),
        ]));
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $validation = $this->validationFor($request);

        if ($validation['error'] !== null) {
            return (string) json_encode(['error' => $validation['error'], 'skipped' => $validation['skipped']]);
        }

        $action = resolve($this->actionClass());
        $deleted = [];
        $skipped = $validation['skipped'];

        foreach ($validation['models'] as $model) {
            try {
                DB::transaction(fn () => $action->execute($user, $model));
                $deleted[] = (string) $model->getKey();
            } catch (Throwable $exception) {
                $skipped[] = sprintf('%s: %s', $model->getKey(), $exception->getMessage());
            }
        }

        return (string) json_encode([
            'deleted' => $deleted,
            'skipped' => $skipped,
            'count' => count($deleted),
        ], JSON_PRETTY_PRINT);
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test --compact tests/Feature/Chat/ApprovableWriteToolTest.php
```

Expected: PASS.

- [ ] **Step 5: Sweep the sibling tools for the same class of bug**

A bug in one write tool almost always has siblings. Confirm all fifteen concrete tools still resolve and expose the expected contract:

```bash
php artisan test --compact tests/Feature/Chat
```

Expected: the create/update/delete tool tests pass. Tests that assert the old `pending_action` JSON shape will fail — leave them; Task 9 deletes or rewrites them.

- [ ] **Step 6: Commit**

```bash
git add packages/Chat/src/Tools/BaseWriteDeleteTool.php tests/Feature/Chat/ApprovableWriteToolTest.php
git commit -m "feat: make chat delete tools approvable and executing"
```

---

### Task 4: Teach ProcessChatMessage to resume with decisions

The job currently takes a message string. It now takes either a message or a decision map. Four correctness concerns land here: provider pinning, credits, tenant context, and the retry guard.

**Files:**
- Create: `packages/Chat/src/Data/TurnInput.php`
- Modify: `packages/Chat/src/Jobs/ProcessChatMessage.php`
- Modify: `packages/Chat/src/Http/Controllers/ChatController.php:153`
- Test: `tests/Feature/Chat/ApprovalResumeTest.php`

**Interfaces:**
- Consumes: the approvable tools from Tasks 1–3.
- Produces:
  - `Relaticle\Chat\Data\TurnInput` — `final readonly` with
    `public static function message(string $text, array $document, array $mentions): self`,
    `public static function decisions(array $decisions): self`,
    `public function isResume(): bool`,
    `public function toDecisions(): Decisions`,
    `public function text(): string`.
    The `$decisions` array is shaped `array<string, array{action: 'approve'|'reject'|'edit', result?: ?string, arguments?: array<string, mixed>}>` — a plain array so the queue payload stays serializable and versionable.
  - `ProcessChatMessage::__construct(User $user, Team $team, TurnInput $input, string $conversationId, array $resolved, ?array $pageContext, string $turnId)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Chat/ApprovalResumeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Data\TurnInput;
use Relaticle\Chat\Jobs\ProcessChatMessage;

it('pins the resume to the provider that produced the pause', function (): void {
    $user = User::factory()->withTeam()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'team_id' => $user->current_team_id,
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'agent' => 'crm',
        'role' => 'assistant',
        'content' => 'proposing',
        'attachments' => '[]',
        'tool_calls' => json_encode([['id' => 'call_abc', 'name' => 'create_company', 'arguments' => []]]),
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => json_encode(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']),
        'approval_state' => json_encode(['pending' => ['call_abc' => null]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new ProcessChatMessage(
        user: $user,
        team: $user->currentTeam,
        input: TurnInput::decisions(['call_abc' => ['action' => 'approve']]),
        conversationId: $conversationId,
        resolved: ['provider' => 'openai', 'model' => 'gpt-5.5'],
        pageContext: null,
        turnId: (string) Str::uuid(),
    );

    expect($job->resolveProviderForTurn())->toBe(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);
});

it('treats an already-resolved approval as a plain turn instead of resubmitting', function (): void {
    $user = User::factory()->withTeam()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'team_id' => $user->current_team_id,
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'agent' => 'crm',
        'role' => 'assistant',
        'content' => 'done',
        'attachments' => '[]',
        'tool_calls' => json_encode([['id' => 'call_abc', 'name' => 'create_company', 'arguments' => []]]),
        'tool_results' => json_encode([['id' => 'call_abc', 'name' => 'create_company', 'arguments' => [], 'result' => 'created']]),
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new ProcessChatMessage(
        user: $user,
        team: $user->currentTeam,
        input: TurnInput::decisions(['call_abc' => ['action' => 'approve']]),
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        pageContext: null,
        turnId: (string) Str::uuid(),
    );

    expect($job->hasResumableApproval())->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact tests/Feature/Chat/ApprovalResumeTest.php
```

Expected: FAIL — `Relaticle\Chat\Data\TurnInput` does not exist.

- [ ] **Step 3: Create the TurnInput value object**

```php
<?php

declare(strict_types=1);

namespace Relaticle\Chat\Data;

use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;

final readonly class TurnInput
{
    /**
     * @param  list<array{type: string, id: string, label: string}>  $mentions
     * @param  array<string, mixed>  $document
     * @param  array<string, array{action: string, result?: ?string, arguments?: array<string, mixed>}>  $decisionMap
     */
    private function __construct(
        public string $text,
        public array $document,
        public array $mentions,
        public array $decisionMap,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array{type: string, id: string, label: string}>  $mentions
     */
    public static function message(string $text, array $document = ['type' => 'doc', 'content' => []], array $mentions = []): self
    {
        return new self($text, $document, $mentions, []);
    }

    /**
     * @param  array<string, array{action: string, result?: ?string, arguments?: array<string, mixed>}>  $decisions
     */
    public static function decisions(array $decisions): self
    {
        return new self('', ['type' => 'doc', 'content' => []], [], $decisions);
    }

    public function isResume(): bool
    {
        return $this->decisionMap !== [];
    }

    public function toDecisions(): Decisions
    {
        return Decisions::from(array_map(
            static fn (array $decision): Decision => match ($decision['action']) {
                'approve' => Decision::approve(),
                'reject' => Decision::reject($decision['result'] ?? null),
                'edit' => Decision::edit($decision['arguments'] ?? []),
                default => Decision::reject(),
            },
            $this->decisionMap,
        ));
    }
}
```

- [ ] **Step 4: Rework the job**

In `packages/Chat/src/Jobs/ProcessChatMessage.php`:

Replace the `message`, `mentions`, and `document` constructor parameters with `public readonly TurnInput $input`. Update `persistMentions()`, `persistUserDocument()`, and `persistFailedTurn()` to read `$this->input->mentions`, `$this->input->document`, and `$this->input->text`.

Add the three new public methods the test drives:

```php
    /**
     * The provider and model that produced the paused turn. A resume MUST run
     * on them: the paused row's raw provider content blocks are only valid
     * verbatim on their own provider, and the user may have switched models in
     * the picker between proposing and approving.
     *
     * @return array{provider: string, model: string}
     */
    public function resolveProviderForTurn(): array
    {
        if (! $this->input->isResume()) {
            return $this->resolved;
        }

        $meta = (array) json_decode((string) DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversationId)
            ->whereNotNull('approval_state')
            ->latest('created_at')
            ->orderByDesc('id')
            ->value('meta'), true);

        return [
            'provider' => is_string($meta['provider'] ?? null) ? $meta['provider'] : $this->resolved['provider'],
            'model' => is_string($meta['model'] ?? null) ? $meta['model'] : $this->resolved['model'],
        ];
    }

    /**
     * Whether the decisions this job carries still match an unresolved pause.
     *
     * laravel/ai stores an approved tool's result BEFORE asking the model to
     * continue, so a mid-resume rate-limit release would come back and
     * resubmit decisions for calls that are already answered — an
     * ApprovalMismatchException, and worse, a second execution if the guard
     * were absent. Resubmitting is never correct; continue as a plain turn.
     */
    public function hasResumableApproval(): bool
    {
        if (! $this->input->isResume()) {
            return false;
        }

        $row = DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversationId)
            ->whereNotNull('approval_state')
            ->latest('created_at')
            ->orderByDesc('id')
            ->first(['approval_state']);

        if ($row === null) {
            return false;
        }

        $state = json_decode((string) $row->approval_state, true);
        $pending = is_array($state) && is_array($state['pending'] ?? null) ? array_keys($state['pending']) : [];

        return array_intersect(array_keys($this->input->decisionMap), $pending) !== [];
    }
```

In `handle()`, replace the supersede block (currently lines 112–126) — that mechanism is gone — and drive the prompt from the input:

```php
        $providerForTurn = $this->resolveProviderForTurn();

        $prompt = $this->input->isResume() && $this->hasResumableApproval()
            ? $this->input->toDecisions()
            : $this->input->text;

        $response = $agent->stream(
            prompt: $prompt,
            provider: $providerForTurn['provider'],
            model: $providerForTurn['model'],
        );
```

Also remove the `withSupersededProposals()` and `withResolvedActions()` calls and the `summarizeSuperseded()` method — Task 8 deletes their counterparts on the agent.

Wrap the streaming block in tenant context, mirroring `SetApiTeamContext`. This replaces what `PendingActionService::approve()` used to do, and it is required: an approved tool's `handle()` saves custom fields inside the job, where no Filament tenant is bound.

```php
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId((string) $this->team->getKey());

        try {
            // ... existing stream + then() block ...
        } finally {
            TenantContextService::setTenantId($previousTenantId);
            $this->releaseAuth();
        }
```

- [ ] **Step 5: Update the dispatch site**

In `packages/Chat/src/Http/Controllers/ChatController.php:153`:

```php
        dispatch(new ProcessChatMessage(
            user: $user,
            team: $team,
            input: TurnInput::message($parsed['text'], $validated['document'], $parsed['mentions']),
            conversationId: $conversation,
            resolved: $resolved,
            pageContext: $this->resolvePageContext($validated['page_context'] ?? null, $user),
            turnId: $turnId,
        ));
```

- [ ] **Step 6: Update every other construction site**

```bash
grep -rn "new ProcessChatMessage" --include="*.php" tests/ packages/
```

Migrate all of them to the `TurnInput` signature. Per project rules there is no dual old/new path — do not add a backwards-compatible string overload.

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Chat/ApprovalResumeTest.php
php artisan test --compact tests/Feature/Chat/TurnSerializationTest.php tests/Feature/Chat/ChatRateLimitTest.php tests/Feature/Chat/MentionsPersistenceTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add packages/Chat/src/Data/TurnInput.php packages/Chat/src/Jobs/ProcessChatMessage.php packages/Chat/src/Http/Controllers/ChatController.php tests/
git commit -m "feat: resume paused chat turns with approval decisions"
```

---

### Task 5: Broadcast the approval pause without blowing the payload cap

`ToolApprovalRequest::toArray()` includes every pending call's full arguments. A 25-record batch exceeds Reverb's 10 KB frame limit, and `StreamEventBroadcaster` currently swallows that failure as a breadcrumb — the card would simply never appear. Send ids and tool names only; the client renders server-side from history.

**Files:**
- Modify: `packages/Chat/src/Support/StreamEventBroadcaster.php`
- Test: `tests/Feature/Chat/StreamEventBroadcasterTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: a `tool_approval_request` broadcast shaped
  `{"type": "tool_approval_request", "invocation_id": string, "approvals": [{"id": string, "tool": string}]}`.

- [ ] **Step 1: Write the failing test**

Create or extend `tests/Feature/Chat/StreamEventBroadcasterTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Relaticle\Chat\Support\StreamEventBroadcaster;

it('strips arguments from an approval request so a large batch still fits the frame', function (): void {
    $records = array_map(
        static fn (int $i): array => ['name' => str_repeat("Company {$i} ", 40)],
        range(1, 25),
    );

    $event = new ToolApprovalRequest(
        id: 'req_1',
        pendingApprovals: new Collection([
            new PendingApproval('call_abc', 'create_company', ['records' => $records], 'Creating 25 companies.'),
        ]),
        timestamp: 0,
    );

    $payload = StreamEventBroadcaster::payloadFor($event);

    expect($payload['as'])->toBe('tool_approval_request')
        ->and($payload['with']['approvals'])->toBe([['id' => 'call_abc', 'tool' => 'create_company']])
        ->and(strlen((string) json_encode($payload['with'])))->toBeLessThan(10240);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact tests/Feature/Chat/StreamEventBroadcasterTest.php
```

Expected: FAIL — the payload contains full arguments and exceeds 10 KB.

- [ ] **Step 3: Add the slim payload branch**

In `packages/Chat/src/Support/StreamEventBroadcaster.php`, add to `payloadFor()` before the generic `toArray()` fallback:

```php
        if ($event instanceof ToolApprovalRequest) {
            return [
                'as' => $event->type(),
                'with' => [
                    'type' => 'tool_approval_request',
                    'invocation_id' => $event->invocationId,
                    'approvals' => $event->pendingApprovals
                        ->map(fn (PendingApproval $approval): array => [
                            'id' => $approval->id,
                            'tool' => $approval->tool,
                        ])
                        ->values()
                        ->all(),
                ],
            ];
        }
```

Import `Laravel\Ai\Approvals\PendingApproval` and `Laravel\Ai\Streaming\Events\ToolApprovalRequest`. Update the class docblock to record why arguments are stripped.

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test --compact tests/Feature/Chat/StreamEventBroadcasterTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/Chat/src/Support/StreamEventBroadcaster.php tests/Feature/Chat/StreamEventBroadcasterTest.php
git commit -m "feat: broadcast a slim tool approval request payload"
```

---

### Task 6: Render and resolve the card from the paused turn

`ProposalCard` stops loading a `PendingAction` and starts loading the paused tool call from conversation history. Per-item batch choices move into component state, and submitting dispatches the resume job.

**Files:**
- Modify: `packages/Chat/src/Livewire/Chat/ProposalCard.php`
- Modify: `packages/Chat/resources/views/livewire/chat/proposal-card.blade.php`
- Modify: `packages/Chat/resources/views/livewire/chat/partials/_proposal-card.blade.php`
- Test: `tests/Feature/Chat/ProposalCardComponentTest.php` (rewrite)

**Interfaces:**
- Consumes: `TurnInput::decisions()` from Task 4, the slim broadcast from Task 5.
- Produces:
  - `ProposalCard::$toolCallId` replaces `$pendingActionId`.
  - `ProposalCard::$itemDecisions` — `array<int, 'approve'|'skip'>`, the per-record review state.
  - `ProposalCard::submit(): void` — builds the decision map and dispatches `ProcessChatMessage`.
  - Listener `#[On('proposal:set-active')] setActive(?string $toolCallId = null, string $context = 'conversation')`.

- [ ] **Step 1: Write the failing test**

Rewrite `tests/Feature/Chat/ProposalCardComponentTest.php` around the new source of truth. The batch test is the one that matters — it proves a partial review becomes a `Decision::edit`, which is the whole Q1 design decision.

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Livewire\Chat\ProposalCard;

use function Pest\Livewire\livewire;

function seedPausedCall(User $user, string $conversationId, array $arguments): void
{
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'team_id' => $user->current_team_id,
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'agent' => 'crm',
        'role' => 'assistant',
        'content' => 'proposing',
        'attachments' => '[]',
        'tool_calls' => json_encode([[
            'id' => 'call_abc',
            'name' => 'create_company',
            'arguments' => $arguments,
        ]]),
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => json_encode(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']),
        'approval_state' => json_encode(['pending' => ['call_abc' => 'Creating 2 companies.']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    Bus::fake();
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
});

it('renders the card from the paused tool call arguments', function (): void {
    $conversationId = (string) Str::uuid();
    seedPausedCall($this->user, $conversationId, ['records' => [['name' => 'Acme Corp']]]);

    livewire(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', 'call_abc', 'conversation')
        ->assertSee('Acme Corp');
});

it('submits an edit decision carrying only the approved records', function (): void {
    $conversationId = (string) Str::uuid();
    seedPausedCall($this->user, $conversationId, ['records' => [
        ['name' => 'Keep Me'],
        ['name' => 'Skip Me'],
    ]]);

    livewire(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', 'call_abc', 'conversation')
        ->set('itemDecisions', [0 => 'approve', 1 => 'skip'])
        ->call('submit');

    Bus::assertDispatched(ProcessChatMessage::class, function (ProcessChatMessage $job): bool {
        $decision = $job->input->decisionMap['call_abc'];

        return $decision['action'] === 'edit'
            && $decision['arguments']['records'] === [['name' => 'Keep Me']];
    });
});

it('submits a plain approve when every record is kept', function (): void {
    $conversationId = (string) Str::uuid();
    seedPausedCall($this->user, $conversationId, ['records' => [['name' => 'Only One']]]);

    livewire(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', 'call_abc', 'conversation')
        ->set('itemDecisions', [0 => 'approve'])
        ->call('submit');

    Bus::assertDispatched(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool =>
        $job->input->decisionMap['call_abc']['action'] === 'approve');
});

it('submits a rejection when every record is skipped', function (): void {
    $conversationId = (string) Str::uuid();
    seedPausedCall($this->user, $conversationId, ['records' => [['name' => 'Nope']]]);

    livewire(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', 'call_abc', 'conversation')
        ->set('itemDecisions', [0 => 'skip'])
        ->call('submit');

    Bus::assertDispatched(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool =>
        $job->input->decisionMap['call_abc']['action'] === 'reject');
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact tests/Feature/Chat/ProposalCardComponentTest.php
```

Expected: FAIL — `setActive` still expects a pending-action id.

- [ ] **Step 3: Rework the component**

In `packages/Chat/src/Livewire/Chat/ProposalCard.php`:

Replace `loadPending(string $id): ?PendingAction` with a loader that reads the paused call from history:

```php
    /**
     * @return array{id: string, tool: string, arguments: array<string, mixed>, reason: ?string, conversation_id: string}|null
     */
    private function loadPausedCall(string $toolCallId): ?array
    {
        $user = $this->authUser();

        $row = DB::table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.participant_type', $user->getMorphClass())
            ->where('m.participant_id', $user->getKey())
            ->where('c.team_id', $user->current_team_id)
            ->whereNotNull('m.approval_state')
            ->whereNull('m.superseded_at')
            ->latest('m.created_at')
            ->orderByDesc('m.id')
            ->first(['m.conversation_id', 'm.tool_calls', 'm.tool_results', 'm.approval_state']);

        if ($row === null) {
            return null;
        }

        $pending = ((array) json_decode((string) $row->approval_state, true))['pending'] ?? [];

        if (! is_array($pending) || ! array_key_exists($toolCallId, $pending)) {
            return null;
        }

        // A call that already has a result is resolved, not pending.
        $answered = collect((array) json_decode((string) $row->tool_results, true))->pluck('id')->all();

        if (in_array($toolCallId, $answered, true)) {
            return null;
        }

        $call = collect((array) json_decode((string) $row->tool_calls, true))
            ->first(static fn (array $toolCall): bool => ($toolCall['id'] ?? null) === $toolCallId);

        if (! is_array($call)) {
            return null;
        }

        return [
            'id' => $toolCallId,
            'tool' => (string) $call['name'],
            'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
            'reason' => is_string($pending[$toolCallId]) ? $pending[$toolCallId] : null,
            'conversation_id' => (string) $row->conversation_id,
        ];
    }
```

Replace `createCurrent()`, `discardCurrent()`, `stepNext()`/`stepPrev()`'s resolved-item logic, and the `approveItem`/`rejectItem` calls with `submit()`:

```php
    public function submit(): void
    {
        $call = $this->loadPausedCall($this->toolCallId ?? '');

        if ($call === null) {
            return;
        }

        $user = $this->authUser();
        $records = $this->recordsFor($call);
        $kept = [];

        foreach ($records as $index => $record) {
            if (($this->itemDecisions[$index] ?? 'approve') === 'approve') {
                $kept[] = $record;
            }
        }

        $decision = match (true) {
            $kept === [] => ['action' => 'reject', 'result' => __('The user reviewed and skipped every record.')],
            count($kept) === count($records) => ['action' => 'approve'],
            default => ['action' => 'edit', 'arguments' => [...$call['arguments'], 'records' => $kept]],
        };

        dispatch(new ProcessChatMessage(
            user: $user,
            team: $user->currentTeam,
            input: TurnInput::decisions([$call['id'] => $decision]),
            conversationId: $call['conversation_id'],
            resolved: resolve(AiModelResolver::class)->resolve($user, null),
            pageContext: null,
            turnId: (string) Str::uuid(),
        ));

        $this->toolCallId = null;
        $this->itemDecisions = [];

        $this->dispatch('proposal:submitted', toolCallId: $call['id'], context: $this->context);
    }

    /**
     * The reviewable records for a call. Create tools use `records[]`; update
     * and delete are single-decision, so they present as one item.
     *
     * @param  array{arguments: array<string, mixed>}  $call
     * @return list<array<string, mixed>>
     */
    private function recordsFor(array $call): array
    {
        $records = $call['arguments']['records'] ?? null;

        return is_array($records) && $records !== [] ? array_values($records) : [$call['arguments']];
    }
```

The `resolved` argument still passes the user's current model, but Task 4's `resolveProviderForTurn()` overrides it from the paused row — the picker selection is only a fallback.

Keep `editField()`, `saveField()`, and `cancelField()`, but have `saveField()` write into `$this->itemDecisions`-adjacent component state rather than calling `ProposalEditor`. Edited records ride out in the `edit` decision's `arguments`, so `ProposalEditor` is no longer needed.

Update the two Blade partials to iterate `recordsFor()` output and bind the approve/skip toggles to `itemDecisions`, replacing the `action.status`/`itemResult()` conditionals.

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test --compact tests/Feature/Chat/ProposalCardComponentTest.php
```

Expected: all four tests PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/Chat/src/Livewire/Chat/ProposalCard.php packages/Chat/resources/views/livewire/chat tests/Feature/Chat/ProposalCardComponentTest.php
git commit -m "feat: render and resolve approval cards from conversation history"
```

---

### Task 7: Rebuild the transcript from tool calls and results

`ListConversationMessages` currently joins `pending_actions` for card status. That table is going away. Status now derives from the message row itself: a call in `approval_state` with no matching entry in `tool_results` is pending; a call with a result is resolved, and the result JSON says what happened.

**Files:**
- Modify: `packages/Chat/src/Actions/ListConversationMessages.php:57-77`
- Modify: `packages/Chat/src/Livewire/Chat/ChatInterface.php:120-180`
- Modify: `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php`
- Test: `tests/Browser/Chat/ProposalOutcomeSummaryTest.php` (update fixtures)

**Interfaces:**
- Consumes: Tasks 1–3's tool result shapes (`created`/`updated`/`deleted` + `skipped` + `count`).
- Produces: each transcript message carries
  `approvals: list<array{id: string, tool: string, status: 'pending'|'approved'|'rejected', arguments: array<string, mixed>, result: ?array<string, mixed>}>`
  replacing `pending_actions`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Chat/ProposalCardComponentTest.php`:

```php
it('reports a resolved call as approved and a denied one as rejected', function (): void {
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $this->user->getMorphClass(),
        'participant_id' => $this->user->getKey(),
        'team_id' => $this->user->current_team_id,
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversationId,
        'participant_type' => $this->user->getMorphClass(),
        'participant_id' => $this->user->getKey(),
        'agent' => 'crm',
        'role' => 'assistant',
        'content' => 'done',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call_ok', 'name' => 'create_company', 'arguments' => ['records' => [['name' => 'Acme']]]],
            ['id' => 'call_no', 'name' => 'delete_company', 'arguments' => ['ids' => ['x']]],
        ]),
        'tool_results' => json_encode([
            ['id' => 'call_ok', 'name' => 'create_company', 'arguments' => [], 'result' => '{"count":1}'],
            ['id' => 'call_no', 'name' => 'delete_company', 'arguments' => [], 'result' => 'rejected', 'denied' => true],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = resolve(Relaticle\Chat\Actions\ListConversationMessages::class)
        ->execute($this->user, $conversationId);

    $approvals = collect($messages)->last()['approvals'];

    expect(collect($approvals)->firstWhere('id', 'call_ok')['status'])->toBe('approved')
        ->and(collect($approvals)->firstWhere('id', 'call_no')['status'])->toBe('rejected');
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact --filter="reports a resolved call as approved"
```

Expected: FAIL — messages carry `pending_actions`, not `approvals`.

- [ ] **Step 3: Replace the pending_actions join**

In `ListConversationMessages::execute()`, delete the `collectPendingActionIds()` call and the `DB::table('pending_actions')` lookup entirely (currently lines 57–77). Select `m.tool_calls` and `m.approval_state` alongside `m.tool_results`, then derive per-message approvals:

```php
    /**
     * @return list<array{id: string, tool: string, status: string, arguments: array<string, mixed>, result: ?array<string, mixed>}>
     */
    private function approvalsFor(object $message): array
    {
        $calls = (array) json_decode((string) ($message->tool_calls ?? '[]'), true);
        $results = collect((array) json_decode((string) ($message->tool_results ?? '[]'), true))->keyBy('id');
        $state = (array) json_decode((string) ($message->approval_state ?? 'null'), true);
        $pending = is_array($state['pending'] ?? null) ? $state['pending'] : [];

        $approvals = [];

        foreach ($calls as $call) {
            $id = (string) ($call['id'] ?? '');

            if ($id === '' || ! array_key_exists($id, $pending) && ! $results->has($id)) {
                continue;
            }

            $result = $results->get($id);

            $approvals[] = [
                'id' => $id,
                'tool' => (string) ($call['name'] ?? ''),
                'status' => match (true) {
                    $result === null => 'pending',
                    ($result['denied'] ?? false) === true => 'rejected',
                    default => 'approved',
                },
                'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                'result' => $result === null ? null : (array) $result,
            ];
        }

        return $approvals;
    }
```

Only calls that were gated (present in `approval_state`) or answered appear — a read-tool call never shows a card.

Emit `'approvals' => $this->approvalsFor($msg)` in the message map, dropping `'pending_actions'`.

- [ ] **Step 4: Update the client**

In `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php`, rename the Alpine surface to match. The concrete sites, by current line number:

- `356`, `358` — the `msg.pending_actions` template loop, keyed on `action.pending_action_id`, becomes `msg.approvals` keyed on `approval.id`
- `676`, `731`, `1932` — `pending_actions: []` initializers become `approvals: []`
- `983`, `1069`, `1075`–`1095` — `visiblePendingActions()`, `activePendingActionId()`, `findPendingAction()` operate on `approvals` and filter `status === 'pending'`
- `1408`, `1432`–`1446`, `1551` — the `.pending_actions_superseded` listener and `markPendingActionsSuperseded()` are deleted outright; the framework settles abandoned pauses server-side and the next transcript load reflects it
- `1898`–`1905` — the `result.type !== 'pending_action'` branch in the tool-result handler is replaced by a `tool_approval_request` listener that pushes `{id, tool, status: 'pending'}` onto the streaming message's `approvals`
- `1241`, `1307`, `2011` — the `some((a) => a.status === 'pending')` guards read `approvals` instead

In `ChatInterface::latestAssistantMessage()` (line 120 onward) and `pendingActionCards()` (line 162), replace the `PendingAction` query with the same `approvalsFor()` derivation so the client's self-heal path still works when a websocket frame is dropped.

- [ ] **Step 5: Update the browser-test fixtures**

`tests/Browser/Chat/ProposalOutcomeSummaryTest.php` drives `proposalOutcome()` with synthetic action objects. Replace the `status`/`itemResults`/`display` fixtures with the new `approvals` shape, keeping one case per branch: single create approved, single delete approved, create rejected, and a partial batch.

- [ ] **Step 6: Run the tests**

```bash
php artisan test --compact tests/Feature/Chat
php artisan test --compact tests/Browser/Chat/ProposalOutcomeSummaryTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/Chat tests/
git commit -m "feat: derive transcript approval cards from conversation history"
```

---

### Task 8: Strip the compensating prompt machinery

`CrmAssistant` carries three mechanisms that exist only because approvals could not resume a turn: the stop-after-write instruction, `<superseded_proposals>`, and `<resolved_actions>`. All three are now actively wrong — the model will see real tool results and should keep working.

**Files:**
- Modify: `packages/Chat/src/Agents/CrmAssistant.php` (lines 103–120, 202–205, 233, 241–263, 357–437)
- Test: `tests/Feature/Chat/CrmAssistantResolvedBlockTest.php` (delete), `tests/Feature/Chat/ResolvedActionsContextTest.php` (delete), `tests/Feature/Chat/SequentialWriteEnforcementTest.php` (update)

**Interfaces:**
- Consumes: Task 4's removal of `withSupersededProposals()` / `withResolvedActions()` calls.
- Produces: a `CrmAssistant` with no `$supersededProposals`, `$resolvedActions`, `withSupersededProposals()`, `withResolvedActions()`, `supersededBlock()`, or `resolvedBlock()`.

- [ ] **Step 1: Delete the properties and their blocks**

Remove `$supersededProposals` (line 109), `$resolvedActions` (line 114), `withSupersededProposals()` (line 437), `withResolvedActions()`, `supersededBlock()` (line 357), and `resolvedBlock()` (line 391). Update the per-turn context concatenation at line 268 to drop the two calls:

```php
        return $this->dateBlock().$this->mentionsBlock().$this->pageContextBlock().$this->contextLedgerBlock();
```

- [ ] **Step 2: Rewrite the write-tool instructions**

Replace the stop-after-write paragraph at line 233 and the bullets at lines 202–205. The new text must say the opposite of the old one:

```
Write tools (create/update/delete) pause for the user's approval before anything
is saved. You will receive the tool's result once they decide, then continue
normally -- report what was actually created, updated, or deleted using the ids
in the result. If the user rejects a call, acknowledge it and do not retry the
same write unless they ask.

For a multi-step request, keep going after each approval until the request is
complete. Do not ask the user to say "continue".
```

Remove the `agent_should_stop` reference — no tool emits it any more.

- [ ] **Step 3: Decide the parallel-tool-call constraint**

`providerOptions()` (line 460) sets Anthropic `disable_parallel_tool_use => true` and OpenAI `parallel_tool_calls => false` to force one write per turn. That existed to keep the sequential approval flow from being bypassed. The framework now gates each call independently and can pause several together for one review pass.

Keep the constraint for this PR. Relaxing it changes how many cards a user sees at once and deserves its own change with its own browser verification. Update the method's docblock to say the constraint is now a UX choice, not a safety mechanism.

- [ ] **Step 4: Update and delete the affected tests**

```bash
git rm tests/Feature/Chat/CrmAssistantResolvedBlockTest.php tests/Feature/Chat/ResolvedActionsContextTest.php tests/Feature/Chat/BatchResolvedActionsTest.php
```

In `tests/Feature/Chat/SequentialWriteEnforcementTest.php`, keep the `providerOptions()` assertions at lines 54–56 — the constraint is unchanged — but delete any assertion about the stop-after-write prompt text.

- [ ] **Step 5: Run the suite**

```bash
php artisan test --compact tests/Feature/Chat
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/Chat/src/Agents/CrmAssistant.php tests/
git commit -m "refactor: drop the prompt machinery that compensated for non-resuming approvals"
```

---

### Task 9: Delete the custom approval layer

Nothing references it now. Remove it in one commit so the deletion is legible in history.

**Files:**
- Delete: `packages/Chat/src/Services/PendingActionService.php`, `packages/Chat/src/Services/ProposalEditor.php`, `packages/Chat/src/Models/PendingAction.php`, `packages/Chat/src/Enums/PendingActionStatus.php`, `packages/Chat/src/Enums/PendingActionOperation.php`, `packages/Chat/src/Commands/ExpirePendingActionsCommand.php`, `packages/Chat/src/Events/PendingActionsSuperseded.php`
- Create: `database/migrations/2026_08_04_200000_drop_pending_actions_table.php`
- Modify: `packages/Chat/src/ChatServiceProvider.php:24,66`, `packages/Chat/config/chat.php:33`, `bootstrap/app.php`, `phpstan.neon`
- Delete tests: `tests/Feature/Chat/PendingActionAllowlistTest.php`, `PendingActionSupersedeTest.php`, `PendingActionEagerLoadTest.php`, `PendingActionConversationIdTest.php`, `PendingActionDisplayDataTest.php`, `ProposalRetryIdempotencyTest.php`, `BatchCreateApprovalTest.php`

**Interfaces:**
- Consumes: every prior task.
- Produces: nothing. This is pure removal.

- [ ] **Step 1: Prove nothing still references the layer**

```bash
grep -rn "PendingAction\|ProposalEditor\|pending_action" --include="*.php" --include="*.blade.php" app/ packages/ tests/ database/ config/ routes/
```

Expected: only the files listed above. If anything else appears, it belongs to an earlier task that is incomplete — go back and finish it rather than deleting a live dependency.

- [ ] **Step 2: Delete the source files**

```bash
git rm packages/Chat/src/Services/PendingActionService.php \
       packages/Chat/src/Services/ProposalEditor.php \
       packages/Chat/src/Models/PendingAction.php \
       packages/Chat/src/Enums/PendingActionStatus.php \
       packages/Chat/src/Enums/PendingActionOperation.php \
       packages/Chat/src/Commands/ExpirePendingActionsCommand.php \
       packages/Chat/src/Events/PendingActionsSuperseded.php
```

- [ ] **Step 3: Delete the tests whose mechanisms no longer exist**

```bash
git rm tests/Feature/Chat/PendingActionAllowlistTest.php \
       tests/Feature/Chat/PendingActionSupersedeTest.php \
       tests/Feature/Chat/PendingActionEagerLoadTest.php \
       tests/Feature/Chat/PendingActionConversationIdTest.php \
       tests/Feature/Chat/PendingActionDisplayDataTest.php \
       tests/Feature/Chat/ProposalRetryIdempotencyTest.php \
       tests/Feature/Chat/BatchCreateApprovalTest.php
```

`tests/Feature/Chat/PendingActionTenantScopeTest.php` is **not** in this list. Tenant scoping still matters — rewrite it against the resume path so the guarantee it protects survives the deletion. Do not delete a test whose subject is still live.

- [ ] **Step 4: Unregister the command and drop the config key**

In `packages/Chat/src/ChatServiceProvider.php`, remove the `ExpirePendingActionsCommand` import (line 24) and its registration (line 66). In `packages/Chat/config/chat.php`, delete the `pending_action_expiry_minutes` key and its comment block (lines 27–33). In `bootstrap/app.php`, remove the scheduled entry for the expire command if one exists:

```bash
grep -n "ExpirePendingActions\|expire-pending" bootstrap/app.php
```

- [ ] **Step 5: Write the drop migration**

Create `database/migrations/2026_08_04_200000_drop_pending_actions_table.php`. No `down()`.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pending_actions');
    }
};
```

- [ ] **Step 6: Clear the PHPStan ignores for deleted files**

```bash
grep -n "PendingAction\|ProposalEditor" phpstan.neon
```

Remove every matching `ignoreErrors` entry. An ignore pointing at a deleted path is itself an error under `reportUnmatchedIgnoredErrors`.

- [ ] **Step 7: Run the full gate**

```bash
php artisan migrate
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
composer test:pest
php -d memory_limit=2G vendor/bin/pest tests/Arch
```

Expected: all pass. The Arch suite is the one that catches a package namespace left without conscious coverage.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor: delete the custom pending action approval layer"
```

---

### Task 10: Verify the whole loop and open the PR

The suite cannot prove that pause, broadcast, decision, resume, and continuation work together over a real queue. Walk it.

**Files:**
- Modify: `tests/.pest/shards.json` (only if timings shifted materially)

**Interfaces:**
- Consumes: all prior tasks.
- Produces: a reviewable PR.

- [ ] **Step 1: Run the complete gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
composer test:pest
php -d memory_limit=2G vendor/bin/pest tests/Arch
```

Expected: all pass, type coverage 100%. Do not substitute `composer test:pest:tia` — it replays cached passes and cannot see this change.

- [ ] **Step 2: Bring up the production-shaped stack**

Confirm `QUEUE_CONNECTION=redis` in `.env` first. A sync queue masks the exact bug class this change introduces.

```bash
grep QUEUE_CONNECTION .env
php artisan horizon &
php artisan reverb:start &
```

If jobs vanish, check that no other Herd app's Horizon is running against the same Redis.

- [ ] **Step 3: Walk the full loop in a browser**

```bash
agent-browser --session hitl-pr2 open https://relaticle.test/app/login
agent-browser --session hitl-pr2 snapshot -i
```

Verify each of these, screenshotting as you go:

1. **Single create.** Ask for one company. Card appears with the approval reason. Approve. The company is created **and the assistant continues in the same turn** reporting the created record — this is the headline behavior change.
2. **Rejection.** Ask for another company, reject with a reason. The assistant acknowledges and does not retry.
3. **Batch, partial.** Ask for five companies. Step through, approve three, skip two. Exactly three are created; the assistant's reply names the three and does not claim five.
4. **Multi-step.** Ask for a company and a contact at that company in one message. Approve the company; the assistant proposes the contact **without you typing "continue"**; approve it; the contact links to the new company.
5. **Custom fields.** Create a record setting a custom field, and separately clear one to empty. Both persist. This is the tenant-context path — a leak here writes across tenants.
6. **Abandonment.** Propose a write, then send a new message instead of deciding. The card stops being actionable and the assistant does not describe it as still pending.
7. **Reload.** With a card pending, reload the page. The card re-renders from history, still actionable.
8. **Rate-limit retry.** Not reproducible on demand — instead confirm by reading that `hasResumableApproval()` is consulted before every resume.

```bash
agent-browser --session hitl-pr2 errors
agent-browser --session hitl-pr2 screenshot .context/pr2-full-loop.png
```

Expected: no console errors and all seven reproducible scenarios behave as described. If any step fails, that is a defect to fix, not to note — enumerate every defective turn separately rather than fixing only the most visible one.

- [ ] **Step 4: Refresh the shard balance if timings moved**

```bash
composer test:update-shards
```

- [ ] **Step 5: Open the PR**

```bash
git push
gh pr create --base main \
  --title "feat: adopt laravel/ai human-in-the-loop tool approval" \
  --body "$(cat <<'BODY'
Replaces the custom `pending_actions` approval system with laravel/ai's
human-in-the-loop tool approval. Approving a proposed write now resumes the
paused generation turn instead of ending it, so a multi-step request no longer
requires the user to type "continue" between steps.

Every write stays gated. Nothing executes without an explicit approval.

Design: `docs/superpowers/specs/2026-08-04-laravel-ai-hitl-migration-design.md`.
Depends on the 0.10 upgrade that landed separately.

## Behavior changes

- Approving runs the tool inside the resumed turn; the model continues and may
  pause again for the next write, each with its own card
- Rejecting sends the reason back to the model, which responds to it
- A partially-approved batch submits as `Decision::edit`, carrying only the kept
  records; items commit individually so one failure keeps the rest
- Proposal expiry is gone. An abandoned pause is settled by the framework when
  the conversation moves on

## Deleted

`PendingActionService`, `ProposalEditor`, the `PendingAction` model, two enums,
`ExpirePendingActionsCommand`, `PendingActionsSuperseded`, the `pending_actions`
table, and the `<superseded_proposals>` / `<resolved_actions>` prompt blocks —
roughly 1,400 lines.

## Test plan

- Full suite, PHPStan level 7, 100% type coverage, Pint, Rector — all green
- New: `ApprovableWriteToolTest`, `ApprovalResumeTest`, `StreamEventBroadcasterTest`;
  `ProposalCardComponentTest` rewritten against conversation history
- `PendingActionTenantScopeTest` rewritten against the resume path rather than
  deleted — the guarantee it protects is still live
- Browser walk on Redis + Horizon + Reverb: single create, rejection, partial
  batch, unprompted multi-step continuation, custom-field set and clear,
  abandonment, and reload-with-pending-card
BODY
)"
```

- [ ] **Step 6: Confirm CI is green**

```bash
gh pr checks --watch
```

---

## Self-Review

**Spec coverage.** Every section of the design maps to a task: the approvable tool layer and the validation-ordering trap to Tasks 1–3, the resume job with its credits/tenant/retry concerns to Task 4, the Reverb payload discipline to Task 5, render-time display and batch decisions to Task 6, transcript audit cards to Task 7, prompt cleanup to Task 8, and the deletion inventory to Task 9.

**Type consistency.** `TurnInput::decisions()` produces the same `array<string, array{action, result?, arguments?}>` shape that `ProposalCard::submit()` builds and `ProcessChatMessage::hasResumableApproval()` reads. Tool result keys (`created`/`updated`/`deleted`, `skipped`, `count`) are consistent between Tasks 1–3 and the `approvalsFor()` consumer in Task 7. `$toolCallId` replaces `$pendingActionId` uniformly across Tasks 6 and 7.

**One deliberate deviation from the spec.** The spec's deletion list included `BatchCreateApprovalTest` and its siblings without qualification. Task 9 keeps `PendingActionTenantScopeTest` and rewrites it instead — cross-tenant custom-field writes remain a live hazard on the resume path, and deleting the test that guards them alongside the mechanism it happened to be written against would silently drop the guarantee.

**The one step the implementer must not skip.** Task 10 Step 3 scenario 4 — unprompted multi-step continuation — is the single behavior this entire migration exists to deliver. Every other scenario can pass while that one fails, and if it does, nothing else in the PR matters.
