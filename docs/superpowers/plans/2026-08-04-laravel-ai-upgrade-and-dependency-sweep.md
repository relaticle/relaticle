# laravel/ai 0.10 Upgrade + Dependency Sweep Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring every outdated direct dependency current — most importantly `laravel/ai` 0.6.8 → 0.10.2 — with the conversation tables migrated to polymorphic participants and the custom conversation store rebuilt on the new parent, leaving `main` green and the human-in-the-loop API available but unused.

**Architecture:** This is PR 1 of two. It changes no product behavior. The three risk-bearing upgrades (`laravel/ai`, `asmit/resized-column`, `relaticle/custom-fields`) each land as their own commit so a regression stays bisectable; the remaining patch releases batch into one. The `pending_actions` approval system is untouched and keeps working exactly as it does today — PR 2 replaces it.

**Tech Stack:** PHP 8.4, Laravel 13, Filament 5, Livewire 4, Pest 5, PostgreSQL, `laravel/ai` 0.10.2.

## Global Constraints

- PostgreSQL only. No SQLite/MySQL compatibility layers, driver checks, or conditional SQL.
- Migrations have `up()` only. Never write a `down()` method.
- Never edit `CLAUDE.md`, `AGENTS.md`, or `GEMINI.md` directly — they are compiled from `.ai/guidelines/relaticle/`.
- Pre-commit gate, in order: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:type-coverage` (must stay 100%), `php artisan test --compact`.
- PHPStan runs at level 7 with no baseline. Do not add new `ignoreErrors` entries.
- All parameters and return types must be explicitly typed — untyped closures fail type coverage in CI.
- The morph map is enforced (`Relation::enforceMorphMap` in `app/Providers/AppServiceProvider.php:288`). `User::class` maps to the string `'user'`.
- Every test file must live under `tests/Arch`, `tests/PHPStan`, `tests/Smoke`, `tests/Feature`, or `tests/Browser`. There is no `tests/Unit` suite.
- The Arch suite OOMs at the default memory limit locally. Run it as `php -d memory_limit=2G vendor/bin/pest tests/Arch`.

---

### Task 1: Batched patch and minor dependency upgrades

The low-risk half of the sweep. Six composer packages and two npm packages, none with breaking changes.

**Files:**
- Modify: `composer.json`, `composer.lock`
- Modify: `package.json`, `package-lock.json`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing. No source changes; later tasks are independent of this one.

- [ ] **Step 1: Record the starting state**

```bash
composer outdated --direct --no-interaction
npm outdated
```

Expected: `laravel/ai 0.6.8`, `asmit/resized-column 3.0.2`, `relaticle/custom-fields 3.5.5`, plus `filament/filament 5.7.4`, `laravel/pint 1.30.0`, `livewire/livewire 4.3.3`, `pestphp/pest 5.0.2`, `rector/rector 2.5.8`, `spatie/laravel-query-builder 7.3.0`; npm `dompurify 3.4.12`, `shiki 4.3.1`.

- [ ] **Step 2: Upgrade the six batched composer packages**

These are all in-range, so no `composer.json` constraint edits are needed.

```bash
composer update \
  filament/filament \
  laravel/pint \
  livewire/livewire \
  pestphp/pest \
  rector/rector \
  spatie/laravel-query-builder \
  --with-dependencies --no-interaction
```

- [ ] **Step 3: Upgrade the two npm packages**

Both are in-range, so `npm update` suffices.

```bash
npm update dompurify shiki
npm outdated
```

Expected: `npm outdated` prints nothing.

- [ ] **Step 4: Rebuild front-end assets**

```bash
npm run build
```

Expected: build succeeds, no Vite manifest errors.

- [ ] **Step 5: Run the full gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
composer test:pest
php -d memory_limit=2G vendor/bin/pest tests/Arch
```

Expected: all pass. Type coverage 100%.

If `rector --dry-run` reports `phar://…/phpstan.phar/… is not a file`, the global temp cache is holding stale paths from a deleted workspace. Clear it and re-run — this masks real findings:

```bash
rm -rf "$TMPDIR/cache"
```

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock package.json package-lock.json public/build
git commit -m "chore(deps): upgrade filament, livewire, pest, rector, pint, query-builder, dompurify, shiki"
```

---

### Task 2: Upgrade asmit/resized-column to 4.x

A major version, but our usage is limited to the plugin registration and the `HasResizableColumn` trait, both of which survive unchanged. The new drag-reorder and pinned-column features are opt-in and we do not opt in. The real risk is asset drift, so this task ends with a browser check.

**Files:**
- Modify: `composer.json` (constraint `^3.0` → `^4.0`), `composer.lock`
- Touched by `filament:assets`: `public/js/`, `public/css/`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing. `Asmit\ResizedColumn\ResizedColumnPlugin` and `Asmit\ResizedColumn\HasResizableColumn` keep the same names and signatures in 4.x.

- [ ] **Step 1: Confirm the current usage surface**

```bash
grep -rn "ResizedColumn" --include="*.php" app/ packages/
```

Expected exactly these six sites, all of which remain valid in 4.x:
- `app/Providers/Filament/AppPanelProvider.php:25` and `:196` — `ResizedColumnPlugin::make()`
- `app/Filament/Resources/CompanyResource/Pages/ListCompanies.php:9`
- `app/Filament/Resources/PeopleResource/Pages/ListPeople.php:9`
- `app/Filament/Resources/OpportunityResource/Pages/ListOpportunities.php:10`
- `app/Filament/Resources/TaskResource/Pages/ManageTasks.php:12`
- `app/Filament/Resources/NoteResource/Pages/ManageNotes.php:9`

- [ ] **Step 2: Upgrade and republish assets**

```bash
composer require asmit/resized-column:^4.0 --no-interaction
php artisan filament:assets
```

- [ ] **Step 3: Run the gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
composer test:pest
```

Expected: all pass.

- [ ] **Step 4: Verify the tables actually render and resize**

Tests do not cover column resizing — it is JS behavior. Check it in a real browser. Use a unique session name; agent-browser sessions are machine-global.

```bash
agent-browser --session hitl-pr1 open https://relaticle.test/app/login
agent-browser --session hitl-pr1 snapshot -i
```

Log in, navigate to the Companies table, then confirm:

```bash
agent-browser --session hitl-pr1 errors
agent-browser --session hitl-pr1 screenshot .context/pr1-companies-table.png
```

Expected: no console errors, table headers render with resize handles, and column widths persist across a reload. Repeat for People, Opportunities, Tasks, and Notes — the five tables using the trait.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock public/js public/css
git commit -m "chore(deps): upgrade asmit/resized-column to 4.x"
```

---

### Task 3: Upgrade relaticle/custom-fields to 3.6.0

A minor release containing two import-path fixes (`apply presence rule to custom field validation on import`, `only save custom fields the imported row carried`). It touches the custom-field write path that the whole HITL design depends on, so it gets its own commit and its own targeted test run.

**Files:**
- Modify: `composer.json`, `composer.lock`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

- [ ] **Step 1: Upgrade**

```bash
composer update relaticle/custom-fields --with-dependencies --no-interaction
```

- [ ] **Step 2: Run the import and custom-field suites specifically**

These are the paths 3.6.0 changed.

```bash
php artisan test --compact tests/Feature/ImportWizard
php artisan test --compact --filter=CustomField
```

Expected: all pass. If an import test now fails on a field the row did not carry, that is the intended 3.6.0 behavior change — fix the assertion, not the package, and say so in the commit body.

- [ ] **Step 3: Run the full gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
composer test:type-coverage
composer test:pest
```

Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore(deps): upgrade relaticle/custom-fields to 3.6.0"
```

---

### Task 4: Migrate the conversation tables to polymorphic participants

`laravel/ai` 0.10 replaces `user_id` with a polymorphic `participant_type` + `participant_id` pair, and adds an `approval_state` column for the paused-turn state. Our schema diverges from upstream's in three ways the published upgrade guide does not cover: both tables have a **foreign key** on `user_id`, `agent_conversations` has an extra `team_id` column, and there is an extra composite index `(team_id, user_id, updated_at)`.

The foreign keys must go. A polymorphic participant column cannot reference `users` — the constraint would break the first time a non-user participant is stored, and today's `ON DELETE SET NULL` would null `participant_id` while leaving `participant_type` reading `'user'`. **Accepted consequence:** deleting a user now leaves a dangling `participant_id` on their conversations instead of nulling it. This is upstream's own design, and conversations remain reachable and cleanable through the `team_id` cascade, which we keep.

Write the migration first and run it against a real database before touching any calling code.

**Files:**
- Create: `database/migrations/2026_08_04_100000_convert_agent_conversations_to_polymorphic_participants.php`
- Test: `tests/Feature/Chat/ConversationParticipantMigrationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the columns `agent_conversations.participant_type`, `agent_conversations.participant_id`, `agent_conversation_messages.participant_type`, `agent_conversation_messages.participant_id`, `agent_conversation_messages.approval_state`. Task 5 renames every query that reads them; Task 6's store writes them.

- [ ] **Step 1: Capture the exact constraint and index names to drop**

```bash
php artisan tinker --execute '
$rows = DB::select("SELECT conname FROM pg_constraint WHERE conrelid IN (\x27agent_conversations\x27::regclass, \x27agent_conversation_messages\x27::regclass) ORDER BY conname");
foreach ($rows as $r) { echo $r->conname . PHP_EOL; }
$idx = DB::select("SELECT indexname FROM pg_indexes WHERE tablename IN (\x27agent_conversations\x27,\x27agent_conversation_messages\x27) ORDER BY indexname");
foreach ($idx as $i) { echo $i->indexname . PHP_EOL; }
'
```

Expected, and these exact names are what the migration drops:
- `agent_conversations_user_id_foreign`
- `agent_conversation_messages_user_id_foreign`
- `agent_conversations_user_id_updated_at_index`
- `agent_conversations_team_id_user_id_updated_at_index`
- `agent_conversation_messages_user_id_index`
- `conversation_index`

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Chat/ConversationParticipantMigrationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('exposes polymorphic participant columns on both conversation tables', function (): void {
    expect(Schema::hasColumns('agent_conversations', ['participant_type', 'participant_id']))->toBeTrue()
        ->and(Schema::hasColumn('agent_conversations', 'user_id'))->toBeFalse()
        ->and(Schema::hasColumns('agent_conversation_messages', ['participant_type', 'participant_id', 'approval_state']))->toBeTrue()
        ->and(Schema::hasColumn('agent_conversation_messages', 'user_id'))->toBeFalse();
});

it('drops the user foreign keys so a polymorphic participant is storable', function (): void {
    $constraints = collect(DB::select(
        "SELECT conname FROM pg_constraint WHERE conrelid IN ('agent_conversations'::regclass, 'agent_conversation_messages'::regclass)"
    ))->pluck('conname');

    expect($constraints)->not->toContain('agent_conversations_user_id_foreign')
        ->and($constraints)->not->toContain('agent_conversation_messages_user_id_foreign')
        ->and($constraints)->toContain('agent_conversations_team_id_foreign');
});

it('stores a conversation participant using the enforced morph alias', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    DB::table('agent_conversations')->insert([
        'id' => (string) Str::uuid(),
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('agent_conversations')->value('participant_type'))->toBe('user');
});
```

- [ ] **Step 3: Run it to verify it fails**

```bash
php artisan test --compact tests/Feature/Chat/ConversationParticipantMigrationTest.php
```

Expected: FAIL — `participant_type` does not exist.

- [ ] **Step 4: Write the migration**

Create `database/migrations/2026_08_04_100000_convert_agent_conversations_to_polymorphic_participants.php`.

Note the deliberate structure: drops come first in their own `Schema::table` calls, then the rename, then the additions, then the backfill, then the new indexes. Mixing a `renameColumn` with index changes in one closure produces statements in an order Postgres rejects. There is no `after()` — Laravel treats it as a no-op on PostgreSQL. No `down()` method, per project rules.

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->dropForeign('agent_conversations_user_id_foreign');
            $table->dropIndex('agent_conversations_user_id_updated_at_index');
            $table->dropIndex('agent_conversations_team_id_user_id_updated_at_index');
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->dropForeign('agent_conversation_messages_user_id_foreign');
            $table->dropIndex('agent_conversation_messages_user_id_index');
            $table->dropIndex('conversation_index');
        });

        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->renameColumn('user_id', 'participant_id');
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->renameColumn('user_id', 'participant_id');
        });

        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->string('participant_type')->nullable();
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->string('participant_type')->nullable();
            $table->text('approval_state')->nullable();
        });

        $participantType = (new User)->getMorphClass();

        DB::table('agent_conversations')
            ->whereNotNull('participant_id')
            ->update(['participant_type' => $participantType]);

        DB::table('agent_conversation_messages')
            ->whereNotNull('participant_id')
            ->update(['participant_type' => $participantType]);

        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
            $table->index(['team_id', 'participant_type', 'participant_id', 'updated_at'], 'team_participant_updated_at_index');
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
            $table->index(['participant_type', 'participant_id'], 'participant_index');
        });
    }
};
```

- [ ] **Step 5: Run the migration and the test**

```bash
php artisan migrate
php artisan test --compact tests/Feature/Chat/ConversationParticipantMigrationTest.php
```

Expected: migration runs clean, all three tests PASS.

- [ ] **Step 6: Verify existing production-shaped rows were backfilled, not blanked**

```bash
php artisan tinker --execute '
echo "conversations: " . DB::table("agent_conversations")->count() . PHP_EOL;
echo "with participant_type: " . DB::table("agent_conversations")->whereNotNull("participant_type")->count() . PHP_EOL;
echo "orphaned (id set, type null): " . DB::table("agent_conversations")->whereNotNull("participant_id")->whereNull("participant_type")->count() . PHP_EOL;
'
```

Expected: the orphaned count is `0`. If your local database is empty, seed a conversation through the chat UI first, roll back to the previous commit, re-migrate, then re-run this task — an untested backfill is the single most dangerous line in this PR.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_04_100000_convert_agent_conversations_to_polymorphic_participants.php tests/Feature/Chat/ConversationParticipantMigrationTest.php
git commit -m "feat: convert agent conversations to polymorphic participants"
```

---

### Task 5: Rename every user_id call site on the conversation tables

The chat package queries these tables directly with the query builder in eighteen places. All of them break silently at runtime — Postgres throws `column "user_id" does not exist` — so the compiler will not help. This task is mechanical but must be exhaustive.

**Files:**
- Modify: `packages/Chat/src/Models/AgentConversation.php:20`
- Modify: `packages/Chat/src/Models/AgentConversationMessage.php:22`
- Modify: `packages/Chat/src/Actions/ListConversations.php:20`
- Modify: `packages/Chat/src/Actions/RenameConversation.php:18`
- Modify: `packages/Chat/src/Actions/ListConversationMessages.php:29,52,64`
- Modify: `packages/Chat/src/Actions/FindConversation.php:16`
- Modify: `packages/Chat/src/Actions/SearchConversations.php:30`
- Modify: `packages/Chat/src/Actions/DeleteConversation.php:18`
- Modify: `packages/Chat/src/Http/Controllers/ChatController.php:88,140,257,277,316`
- Modify: `packages/Chat/src/Jobs/ProcessChatMessage.php:400,421`
- Modify: `packages/Chat/src/Livewire/Chat/ChatInterface.php:133`
- Modify: `packages/Chat/src/Support/FirstChatUsageTagger.php:39`
- Modify: `packages/Chat/routes/channels.php:21`
- Test: `tests/Feature/Chat/ConversationParticipantMigrationTest.php` (extend)

**Interfaces:**
- Consumes: the `participant_type` / `participant_id` columns from Task 4.
- Produces: nothing new. Every conversation-scoped query filters on **both** `participant_type` and `participant_id` after this task.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Chat/ConversationParticipantMigrationTest.php`:

```php
it('scopes a conversation listing to the participant, not just the id', function (): void {
    $user = User::factory()->withTeam()->create();

    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'team_id' => $user->current_team_id,
        'title' => 'Mine',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversations')->insert([
        'id' => (string) Str::uuid(),
        'participant_type' => 'team',
        'participant_id' => $user->getKey(),
        'team_id' => $user->current_team_id,
        'title' => 'Not a user conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $listed = resolve(Relaticle\Chat\Actions\ListConversations::class)->execute($user);

    expect($listed->pluck('id')->all())->toBe([$conversationId]);
});
```

`ListConversations::execute(User $user, int $limit = 50): Collection` already
filters `team_id` by `$user->current_team_id` internally, so the test only needs
to supply the user.

The second row shares the same `participant_id` but a different `participant_type`. A rename that filters on `participant_id` alone returns both rows and fails this test — which is exactly the bug being guarded against.

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact --filter="scopes a conversation listing to the participant"
```

Expected: FAIL — `column "user_id" does not exist`.

- [ ] **Step 3: Find every call site**

```bash
grep -rn "user_id" --include="*.php" packages/Chat/src/ packages/Chat/routes/ \
  | grep -vi "pendingaction\|credit\|feedback\|mentions\|TeamMembersContext\|MyTasksService"
```

Expected: the eighteen sites listed under **Files** above. `TeamMembersContext` and `MyTasksService` reference `user_id` on the `teams` and `team_user` tables — leave them alone.

- [ ] **Step 4: Apply the rename**

For each query-builder call site, replace the single-column filter with a participant pair. The pattern, using `ListConversations.php:20` as the example:

```php
// Before
->where('user_id', $user->getKey())

// After
->where('participant_type', $user->getMorphClass())
->where('participant_id', $user->getKey())
```

For the aliased joins in `ListConversationMessages.php:29`, `ChatInterface.php:133`, and `SearchConversations.php:30`, keep the alias prefix:

```php
// Before
->where('m.user_id', $user->getKey())

// After
->where('m.participant_type', $user->getMorphClass())
->where('m.participant_id', $user->getKey())
```

For the ownership assertions that compare a fetched row — `ChatController.php:88,140,277,316` and `channels.php:21` — compare both fields:

```php
// Before
abort_if($row->user_id !== (string) $user->getKey(), 403);

// After
abort_if(
    $row->participant_type !== $user->getMorphClass()
        || $row->participant_id !== (string) $user->getKey(),
    403,
);
```

For the two inserts — `ChatController.php:257` and `ProcessChatMessage.php:400,421` — write both columns:

```php
// Before
'user_id' => (string) $user->getKey(),

// After
'participant_type' => $user->getMorphClass(),
'participant_id' => (string) $user->getKey(),
```

For the two model docblocks, replace `@property string|null $user_id` with:

```php
 * @property string|null $participant_type
 * @property string|null $participant_id
```

In `AgentConversation.php`, the `user()` relation now needs an explicit foreign key since the column no longer matches the convention:

```php
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_id');
    }
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test --compact tests/Feature/Chat/ConversationParticipantMigrationTest.php
```

Expected: PASS.

- [ ] **Step 6: Prove nothing was missed**

```bash
grep -rn "user_id" --include="*.php" packages/Chat/src/ packages/Chat/routes/ \
  | grep -vi "pendingaction\|credit\|feedback\|mentions\|TeamMembersContext\|MyTasksService"
```

Expected: no output.

```bash
php artisan test --compact tests/Feature/Chat
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/Chat tests/Feature/Chat/ConversationParticipantMigrationTest.php
git commit -m "refactor: scope chat conversation queries by polymorphic participant"
```

---

### Task 6: Upgrade laravel/ai and rebuild the conversation store

The package bump itself, plus the store rewrite it forces. `SupersededAwareConversationStore` currently reimplements `getLatestConversationMessages()` with its own message-mapping logic written against 0.6.8. In 0.10 the parent gained pause reconstruction, provider content blocks, and cross-row resolved-call tracking — all of which the PR 2 resume flow depends on. Our copy must be replaced with the 0.10.2 parent's body plus the single `superseded_at` filter, and our private `mapRecordToMessages()` deleted.

**Files:**
- Modify: `composer.json` (constraint `~0.6.8` → `^0.10.2`), `composer.lock`
- Modify: `packages/Chat/src/Storage/SupersededAwareConversationStore.php`
- Test: `tests/Feature/Chat/SupersededConversationStoreTest.php`

**Interfaces:**
- Consumes: the schema from Task 4, the renamed call sites from Task 5.
- Produces: `SupersededAwareConversationStore` implementing the 0.10 `ConversationStore` contract —
  `storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string`,
  `storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string`,
  `getLatestConversationMessages(string $conversationId, int $limit): Collection`.
  `storeApprovalResults()` is inherited from `DatabaseConversationStore` unchanged — do not override it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Chat/SupersededConversationStoreTest.php`. The second test is the important one: it guards the drift that a hand-copied parent method invites.

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;

function seedConversationRow(string $conversationId, array $attributes = []): string
{
    $messageId = (string) Str::uuid();

    DB::table('agent_conversation_messages')->insert(array_merge([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => null,
        'agent' => 'crm',
        'role' => 'assistant',
        'content' => 'hello',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));

    return $messageId;
}

it('hides superseded turns from replayed history', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    seedConversationRow($conversationId, ['content' => 'kept']);
    seedConversationRow($conversationId, ['content' => 'replaced', 'superseded_at' => now()]);

    $messages = resolve(SupersededAwareConversationStore::class)
        ->getLatestConversationMessages($conversationId, 100);

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toBeInstanceOf(AssistantMessage::class)
        ->and($messages->first()->content)->toBe('kept');
});

it('reconstructs a paused turn with its provider content blocks intact', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    seedConversationRow($conversationId, [
        'content' => 'proposing',
        'tool_calls' => json_encode([[
            'id' => 'call_abc',
            'name' => 'create_company',
            'arguments' => ['records' => [['name' => 'Acme']]],
        ]]),
        'approval_state' => json_encode(['pending' => ['call_abc' => ['tool' => 'create_company']]]),
        'meta' => json_encode([
            'provider' => 'anthropic',
            'provider_content_blocks' => [['type' => 'text', 'text' => 'proposing']],
        ]),
    ]);

    $messages = resolve(SupersededAwareConversationStore::class)
        ->getLatestConversationMessages($conversationId, 100);

    $assistant = $messages->first();

    expect($assistant)->toBeInstanceOf(AssistantMessage::class)
        ->and($assistant->providerContentBlocks)->toBe([['type' => 'text', 'text' => 'proposing']])
        ->and($assistant->providerContentBlocksProvider)->toBe('anthropic')
        ->and($assistant->toolCalls)->toHaveCount(1);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact tests/Feature/Chat/SupersededConversationStoreTest.php
```

Expected: the first test passes, the second FAILS — 0.6.8 has no `providerContentBlocks` on `AssistantMessage`.

- [ ] **Step 3: Upgrade the package**

```bash
composer require laravel/ai:^0.10.2 --no-interaction
```

- [ ] **Step 4: Replace the store**

Overwrite `packages/Chat/src/Storage/SupersededAwareConversationStore.php`. The `getLatestConversationMessages()` body is copied verbatim from `vendor/laravel/ai/src/Storage/DatabaseConversationStore.php` with one added line — `->whereNull('superseded_at')`. Do not paraphrase it; diff the two afterwards to confirm the only difference is that filter.

```php
<?php

declare(strict_types=1);

namespace Relaticle\Chat\Storage;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Relaticle\Chat\Support\AssistantText;
use Relaticle\Chat\Support\FirstChatUsageTagger;

/**
 * Conversation store that hides superseded turns from the agent's history.
 *
 * Regenerate/edit mark replaced turns with superseded_at (see
 * ChatController::supersedeMessages). Without this filter the model keeps
 * "remembering" turns the user replaced — answering "I already proposed that"
 * against a transcript the user can no longer see.
 *
 * getLatestConversationMessages() is the parent's implementation plus a single
 * superseded_at filter. Upstream exposes no query hook, so the body is copied.
 * SupersededConversationStoreTest guards the pause-reconstruction behavior that
 * a stale copy would silently lose.
 */
final class SupersededAwareConversationStore extends DatabaseConversationStore
{
    /**
     * Tag the "has-ai-usage" marketing segment on the subscriber's first
     * chat message. This is the real write path for user turns (see
     * RememberConversation middleware in laravel/ai), unlike the
     * AgentConversationMessage Eloquent model, which never receives writes.
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
    {
        $messageId = parent::storeUserMessage($conversationId, $participantType, $participantId, $prompt);

        FirstChatUsageTagger::tagIfFirstMessage($messageId);

        return $messageId;
    }

    /**
     * Collapse a fully-repeated combined assistant text before persisting.
     *
     * laravel/ai concatenates the model's text deltas across every agent step,
     * so a model that echoes the same acknowledgment in both the tool-call step
     * and the post-tool-result step yields that text repeated back-to-back. We
     * store the single copy instead of the duplicate.
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
    {
        $response->text = AssistantText::collapseRepeated($response->text);

        return parent::storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response);
    }

    /**
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $records = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->whereNull('superseded_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        // A call resolved after an approval pause lands on a later row than the call, so gather every result ID across the window to keep those calls while dropping legacy dangling ones...
        $resolvedCallIds = $records
            ->flatMap(fn ($record) => collect(json_decode((string) $record->tool_results, true))->pluck('id'))
            ->filter()
            ->all();

        return $records
            ->flatMap(function ($record) use ($resolvedCallIds): array {
                $toolCalls = collect(json_decode((string) $record->tool_calls, true))->values();
                $toolResults = collect(json_decode((string) $record->tool_results, true))->values();

                if ($record->role === 'user') {
                    $attachments = $this->rehydrateAttachments($record->attachments);

                    if ($attachments->isNotEmpty()) {
                        return [new UserMessage($record->content, $attachments)];
                    }

                    return [new Message('user', $record->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    return $this->reconstructToolTurn($record, $toolCalls, $toolResults, $resolvedCallIds);
                }

                if ($toolResults->isNotEmpty()) {
                    $messages = [new ToolResultMessage($toolResults->map(ToolResult::fromArray(...)))];

                    if (filled($record->content)) {
                        $messages[] = new AssistantMessage($record->content);
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content)];
            })
            ->skipWhile(fn (Message $message) => $message instanceof ToolResultMessage)
            ->values();
    }
}
```

- [ ] **Step 5: Confirm the copy matches the parent**

```bash
diff <(sed -n '/public function getLatestConversationMessages/,/^    }$/p' vendor/laravel/ai/src/Storage/DatabaseConversationStore.php) \
     <(sed -n '/public function getLatestConversationMessages/,/^    }$/p' packages/Chat/src/Storage/SupersededAwareConversationStore.php)
```

Expected: exactly one added line, `->whereNull('superseded_at')`. Any other difference is a transcription error — fix it before continuing.

- [ ] **Step 6: Run the test to verify it passes**

```bash
php artisan test --compact tests/Feature/Chat/SupersededConversationStoreTest.php
```

Expected: both tests PASS.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock packages/Chat/src/Storage/SupersededAwareConversationStore.php tests/Feature/Chat/SupersededConversationStoreTest.php
git commit -m "feat: upgrade laravel/ai to 0.10.2 and rebuild the conversation store"
```

---

### Task 7: Absorb the faked-gateway test churn

0.9 changed `Agent::fake()` to run faked responses through the real `TextGenerationLoop`. Three consequences hit our chat suite: a faked tool call now yields one extra assistant message in `$response->messages`, streaming emits one extra `ToolCall` event per call, and faking a call to a tool the agent has not registered throws `NoSuchToolException` instead of being skipped silently.

These are assertion changes, not production bugs. Fix the assertions.

**Files:**
- Modify: whichever files under `tests/Feature/Chat/` fail; likely candidates are those asserting message counts or streamed event counts.

**Interfaces:**
- Consumes: the upgraded package from Task 6.
- Produces: a green suite.

- [ ] **Step 1: Run the chat suite and capture every failure**

```bash
php artisan test --compact tests/Feature/Chat 2>&1 | tee .context/pr1-chat-failures.txt
```

Record the failing test names before changing anything.

- [ ] **Step 2: Classify each failure before touching it**

For each failure decide which of three cases it is, and write the verdict down:

1. **Message-count drift** — an assertion like `->toHaveCount(2)` on `$response->messages` that is now 3. Fix the number.
2. **Stream-event-count drift** — a count of broadcast or streamed events that grew by one per tool call. Fix the number.
3. **`NoSuchToolException`** — the test fakes a tool the agent does not register. Register the tool in the fake; do not catch the exception.

If a failure fits none of these, stop. It is a real regression from the upgrade, not churn, and it needs diagnosis rather than an assertion edit. Never weaken an assertion to turn the suite green.

- [ ] **Step 3: Apply the assertion fixes**

Edit only the assertions identified in Step 2. Do not change production code in this task — if production looks wrong, that is a finding to raise, not to patch here.

- [ ] **Step 4: Re-run the chat suite**

```bash
php artisan test --compact tests/Feature/Chat
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Chat
git commit -m "test: absorb laravel/ai 0.9 faked-gateway loop changes"
```

---

### Task 8: Full verification and PR

Everything green together, plus a real browser walk of the chat flow — the suite cannot prove that streaming, broadcasting, and the rewritten store work end to end.

**Files:**
- Modify: `tests/.pest/shards.json` (only if timings shifted materially)

**Interfaces:**
- Consumes: all prior tasks.
- Produces: a PR ready to merge, unblocking PR 2.

- [ ] **Step 1: Run the complete gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
composer test:pest
php -d memory_limit=2G vendor/bin/pest tests/Arch
```

Expected: all pass, type coverage 100%. `composer test:pest:tia` is not a substitute here — it replays cached passes and cannot see a schema change.

- [ ] **Step 2: Walk the chat flow against the real queue**

A sync queue hides the ordering bugs a store rewrite can introduce. Bring up the production-shaped stack first, and confirm `QUEUE_CONNECTION=redis`.

```bash
php artisan horizon &
php artisan reverb:start &
agent-browser --session hitl-pr1 open https://relaticle.test/app/login
```

Log in, open the chat panel, then verify by hand:
1. Send a message and confirm tokens stream in.
2. Reload the page and confirm the transcript replays in the right order.
3. Ask the assistant to create a company and confirm the proposal card still appears — `pending_actions` is untouched by this PR and must still work.
4. Approve it and confirm the company is created.
5. Regenerate a turn and confirm the superseded message disappears and does not come back after a reload.

```bash
agent-browser --session hitl-pr1 errors
agent-browser --session hitl-pr1 screenshot .context/pr1-chat-flow.png
```

Expected: no console errors, all five steps behave exactly as they did before the upgrade.

Note: shared Redis across Herd apps can steal jobs between projects. If a job vanishes, check that no other app's Horizon is running.

- [ ] **Step 3: Refresh the CI shard balance if timings moved**

Only if the suite's per-class timings shifted materially — a stale file silently drops new test classes out of time-balancing.

```bash
composer test:update-shards
```

- [ ] **Step 4: Open the PR**

```bash
git push -u origin ManukMinasyan/chore/laravel-ai-hitl-migration
gh pr create --base main \
  --title "chore: upgrade laravel/ai to 0.10.2 and refresh all dependencies" \
  --body "$(cat <<'BODY'
Brings every outdated direct dependency current and migrates the conversation
tables to laravel/ai 0.10's polymorphic participants. No product behavior
changes — the `pending_actions` approval flow is untouched and still works
exactly as before.

This unblocks the human-in-the-loop migration, which lands separately.
Design: `docs/superpowers/specs/2026-08-04-laravel-ai-hitl-migration-design.md`.

## Changes

- `laravel/ai` 0.6.8 → 0.10.2, with the participant-polymorphism migration and
  the `approval_state` column
- `SupersededAwareConversationStore` rebuilt on the 0.10 parent — our stale copy
  of `getLatestConversationMessages()` predated pause reconstruction and
  provider content blocks
- Both `user_id` foreign keys on the conversation tables dropped; a polymorphic
  participant cannot reference `users`. Deleting a user now leaves a dangling
  `participant_id` rather than nulling it — upstream's own design, and the
  `team_id` cascade still reclaims the rows
- Eighteen chat query call sites rescoped to filter on participant type **and** id
- `asmit/resized-column` 3.x → 4.x, `relaticle/custom-fields` 3.5.5 → 3.6.0, plus
  six batched patch releases and two npm packages

## Test plan

- Full suite, PHPStan level 7, 100% type coverage, Pint, Rector — all green
- New: `ConversationParticipantMigrationTest`, `SupersededConversationStoreTest`
  (the latter guards the copied parent method against drift)
- Browser walk on Redis + Horizon + Reverb: streaming, reload ordering, proposal
  creation, approval, and regenerate/supersede
- Five Filament tables checked for resize behavior after the resized-column major
BODY
)"
```

- [ ] **Step 5: Confirm CI is green**

```bash
gh pr checks --watch
```

Expected: all checks pass. Do not start PR 2 until this merges — PR 2's tasks are written against the 0.10 API and the migrated schema.

---

## Self-Review

**Spec coverage.** Every element of the spec's "PR 1 — the upgrade" section maps to a task: the dependency table to Tasks 1–3, the participant migration and `approval_state` column to Task 4, the store rewrite to Task 6, and the faked-loop test churn to Task 7. The spec's 0.9 sweep item is deliberately absent from the task list because it was checked and found to be a non-issue: the `providerOptions()` change applied to embeddings/transcription builders and provider tools, not to the `HasProviderOptions` agent contract that `CrmAssistant` implements, and the `Lab` enum only gained a case (`OpenAICompatible`). Neither affects us.

**Additions beyond the spec.** Task 5 has no counterpart in the spec, which understated the rename as a schema concern. It is eighteen runtime-breaking query call sites. Task 4 also documents a consequence the spec missed entirely — our conversation tables carry foreign keys on `user_id` that upstream's guide does not anticipate, and dropping them changes user-deletion behavior.

**Deferred.** `CrmAssistant::providerOptions()` sets `disable_parallel_tool_use` and `parallel_tool_calls => false` to force one write per turn. Under PR 2 that constraint may want relaxing so several approvals can pause together. It stays as-is here; PR 1 changes no behavior.
