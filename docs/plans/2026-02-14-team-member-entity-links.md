# Team Member EntityLinks Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace flat `account_owner_email` / `assignee_email` ImportFields with proper EntityLinks, giving users the existing submenu UX to choose "Match by Email" or "Match by Record ID".

**Architecture:** The EntityLink system already handles CRM entity relationships (Company, People, Opportunity) with matcher selection, resolution, and storage. We extend it to support `User` as a target model by adding a team-scoped resolution path in `EntityLinkResolver`. Both `ForeignKeyStorage` (Company's `account_owner_id`) and `MorphToManyStorage` (Task's `assignees()` pivot) already handle their respective storage patterns — no changes needed there.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, ImportWizard module

**Design doc:** `docs/plans/2026-02-14-team-member-entity-links-design.md`

---

### Task 1: EntityLinkResolver — team member resolution

**Files:**
- Modify: `app-modules/ImportWizard/src/Support/EntityLinkResolver.php:79-106`
- Test: `tests/Feature/ImportWizard/Support/EntityLinkResolverTest.php` (create)

**Step 1: Write failing tests**

Create `tests/Feature/ImportWizard/Support/EntityLinkResolverTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Support\EntityLinkResolver;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
});

it('resolves team member by email via pivot', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'editor']);

    $resolver = new EntityLinkResolver($this->team->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$member->email]);

    expect($result[$member->email])->toBe($member->id);
});

it('resolves team owner by email', function (): void {
    $resolver = new EntityLinkResolver($this->team->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$this->user->email]);

    expect($result[$this->user->email])->toBe($this->user->id);
});

it('resolves team member by ID', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'editor']);

    $resolver = new EntityLinkResolver($this->team->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::id()])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::id();

    $result = $resolver->batchResolve($link, $matcher, [$member->id]);

    expect($result[$member->id])->toBe($member->id);
});

it('returns null for non-team-member email', function (): void {
    $stranger = User::factory()->create();

    $resolver = new EntityLinkResolver($this->team->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$stranger->email]);

    expect($result[$stranger->email])->toBeNull();
});

it('resolves multiple team members in batch', function (): void {
    $member1 = User::factory()->create();
    $member2 = User::factory()->create();
    $this->team->users()->attach($member1, ['role' => 'editor']);
    $this->team->users()->attach($member2, ['role' => 'editor']);

    $resolver = new EntityLinkResolver($this->team->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$member1->email, $member2->email]);

    expect($result[$member1->email])->toBe($member1->id)
        ->and($result[$member2->email])->toBe($member2->id);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=EntityLinkResolverTest`
Expected: FAIL — `resolveViaColumn` tries `User::where('team_id', ...)` which doesn't exist on users table.

**Step 3: Implement `resolveViaTeamMember` in EntityLinkResolver**

Modify `app-modules/ImportWizard/src/Support/EntityLinkResolver.php`:

1. Add import at top: `use App\Models\User;`

2. In `batchResolve()` method (line 88-90), change the resolution dispatch to also check for User:

```php
$results = match (true) {
    $link->targetModelClass === User::class => $this->resolveViaTeamMember($field, $uniqueValues),
    $this->isCustomField($field) => $this->resolveViaCustomField($link, $field, $uniqueValues),
    default => $this->resolveViaColumn($link, $field, $uniqueValues),
};
```

3. Add the new method after `resolveViaColumn()` (after line 145):

```php
/**
 * @param  array<string>  $uniqueValues
 * @return array<string, int|string>
 */
private function resolveViaTeamMember(string $field, array $uniqueValues): array
{
    return User::query()
        ->whereIn($field, $uniqueValues)
        ->where(function (\Illuminate\Database\Eloquent\Builder $query): void {
            $query->whereHas('teams', fn (\Illuminate\Database\Eloquent\Builder $q) => $q->where('teams.id', $this->teamId))
                ->orWhereHas('ownedTeams', fn (\Illuminate\Database\Eloquent\Builder $q) => $q->where('teams.id', $this->teamId));
        })
        ->pluck('id', $field)
        ->all();
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=EntityLinkResolverTest`
Expected: PASS (all 5 tests)

**Step 5: Commit**

```bash
git add app-modules/ImportWizard/src/Support/EntityLinkResolver.php tests/Feature/ImportWizard/Support/EntityLinkResolverTest.php
git commit -m "feat: add team member resolution to EntityLinkResolver"
```

---

### Task 2: CompanyImporter — replace ImportField with EntityLink

**Files:**
- Modify: `app-modules/ImportWizard/src/Importers/CompanyImporter.php`
- Modify: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php`

**Step 1: Update existing tests to use EntityLink format**

In `tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php`, find the two company account_owner tests (around lines 1647-1678) and update them:

Test 1 — "imports company with account_owner_email resolved to user" becomes:

```php
it('imports company with account_owner resolved by email via entity link', function (): void {
    $owner = User::factory()->create();
    $this->team->users()->attach($owner, ['role' => 'editor']);

    $relationships = json_encode([
        ['relationship' => 'account_owner', 'action' => 'update', 'id' => (string) $owner->id, 'name' => null],
    ]);

    createImportReadyStore($this, ['Name', 'Owner Email'], [
        makeRow(2, ['Name' => 'Test Corp', 'Owner Email' => $owner->email], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Owner Email', matcherKey: 'email', entityLinkKey: 'account_owner'),
    ], ImportEntityType::Company);

    runImportJob($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Test Corp')->first();
    expect($company)->not->toBeNull()
        ->and((string) $company->account_owner_id)->toBe((string) $owner->id);
});
```

Test 2 — "imports company with unknown account_owner_email ignoring the field" becomes:

```php
it('imports company with unmatched account_owner email skipping silently', function (): void {
    createImportReadyStore($this, ['Name', 'Owner Email'], [
        makeRow(2, ['Name' => 'Test Corp', 'Owner Email' => 'nonexistent@example.com'], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Owner Email', matcherKey: 'email', entityLinkKey: 'account_owner'),
    ], ImportEntityType::Company);

    runImportJob($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Test Corp')->first();
    expect($company)->not->toBeNull()
        ->and($company->account_owner_id)->toBeNull();
});
```

Add a new test for team owner resolution:

```php
it('imports company with account_owner resolved for team owner', function (): void {
    $relationships = json_encode([
        ['relationship' => 'account_owner', 'action' => 'update', 'id' => (string) $this->user->id, 'name' => null],
    ]);

    createImportReadyStore($this, ['Name', 'Owner Email'], [
        makeRow(2, ['Name' => 'Owner Corp', 'Owner Email' => $this->user->email], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Owner Email', matcherKey: 'email', entityLinkKey: 'account_owner'),
    ], ImportEntityType::Company);

    runImportJob($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Owner Corp')->first();
    expect($company)->not->toBeNull()
        ->and((string) $company->account_owner_id)->toBe((string) $this->user->id);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="account_owner"`
Expected: FAIL — CompanyImporter doesn't define `account_owner` entity link yet.

**Step 3: Update CompanyImporter**

Replace the entire `CompanyImporter.php` with:

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Importers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\ImportFieldCollection;
use Relaticle\ImportWizard\Data\MatchableField;

final class CompanyImporter extends BaseImporter
{
    public function modelClass(): string
    {
        return Company::class;
    }

    public function entityName(): string
    {
        return 'company';
    }

    public function fields(): ImportFieldCollection
    {
        return new ImportFieldCollection([
            ImportField::id(),

            ImportField::make('name')
                ->label('Name')
                ->required()
                ->rules(['required', 'string', 'max:255'])
                ->guess([
                    'name', 'company_name', 'company', 'organization', 'account', 'account_name',
                    'company name', 'associated company', 'company domain name',
                    'account name', 'parent account', 'billing name',
                    'business', 'business_name', 'org', 'org_name', 'organisation',
                    'firm', 'client', 'customer', 'customer_name', 'vendor', 'vendor_name',
                ])
                ->example('Acme Corporation')
                ->icon('heroicon-o-building-office-2'),
        ]);
    }

    /** @return array<string, EntityLink> */
    protected function defineEntityLinks(): array
    {
        return [
            'account_owner' => EntityLink::belongsTo('account_owner', User::class)
                ->matchableFields([
                    MatchableField::id(),
                    MatchableField::email('email'),
                ])
                ->foreignKey('account_owner_id')
                ->label('Account Owner')
                ->guess([
                    'account_owner', 'owner_email', 'owner', 'assigned_to', 'account_manager',
                    'owner email', 'sales rep', 'sales_rep', 'rep', 'salesperson', 'sales_owner',
                    'account_rep', 'assigned_user', 'manager_email', 'contact_owner',
                    'account_owner_email', 'owner_id',
                ]),
        ];
    }

    /** @return array<MatchableField> */
    public function matchableFields(): array
    {
        return [
            MatchableField::id(),
            MatchableField::domain('custom_fields_domains'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  &$context
     * @return array<string, mixed>
     */
    public function prepareForSave(array $data, ?Model $existing, array &$context): array
    {
        $data = parent::prepareForSave($data, $existing, $context);

        if (! $existing instanceof Model) {
            return $this->initializeNewRecordData($data, $context['creator_id'] ?? null);
        }

        return $data;
    }
}
```

Key changes:
- Removed `account_owner_email` ImportField from `fields()`
- Added `defineEntityLinks()` with `account_owner` entity link
- Simplified `prepareForSave` — no manual email resolution
- Removed `afterSave` entirely — `ForeignKeyStorage.prepareData()` sets `account_owner_id` in `$data` before `forceFill`

**Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter="account_owner"`
Expected: PASS (3 tests)

**Step 5: Commit**

```bash
git add app-modules/ImportWizard/src/Importers/CompanyImporter.php tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php
git commit -m "feat: convert company account_owner to entity link with matcher selection"
```

---

### Task 3: TaskImporter — replace ImportField with EntityLink

**Files:**
- Modify: `app-modules/ImportWizard/src/Importers/TaskImporter.php`
- Modify: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php`

**Step 1: Update existing tests to use EntityLink format**

In `tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php`, find the two task assignee tests (around lines 1680-1712) and update:

Test 1 — "imports task with assignee_email resolved and synced":

```php
it('imports task with assignee resolved by email via entity link', function (): void {
    $assignee = User::factory()->create();
    $this->team->users()->attach($assignee, ['role' => 'editor']);

    $relationships = json_encode([
        ['relationship' => 'assignees', 'action' => 'update', 'id' => (string) $assignee->id, 'name' => null],
    ]);

    createImportReadyStore($this, ['Title', 'Assignee Email'], [
        makeRow(2, ['Title' => 'Test Task', 'Assignee Email' => $assignee->email], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Assignee Email', matcherKey: 'email', entityLinkKey: 'assignees'),
    ], ImportEntityType::Task);

    runImportJob($this);

    $task = Task::where('team_id', $this->team->id)->where('title', 'Test Task')->first();
    expect($task)->not->toBeNull();

    $assigneeIds = $task->assignees()->pluck('users.id')->map(fn ($id) => (string) $id)->all();
    expect($assigneeIds)->toContain((string) $assignee->id);
});
```

Test 2 — "imports task with unknown assignee_email without crashing":

```php
it('imports task with unmatched assignee email skipping silently', function (): void {
    createImportReadyStore($this, ['Title', 'Assignee Email'], [
        makeRow(2, ['Title' => 'Orphan Task', 'Assignee Email' => 'ghost@nowhere.com'], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Assignee Email', matcherKey: 'email', entityLinkKey: 'assignees'),
    ], ImportEntityType::Task);

    runImportJob($this);

    $task = Task::where('team_id', $this->team->id)->where('title', 'Orphan Task')->first();
    expect($task)->not->toBeNull()
        ->and($task->assignees()->count())->toBe(0);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="assignee"`
Expected: FAIL — TaskImporter doesn't define `assignees` entity link yet.

**Step 3: Update TaskImporter**

Replace the entire `TaskImporter.php` with:

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Importers;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\ImportFieldCollection;
use Relaticle\ImportWizard\Data\MatchableField;

final class TaskImporter extends BaseImporter
{
    public function modelClass(): string
    {
        return Task::class;
    }

    public function entityName(): string
    {
        return 'task';
    }

    public function fields(): ImportFieldCollection
    {
        return new ImportFieldCollection([
            ImportField::id(),

            ImportField::make('title')
                ->label('Title')
                ->required()
                ->rules(['required', 'string', 'max:255'])
                ->guess([
                    'title', 'task_title', 'name', 'subject', 'todo',
                    'task', 'task name', 'action', 'action item',
                    'to do', 'todo item', 'activity',
                ])
                ->example('Follow up with client')
                ->icon('heroicon-o-check-circle'),
        ]);
    }

    /** @return array<string, EntityLink> */
    protected function defineEntityLinks(): array
    {
        return [
            'companies' => EntityLink::polymorphicCompanies(),
            'people' => EntityLink::polymorphicPeople(),
            'opportunities' => EntityLink::polymorphicOpportunities(),
            'assignees' => EntityLink::morphToMany('assignees', User::class)
                ->matchableFields([
                    MatchableField::id(),
                    MatchableField::email('email'),
                ])
                ->label('Assignee')
                ->guess([
                    'assignee', 'assigned_to', 'owner', 'assignee_email',
                    'assigned_email', 'owner_email', 'responsible',
                ]),
        ];
    }

    /** @return array<MatchableField> */
    public function matchableFields(): array
    {
        return [
            MatchableField::id(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  &$context
     * @return array<string, mixed>
     */
    public function prepareForSave(array $data, ?Model $existing, array &$context): array
    {
        $data = parent::prepareForSave($data, $existing, $context);

        if (! $existing instanceof Model) {
            return $this->initializeNewRecordData($data, $context['creator_id'] ?? null);
        }

        return $data;
    }
}
```

Key changes:
- Removed `assignee_email` ImportField from `fields()`
- Added `assignees` entity link to `defineEntityLinks()`
- Simplified `prepareForSave` — no manual email resolution
- Removed `afterSave` and `syncAssignee` — `MorphToManyStorage.store()` handles `syncWithoutDetaching`

**Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter="assignee"`
Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add app-modules/ImportWizard/src/Importers/TaskImporter.php tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php
git commit -m "feat: convert task assignee to entity link with matcher selection"
```

---

### Task 4: Clean up BaseImporter

**Files:**
- Modify: `app-modules/ImportWizard/src/Importers/BaseImporter.php:225-235`

**Step 1: Verify no other callers of `resolveTeamMemberByEmail`**

Run: `grep -r "resolveTeamMemberByEmail" app-modules/ app/ --include="*.php"`
Expected: Only `BaseImporter.php` definition (no callers left after Task 2 and Task 3).

**Step 2: Remove the method**

Delete lines 225-235 from `BaseImporter.php` (the `resolveTeamMemberByEmail` method). Also remove unused imports if any (`Builder` may still be used elsewhere — check first).

**Step 3: Run full ImportWizard test suite**

Run: `php artisan test --compact --filter=ImportWizard`
Expected: All tests PASS.

**Step 4: Run PHPStan**

Run: `vendor/bin/phpstan analyse app-modules/ImportWizard/src/Importers/ app-modules/ImportWizard/src/Support/EntityLinkResolver.php`
Expected: No new errors.

**Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: Clean or auto-fixed.

**Step 6: Commit**

```bash
git add app-modules/ImportWizard/src/Importers/BaseImporter.php
git commit -m "refactor: remove unused resolveTeamMemberByEmail from BaseImporter"
```

---

### Task 5: Final verification

**Step 1: Run full ImportWizard test suite**

Run: `php artisan test --compact --filter=ImportWizard`
Expected: All tests pass.

**Step 2: Run PHPStan on all changed files**

Run: `vendor/bin/phpstan analyse app-modules/ImportWizard/src/`
Expected: No new errors above baseline.

**Step 3: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

**Step 4: Commit any formatting fixes if needed**
