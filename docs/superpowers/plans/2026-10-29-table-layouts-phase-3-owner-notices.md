# Table layouts phase 3: owner notices Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a workspace Admin deletes or stops sharing a member's table layout, the owner gets one in-app notice in the bell that names the layout, the table it was on and the Admin. The owner's own changes send nothing.

**Architecture:** One `InboxNotification` subclass, `TableLayoutChangedNotification`, whose subject is the workspace and whose facts carry the layout's name, entity and the change. `DeleteTableLayout` and `UpdateTableLayout` send it. `CanonicalRecordUrl` gains two public reads over its existing entity-to-resource match: the table's list URL and its plural label. No migration.

**Tech Stack:** Laravel 13, Filament 5 notifications, the project inbox (`InboxChannel`, `App\Models\Notification`), Pest 5, PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-10-27-table-layouts-design.md`, section "Phase 3: Saved layouts", subsection "Telling the owner".

## Global Constraints

- Branch `feat/943-3-saved-layouts`. **No commits.** Each task ends at "stop for review". The user commits when they say.
- `docs/*` is gitignored. This plan needs `git add -f` when the user commits it.
- Read `.ai/rules/notifications.md` before Task 2. A bell row stores facts and `present()` renders them; a `present()` that reads relations lists them in `presentingRelations()`.
- The subject is the workspace, never the layout. The notice is queued after commit (`ShouldQueueAfterCommit`), the layout is hard-deleted, and `#[DeleteWhenMissingModels]` would drop a notice whose layout is gone. `PaymentFailedNotification` is the precedent.
- Reason `NotificationReason::System`. No `NotificationType` preference, so no settings toggle. In-app only: the class declares no `toMail()`.
- `InboxNotification::shouldSend()` already skips a notice to its own actor and to a member who left the workspace. Do not restate either check in the actions.
- Every string goes through `__()` in `lang/en/notifications.php` under `events.table_layout_changed.*`. No em-dash. Record names follow the business language (company, person, opportunity, task, note).
- No new PHPStan ignore. Methods stay under 60 lines. Comments: two lines max, a non-obvious why only. No comments in tests.

## Review Focus

1. **A deleted layout still produces its notice.** The queued job must not hold the layout model. Test in Task 3.
2. **No notice for the owner's own change,** whether they delete, stop sharing, rename or overwrite. Test in Task 3.
3. **Rename and overwrite by an Admin send nothing.** Only `is_shared` turning false does, not any update. Test in Task 3.
4. **The row renders after the layout is gone,** with the actor's name or the fallback title when the actor has since been deleted. Test in Task 4.

---

## Task 1: List URL and label for an entity's table

**Files:**
- Modify: `app/Support/CanonicalRecordUrl.php`

- [ ] **Step 1: Add two public reads over the existing `resource()` match**

```php
public function listUrl(CrmEntity $entity, Workspace $workspace): ?string
{
    $resource = $this->resource($entity);

    try {
        return $resource::getUrl('index', panel: 'app', tenant: $workspace);
    } catch (Throwable) {
        return null;
    }
}

public function listLabel(CrmEntity $entity): string
{
    return $this->resource($entity)::getPluralModelLabel();
}
```

The `match` stays outside the `try`, as `build()` keeps it, so a new entity fails loudly.

- [ ] **Step 2: Stop for review.** No test of its own: Task 4 reaches both through `present()`.

## Task 2: The notification

**Files:**
- Create: `app/Notifications/TableLayoutChangedNotification.php`
- Modify: `lang/en/notifications.php`

- [ ] **Step 1: Strings** under `events`:

```php
'table_layout_changed' => [
    'deleted_title' => ':actor deleted your layout ":name"',
    'deleted_title_without_actor' => 'Your layout ":name" was deleted',
    'deleted_body' => 'It was on the :table table. Members who used it keep what their table shows.',
    'unshared_title' => ':actor stopped sharing your layout ":name"',
    'unshared_title_without_actor' => 'Your layout ":name" is no longer shared',
    'unshared_body' => 'It is private to you again on the :table table.',
    'action' => 'Open :table',
],
```

- [ ] **Step 2: The class**

```php
final class TableLayoutChangedNotification extends InboxNotification
{
    public const string DELETED = 'deleted';

    public const string UNSHARED = 'unshared';

    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $actor,
        private readonly string $layoutName,
        private readonly CrmEntity $entity,
        private readonly string $change,
    ) {}

    // workspace(), subject() (the workspace), actor(), reason() => System

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return ['layout_name' => $this->layoutName, 'entity' => $this->entity->value, 'change' => $this->change];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        // Title and body by `change`, the `_without_actor` title when $row->actor is null.
        // One "Open :table" action to CanonicalRecordUrl::listUrl(), omitted when it is null.
    }
}
```

If `change` holds anything but the two constants, `present()` throws. A silent fallback would hide a bad row.

- [ ] **Step 3: Stop for review.**

## Task 3: Send it from the actions

**Files:**
- Modify: `app/Actions/TableLayouts/DeleteTableLayout.php`
- Modify: `app/Actions/TableLayouts/UpdateTableLayout.php`
- Modify: `tests/Feature/Filament/App/Tables/TableLayoutsTest.php`

- [ ] **Step 1: Write the failing tests** through `ListCompanies`, as the file's other tests do:

```php
it('tells the owner when an admin deletes their layout', ...);
it('tells the owner when an admin stops sharing their layout', ...);
it('tells no one when a member deletes or stops sharing their own layout', ...);
it('tells no one when an admin renames or overwrites someone else\'s layout', ...);
```

Assert on `App\Models\Notification` rows: exactly one for the owner, `subject` the workspace, `actor` the Admin, and `data` holding `layout_name`, `entity` and `change`. The delete test proves Review Focus 1: the layout row is gone and the notice still exists.

- [ ] **Step 2: Run them, expect failure:** `php artisan test --compact tests/Feature/Filament/App/Tables/TableLayoutsTest.php --filter='tells'`

- [ ] **Step 3: `DeleteTableLayout`** reads the name, entity, workspace and owner before `delete()`, then calls `$owner->notify(new TableLayoutChangedNotification(..., TableLayoutChangedNotification::DELETED))`.

- [ ] **Step 4: `UpdateTableLayout`** sends `UNSHARED` inside the existing `wasChanged('is_shared') && ! $layout->is_shared` branch, next to `forgetForOtherMembers()`. `ShouldQueueAfterCommit` holds it until the transaction commits.

- [ ] **Step 5: Run the file:** `php artisan test --compact tests/Feature/Filament/App/Tables/TableLayoutsTest.php`. Expect every test to pass.

- [ ] **Step 6: Stop for review.**

## Task 4: The bell row renders

**Files:**
- Modify: `tests/Feature/Notifications/NotificationInboxTest.php`

- [ ] **Step 1: Tests**, following that file's setup:

```php
it('shows the owner who deleted their layout and links to the table', ...);
it('shows a layout notice whose actor has since been deleted', ...);
```

The first asserts the title, the "on Companies" body and an action URL equal to `CanonicalRecordUrl::listUrl(CrmEntity::Company, $workspace)`. The second deletes the actor and asserts the `_without_actor` title.

- [ ] **Step 2: Run:** `php artisan test --compact tests/Feature/Notifications/NotificationInboxTest.php`

- [ ] **Step 3: Stop for review.**

## Task 5: Verify

- [ ] **Step 1: Quality loop** from `core.md`: `vendor/bin/pint --dirty --format agent`, `composer test:affected`, then `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:arch`. `ConventionsTest` checks that the class extends `InboxNotification` and that a bell row carries workspace, subject and reason.
- [ ] **Step 2: Browser walk**, light and dark. As an Admin, delete one member's shared layout and stop sharing another. Sign in as the member: the bell shows two notices, and "Open Companies" lands on the companies table. Screenshot both rows.
- [ ] **Step 3: Stop for review.**

## Non-goals

- Email for these notices, and a preference toggle for them.
- A notice when an Admin renames or overwrites a member's layout. The member sees the new name or state in their menu.
- Restoring a deleted layout from the notice.
