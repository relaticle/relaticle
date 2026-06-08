# TipTap-Backed Mentions and Server-Owned Conversation Creation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the chat input's `<textarea>` with a TipTap-backed editor (atomic mention chips), persist message content as TipTap document JSON in `agent_conversation_messages.document`, and switch first-message creation to a server-owned `POST /chat/conversations` flow so reload/back/bookmark are idempotent.

**Architecture:** Two interlocking changes that must ship together because they share the wire contract.

1. **TipTap migration.** The chat input becomes a `<div contenteditable>` editor mounted by an `Alpine.data('chatEditor', ...)` factory. Its JSON output (`getDocument()`) replaces the legacy `{message, mentions}` request shape with a single `document` field. Server-side parsing uses `ueberdosis/tiptap-php` (already pulled transitively via Filament Forms) to walk the document tree and derive plain text + structured mention IDs. A new `document jsonb NOT NULL` column on `agent_conversation_messages` stores the editor JSON for both user and assistant messages; user messages save what the editor produced, assistant messages materialize a document at stream end from the final text + mention rows.
2. **Server-owned conversation creation.** A new `POST /chat/conversations` endpoint creates the conversation row, reserves credit, dispatches `ProcessChatMessage` and returns `{conversation_id}`. The dashboard POSTs to it then `location.href = '/chats/' + id`. The conversation page (when no current conversation) POSTs then `history.replaceState`. The legacy `/chat/conversations/init` endpoint and the F-002 auto-title backfill block are deleted.

Sequenced server-first then client. Eleven shippable commits, each independently rollback-safe except step 1 (data wipe).

**Tech Stack:** PHP 8.4, Laravel 12, Filament 5, Livewire 4, Alpine.js 3, TipTap 3 (`@tiptap/core` + `@tiptap/extension-mention` + `@tiptap/suggestion` + `@tiptap/pm` peers), `ueberdosis/tiptap-php` 2.1, Pest 4, Pest Browser plugin, Tailwind CSS 4, PostgreSQL. Pre-commit gate sequence: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse --memory-limit=2G`, `composer test:type-coverage`, then targeted `php artisan test --compact`.

---

## Pre-flight

- [ ] **Step 0a: Confirm working tree is clean and on the right branch**

```bash
cd /Users/manuk/Herd/relaticle
git branch --show-current
git status --short
```

Expected: branch `feat/dashboard-chat`, working tree clean (or only the long-running gitignored noise — `composer.lock`, `public/css/filament/filament/app.css`, `public/js/filament/widgets/...`).

- [ ] **Step 0b: Confirm `ueberdosis/tiptap-php` is already installed**

```bash
composer show ueberdosis/tiptap-php 2>&1 | grep -E "name|versions" | head -3
```

Expected: `name: ueberdosis/tiptap-php`, `versions: * 2.1.0` (or compatible). It's pulled transitively via `filament/forms`. If it's missing, run `composer require ueberdosis/tiptap-php:^2.1` (it should already resolve from the lockfile).

- [ ] **Step 0c: Confirm Node + npm work and the dev server is reachable**

```bash
node --version && npm --version
curl -ksI https://app.relaticle.test/ | head -1
```

Expected: Node 18+ / npm 9+, HTTP/2 200 from the local Herd app.

- [ ] **Step 0d: Establish a baseline test run for the chat scope**

```bash
php artisan test --compact tests/Unit/Chat tests/Feature/Chat
```

Expected: green or only pre-existing flaky tests (consult the prior baseline notes if anything looks new). Note the passing count for the wrap-up comparison.

---

## Task 1: Wipe alpha chat data and add `document jsonb NOT NULL` column

**Files:**
- Create: `database/migrations/2026_05_07_000001_add_document_to_agent_conversation_messages.php`
- Test: `tests/Feature/Chat/MessageDocumentColumnTest.php` (new)

**Why:** The `document` column needs to be NOT NULL from the first migration so the renderer never has a fallback path. Existing alpha rows would violate that constraint, so the same migration truncates the chat tables. Per the spec, this is alpha-only behavior; document the decision in the migration's PHP comment.

- [ ] **Step 1: Write the failing column-existence test**

Create `tests/Feature/Chat/MessageDocumentColumnTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('agent_conversation_messages has a non-null document jsonb column', function (): void {
    expect(Schema::hasColumn('agent_conversation_messages', 'document'))->toBeTrue();

    $columnType = Schema::getColumnType('agent_conversation_messages', 'document');

    // Postgres reports jsonb as 'jsonb' via getColumnType()
    expect($columnType)->toBe('jsonb');
});

it('rejects null document on insert', function (): void {
    $exception = null;

    try {
        \Illuminate\Support\Facades\DB::table('agent_conversation_messages')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'conversation_id' => (string) \Illuminate\Support\Str::uuid7(),
            'agent' => 'crm',
            'role' => 'user',
            'content' => 'hi',
            'document' => null,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (\Throwable $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->getMessage())->toContain('document');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --compact tests/Feature/Chat/MessageDocumentColumnTest.php
```

Expected: both tests FAIL — column doesn't exist yet, so `Schema::hasColumn` returns false and the insert succeeds (no document column to violate).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_05_07_000001_add_document_to_agent_conversation_messages.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a TipTap document JSON column to chat messages.
 *
 * Alpha-phase migration: truncates existing chat data so the column can be
 * NOT NULL from day one. If this migration is ever needed against a database
 * with chat data worth preserving, replace the truncate with a backfill job
 * that walks each message's content + mention rows to reconstruct a
 * document JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Children first so FK constraints don't trip. CASCADE so any rows
        // we missed in the explicit list go too.
        DB::statement('TRUNCATE TABLE agent_conversation_message_mentions, pending_actions, ai_credit_transactions, agent_conversation_messages, agent_conversations RESTART IDENTITY CASCADE');

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->jsonb('document')->after('content');
        });
    }
};
```

- [ ] **Step 4: Run the migration**

```bash
php artisan migrate
```

Expected: migration runs without error. Output mentions `2026_05_07_000001_add_document_to_agent_conversation_messages`.

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test --compact tests/Feature/Chat/MessageDocumentColumnTest.php
```

Expected: both tests pass.

- [ ] **Step 6: Pre-commit gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
composer test:type-coverage
```

All must pass. If Rector suggests changes, run `vendor/bin/rector` and re-stage.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_05_07_000001_add_document_to_agent_conversation_messages.php tests/Feature/Chat/MessageDocumentColumnTest.php
git commit -m "feat(chat): add document jsonb NOT NULL column, wipe alpha chat data"
```

---

## Task 2: TipTapDocumentParser service

**Files:**
- Create: `packages/Chat/src/Services/TipTapDocumentParser.php`
- Create: `tests/Unit/Chat/TipTapDocumentParserTest.php`

**Why:** Single bridge between the editor's JSON shape and the existing `{text, mentions[]}` shape that `ProcessChatMessage` and the agent prompt builder expect. Two methods: `parse(array $document, Team $team)` (JSON → text + mentions, used at submit time) and `buildFromText(string $text, array $mentionRows, Team $team)` (text + IDs → JSON, used at assistant stream-end).

The parser is tenant-scoped: any mention node whose `id` is not visible to the supplied team is dropped before the parse returns. This guards against client-supplied IDs from another tenant.

- [ ] **Step 1: Write the failing test (parse — text-only)**

Create `tests/Unit/Chat/TipTapDocumentParserTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Relaticle\Chat\Services\TipTapDocumentParser;

mutates(TipTapDocumentParser::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
});

it('extracts plain text from a paragraph-only document', function (): void {
    $parser = app(TipTapDocumentParser::class);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Hello world'],
            ],
        ]],
    ];

    $result = $parser->parse($document, $this->team);

    expect($result['text'])->toBe('Hello world');
    expect($result['mentions'])->toBe([]);
});

it('returns empty text for an empty document', function (): void {
    $parser = app(TipTapDocumentParser::class);

    $result = $parser->parse(['type' => 'doc', 'content' => []], $this->team);

    expect($result['text'])->toBe('');
    expect($result['mentions'])->toBe([]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --compact tests/Unit/Chat/TipTapDocumentParserTest.php
```

Expected: FAIL — `Class TipTapDocumentParser not found`.

- [ ] **Step 3: Implement the minimal parser**

Create `packages/Chat/src/Services/TipTapDocumentParser.php`:

```php
<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\Team;
use Tiptap\Editor;

final class TipTapDocumentParser
{
    /**
     * Walk a TipTap document JSON tree.
     *
     * @param  array<string, mixed>  $document
     * @return array{text: string, mentions: list<array{type: string, id: string, label: string}>}
     */
    public function parse(array $document, Team $team): array
    {
        $editor = (new Editor)->setContent($document);

        $text = $editor->getText();

        $mentions = [];

        $editor->descendants(function (object $node) use (&$mentions): void {
            if ($node->type !== 'mention') {
                return;
            }

            $type = $node->attrs->type ?? null;
            $id = $node->attrs->id ?? null;
            $label = $node->attrs->label ?? '';

            if (! is_string($type) || ! is_string($id)) {
                return;
            }

            $mentions[] = [
                'type' => $type,
                'id' => $id,
                'label' => is_string($label) ? $label : '',
            ];
        });

        $mentions = $this->filterToTeam($mentions, $team);

        return [
            'text' => trim($text),
            'mentions' => $mentions,
        ];
    }

    /**
     * @param  list<array{type: string, id: string, label?: string}>  $mentions
     * @return list<array{type: string, id: string, label: string}>
     */
    private function filterToTeam(array $mentions, Team $team): array
    {
        if ($mentions === []) {
            return [];
        }

        // Group by type for efficient lookup
        $byType = [];
        foreach ($mentions as $m) {
            $byType[$m['type']][] = $m['id'];
        }

        $authorized = [];
        foreach ($byType as $type => $ids) {
            $modelClass = $this->modelForType($type);
            if ($modelClass === null) {
                continue;
            }

            $found = $modelClass::query()
                ->whereBelongsTo($team)
                ->whereIn('id', array_unique($ids))
                ->pluck('id')
                ->all();

            $authorized[$type] = array_flip($found);
        }

        $result = [];
        foreach ($mentions as $m) {
            if (isset($authorized[$m['type']][$m['id']])) {
                $result[] = [
                    'type' => $m['type'],
                    'id' => $m['id'],
                    'label' => $m['label'] ?? '',
                ];
            }
        }

        return $result;
    }

    private function modelForType(string $type): ?string
    {
        return match ($type) {
            'company' => \App\Models\Company::class,
            'people' => \App\Models\People::class,
            'opportunity' => \App\Models\Opportunity::class,
            'task' => \App\Models\Task::class,
            'note' => \App\Models\Note::class,
            default => null,
        };
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test --compact tests/Unit/Chat/TipTapDocumentParserTest.php
```

Expected: 2 passing.

- [ ] **Step 5: Add the mention-extraction tests**

Append to `tests/Unit/Chat/TipTapDocumentParserTest.php`:

```php
it('extracts mention nodes alongside text', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $company = \App\Models\Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Tell me about '],
                ['type' => 'mention', 'attrs' => [
                    'type' => 'company',
                    'id' => $company->getKey(),
                    'label' => 'Acme Corp',
                ]],
                ['type' => 'text', 'text' => ' please'],
            ],
        ]],
    ];

    $result = $parser->parse($document, $this->team);

    expect($result['mentions'])->toHaveCount(1);
    expect($result['mentions'][0])->toMatchArray([
        'type' => 'company',
        'id' => $company->getKey(),
        'label' => 'Acme Corp',
    ]);
});

it('drops mentions whose entity belongs to a different team', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $otherTeam = \App\Models\User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignCompany = \App\Models\Company::factory()->for($otherTeam)->create();

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'mention', 'attrs' => [
                    'type' => 'company',
                    'id' => $foreignCompany->getKey(),
                    'label' => 'Foreign',
                ]],
            ],
        ]],
    ];

    $result = $parser->parse($document, $this->team);

    expect($result['mentions'])->toBe([]);
});

it('drops mentions of unknown entity types', function (): void {
    $parser = app(TipTapDocumentParser::class);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'mention', 'attrs' => [
                    'type' => 'invoice',  // not a known type
                    'id' => '01k...',
                    'label' => 'INV-1',
                ]],
            ],
        ]],
    ];

    $result = $parser->parse($document, $this->team);

    expect($result['mentions'])->toBe([]);
});
```

- [ ] **Step 6: Run the new tests**

```bash
php artisan test --compact tests/Unit/Chat/TipTapDocumentParserTest.php
```

Expected: 5 passing.

- [ ] **Step 7: Implement `buildFromText`**

Append the method to `packages/Chat/src/Services/TipTapDocumentParser.php` (inside the class, after `parse`):

```php
    /**
     * Build a TipTap document from plain text plus a list of stored mention rows.
     * Used at assistant stream-end to materialize the document for the assistant message.
     *
     * The text is split on each mention's label. Each label found in the text
     * (longest-first to avoid partial-prefix collisions) is replaced with a
     * mention node. Anything not matched stays as plain text nodes.
     *
     * @param  list<array{type: string, id: string, label: string}>  $mentionRows
     * @return array<string, mixed>
     */
    public function buildFromText(string $text, array $mentionRows, Team $team): array
    {
        if ($text === '') {
            return ['type' => 'doc', 'content' => []];
        }

        $authorized = $this->filterToTeam($mentionRows, $team);

        // Sort by label length descending so longer labels don't get split by
        // shorter substring matches (e.g. "Acme" vs "Acme Holdings").
        usort($authorized, static fn (array $a, array $b): int => mb_strlen($b['label']) <=> mb_strlen($a['label']));

        $nodes = $this->splitTextWithMentions($text, $authorized);

        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => $nodes,
            ]],
        ];
    }

    /**
     * @param  list<array{type: string, id: string, label: string}>  $mentions
     * @return list<array<string, mixed>>
     */
    private function splitTextWithMentions(string $text, array $mentions): array
    {
        if ($mentions === [] || $text === '') {
            return $text === '' ? [] : [['type' => 'text', 'text' => $text]];
        }

        $segments = [['type' => 'text', 'text' => $text]];

        foreach ($mentions as $mention) {
            $needle = $mention['label'];
            if ($needle === '') {
                continue;
            }

            $next = [];
            foreach ($segments as $segment) {
                if ($segment['type'] !== 'text') {
                    $next[] = $segment;
                    continue;
                }

                $parts = explode($needle, $segment['text']);
                $lastIndex = count($parts) - 1;
                foreach ($parts as $i => $part) {
                    if ($part !== '') {
                        $next[] = ['type' => 'text', 'text' => $part];
                    }
                    if ($i < $lastIndex) {
                        $next[] = [
                            'type' => 'mention',
                            'attrs' => [
                                'type' => $mention['type'],
                                'id' => $mention['id'],
                                'label' => $mention['label'],
                            ],
                        ];
                    }
                }
            }
            $segments = $next;
        }

        return $segments;
    }
```

- [ ] **Step 8: Add tests for `buildFromText`**

Append to `tests/Unit/Chat/TipTapDocumentParserTest.php`:

```php
it('builds a document from text without mentions', function (): void {
    $parser = app(TipTapDocumentParser::class);

    $document = $parser->buildFromText('Hello world', [], $this->team);

    expect($document)->toBe([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Hello world'],
            ],
        ]],
    ]);
});

it('returns an empty document for empty text', function (): void {
    $parser = app(TipTapDocumentParser::class);

    expect($parser->buildFromText('', [], $this->team))->toBe([
        'type' => 'doc',
        'content' => [],
    ]);
});

it('embeds mention nodes when labels appear in the text', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $company = \App\Models\Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $document = $parser->buildFromText(
        'Found Acme in the system',
        [['type' => 'company', 'id' => $company->getKey(), 'label' => 'Acme']],
        $this->team,
    );

    expect($document['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'Found '],
        ['type' => 'mention', 'attrs' => [
            'type' => 'company',
            'id' => $company->getKey(),
            'label' => 'Acme',
        ]],
        ['type' => 'text', 'text' => ' in the system'],
    ]);
});

it('matches longer labels before shorter overlapping ones', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $teamA = \App\Models\Company::factory()->for($this->team)->create(['name' => 'Acme']);
    $teamAB = \App\Models\Company::factory()->for($this->team)->create(['name' => 'Acme Holdings']);

    $document = $parser->buildFromText(
        'See Acme Holdings, sister of Acme',
        [
            ['type' => 'company', 'id' => $teamA->getKey(), 'label' => 'Acme'],
            ['type' => 'company', 'id' => $teamAB->getKey(), 'label' => 'Acme Holdings'],
        ],
        $this->team,
    );

    $nodes = $document['content'][0]['content'];

    // Longer match wins: "Acme Holdings" becomes a single chip
    expect($nodes[1])->toMatchArray([
        'type' => 'mention',
        'attrs' => ['label' => 'Acme Holdings'],
    ]);
    // Trailing "Acme" still chips as the shorter label
    expect(end($nodes))->toMatchArray([
        'type' => 'mention',
        'attrs' => ['label' => 'Acme'],
    ]);
});
```

- [ ] **Step 9: Run all parser tests**

```bash
php artisan test --compact tests/Unit/Chat/TipTapDocumentParserTest.php
```

Expected: 9 passing.

- [ ] **Step 10: Pre-commit gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
composer test:type-coverage
```

If Rector suggests changes, run `vendor/bin/rector` and re-stage.

- [ ] **Step 11: Commit**

```bash
git add packages/Chat/src/Services/TipTapDocumentParser.php tests/Unit/Chat/TipTapDocumentParserTest.php
git commit -m "feat(chat): add TipTapDocumentParser service with parse and buildFromText"
```

---

## Task 3: `/chat/send` accepts `document`, persists to user message

**Files:**
- Modify: `packages/Chat/src/Http/Controllers/ChatController.php` (the `send` method)
- Modify: `packages/Chat/src/Jobs/ProcessChatMessage.php` (constructor + persistence)
- Test: `tests/Feature/Chat/ChatSendDocumentTest.php` (new)
- Modify: existing tests in `tests/Feature/Chat/` that POST to `/chat/send` with `{message, mentions}` shape

**Why:** Move the wire contract from `{message, mentions[]}` to `{document}`. The controller parses the document via `TipTapDocumentParser`, derives the existing `text` and `mentions[]` for downstream code paths (so `ProcessChatMessage`, `agent->prompt`, mention persistence all continue to work unchanged), and additionally passes the raw `document` JSON to `ProcessChatMessage` so the user message row can be updated with `document` after the laravel/ai `ConversationStore` inserts it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Chat/ChatSendDocumentTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AiCreditBalance;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);

    AiCreditBalance::query()->create([
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->conversationId = (string) Str::uuid7();
    AgentConversation::query()->insert([
        'id' => $this->conversationId,
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('accepts a document field and dispatches with derived text and mentions', function (): void {
    Queue::fake();
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Tell me about '],
                ['type' => 'mention', 'attrs' => [
                    'type' => 'company',
                    'id' => $company->getKey(),
                    'label' => 'Acme',
                ]],
            ],
        ]],
    ];

    $this->postJson(route('chat.send'), [
        'conversation_id' => $this->conversationId,
        'document' => $document,
    ])->assertOk();

    Queue::assertPushed(\Relaticle\Chat\Jobs\ProcessChatMessage::class, function ($job) use ($document, $company): bool {
        return $job->message === 'Tell me about Acme'
            && count($job->mentions) === 1
            && $job->mentions[0]['id'] === $company->getKey()
            && $job->document === $document;
    });
});

it('returns 422 when the document field is missing', function (): void {
    $this->postJson(route('chat.send'), [
        'conversation_id' => $this->conversationId,
    ])->assertStatus(422);
});

it('returns 422 when the document is empty (no text content)', function (): void {
    $this->postJson(route('chat.send'), [
        'conversation_id' => $this->conversationId,
        'document' => ['type' => 'doc', 'content' => []],
    ])->assertStatus(422);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --compact tests/Feature/Chat/ChatSendDocumentTest.php
```

Expected: 3 FAIL — current send validates `message` instead of `document`.

- [ ] **Step 3: Update `ChatController::send` validator and parsing**

In `packages/Chat/src/Http/Controllers/ChatController.php`, locate the `send()` method (currently around line 38). Replace the validator block at the top of the method with:

```php
$validated = $request->validate([
    'document' => ['required', 'array'],
    'model' => ['nullable', 'string', \Illuminate\Validation\Rule::enum(\Relaticle\Chat\Enums\AiModel::class)],
    'conversation_id' => ['nullable', 'string', 'uuid'],
]);
```

(Remove the `'message'` and `'mentions.*'` validation rules.)

Inject `TipTapDocumentParser` via constructor injection. Locate the existing constructor (around line 32) and add the parser to its argument list. PHP 8.4 constructor property promotion:

```php
public function __construct(
    private readonly ConversationStore $conversationStore,
    private readonly CreditService $creditService,
    private readonly AiModelResolver $modelResolver,
    private readonly \Relaticle\Chat\Services\TipTapDocumentParser $documentParser,
) {}
```

(Adjust to match the existing constructor's exact argument list — add `TipTapDocumentParser` as a new parameter, keep the rest.)

Inside `send()`, after `$user = $request->user();` and `$team = $user->currentTeam;`, add the parse:

```php
$parsed = $this->documentParser->parse($validated['document'], $team);

if ($parsed['text'] === '') {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'document' => 'Message is empty.',
    ]);
}
```

Then, where the original code referenced `$validated['message']`, use `$parsed['text']`. Where it referenced `$validated['mentions']`, use `$parsed['mentions']`. Specifically:

- The `TitleSanitizer::clean(...)` calls become `TitleSanitizer::clean($parsed['text'])`.
- The `dispatch(new ProcessChatMessage(...))` call's `message:` argument becomes `$parsed['text']`.
- The `mentions:` argument becomes `$parsed['mentions']`.
- Add a new `document:` argument: `document: $validated['document']`.

The `resolveMentions($validated['mentions'] ?? [], $team)` call goes away — `$parsed['mentions']` is already team-filtered.

- [ ] **Step 4: Update `ProcessChatMessage` to accept and persist the document**

In `packages/Chat/src/Jobs/ProcessChatMessage.php`, add a `document` argument to the constructor. PHP 8.4 constructor property promotion:

```php
public function __construct(
    public readonly User $user,
    public readonly Team $team,
    public readonly string $message,
    public readonly string $conversationId,
    public readonly array $resolved,
    public readonly array $mentions,
    public readonly array $document,
) {}
```

(Adjust to match the existing constructor — add `document: array` as the last property-promoted argument, with `array<string, mixed>` PHPDoc above the constructor if needed.)

Add a private method `persistUserDocument()` near the existing `persistMentions()` method:

```php
private function persistUserDocument(): void
{
    DB::table('agent_conversation_messages')
        ->where('conversation_id', $this->conversationId)
        ->where('role', 'user')
        ->latest('created_at')
        ->limit(1)
        ->update(['document' => json_encode($this->document, JSON_THROW_ON_ERROR)]);
}
```

Call `$this->persistUserDocument()` immediately after the existing call to `$this->persistMentions()` (so it runs once the user message has been persisted by the agent's `ConversationStore`).

- [ ] **Step 5: Run the new test**

```bash
php artisan test --compact tests/Feature/Chat/ChatSendDocumentTest.php
```

Expected: 3 passing.

- [ ] **Step 6: Update existing tests that POST `{message, mentions}` to `/chat/send`**

Run `grep` to find affected tests:

```bash
grep -ln "route.'chat.send'\|'chat.send'\|/chat/send" tests/Feature/Chat tests/Browser/Chat 2>/dev/null
```

For each file that POSTs `{message, mentions}`, rewrite the request payload to `{document}`. Example transformation:

Before:
```php
$this->postJson(route('chat.send'), [
    'conversation_id' => $id,
    'message' => 'Hello',
    'mentions' => [['type' => 'company', 'id' => $company->getKey()]],
])
```

After:
```php
$this->postJson(route('chat.send'), [
    'conversation_id' => $id,
    'document' => [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Hello'],
                ['type' => 'mention', 'attrs' => [
                    'type' => 'company',
                    'id' => $company->getKey(),
                    'label' => $company->name,
                ]],
            ],
        ]],
    ],
])
```

For text-only messages, the document is just `{type: 'doc', content: [{type: 'paragraph', content: [{type: 'text', text: 'Hello'}]}]}`.

A helper makes this less repetitive — add it to `tests/Pest.php` or a new `tests/Helpers/ChatDocument.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers;

final class ChatDocument
{
    /**
     * @param  list<array{type: string, id: string, label: string}>  $mentions
     * @return array<string, mixed>
     */
    public static function fromText(string $text, array $mentions = []): array
    {
        $content = [['type' => 'text', 'text' => $text]];
        foreach ($mentions as $mention) {
            $content[] = [
                'type' => 'mention',
                'attrs' => [
                    'type' => $mention['type'],
                    'id' => $mention['id'],
                    'label' => $mention['label'],
                ],
            ];
        }

        return [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => $content]],
        ];
    }
}
```

Then in tests: `'document' => \Tests\Helpers\ChatDocument::fromText('Hello')`.

- [ ] **Step 7: Run the broader chat suite**

```bash
php artisan test --compact tests/Feature/Chat
```

Expected: every test passes. Any failure here is a test that hadn't been updated — fix until green.

- [ ] **Step 8: Pre-commit gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
composer test:type-coverage
```

- [ ] **Step 9: Commit**

```bash
git add packages/Chat/src/Http/Controllers/ChatController.php packages/Chat/src/Jobs/ProcessChatMessage.php tests/Feature/Chat/ChatSendDocumentTest.php tests/Helpers/ChatDocument.php tests/Feature/Chat/*.php
git commit -m "feat(chat): /chat/send accepts document, persists to user message row"
```

---

## Task 4: Assistant messages materialize document at stream end

**Files:**
- Modify: `packages/Chat/src/Jobs/ProcessChatMessage.php`
- Test: `tests/Feature/Chat/ProcessChatMessageDocumentMaterializationTest.php` (new)

**Why:** When the LLM stream completes, the assistant message row exists in `agent_conversation_messages` (inserted by laravel/ai's ConversationStore) with `content` populated and `document` null. Compute the document from the final text + the message's mention rows (extracted from tool results, populated by existing `persistMentions` logic but for assistant messages we need to extract entities from tool results).

For v1 we only need user-mention support; assistant messages get `document = buildFromText(content, [], $team)` — i.e. no mention chips in assistant prose. This matches today's behavior (assistant messages don't have mentions stored against them in the join table — `persistMentions` only persists for the user side per the current code path). Future work can extract structured entity references from tool results and chip them up.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Chat/ProcessChatMessageDocumentMaterializationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\TipTapDocumentParser;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);

    AiCreditBalance::query()->create([
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

it('persists document jsonb on assistant message row at stream end', function (): void {
    $conversationId = (string) Str::uuid7();
    AgentConversation::query()->insert([
        'id' => $conversationId,
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Simulate an assistant message row inserted by the conversation store
    $assistantId = (string) Str::ulid();
    DB::table('agent_conversation_messages')->insert([
        'id' => $assistantId,
        'conversation_id' => $conversationId,
        'agent' => 'crm',
        'role' => 'assistant',
        'content' => 'I found 2 deals.',
        'document' => json_encode(['type' => 'doc', 'content' => []]),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Simulate the stream-end materialization
    $parser = app(TipTapDocumentParser::class);
    $document = $parser->buildFromText('I found 2 deals.', [], $this->team);

    DB::table('agent_conversation_messages')
        ->where('id', $assistantId)
        ->update(['document' => json_encode($document)]);

    $row = DB::table('agent_conversation_messages')->where('id', $assistantId)->first();
    $stored = json_decode($row->document, associative: true);

    expect($stored)->toMatchArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'I found 2 deals.']],
        ]],
    ]);
});
```

This test stubs out the streaming side and verifies the materialization shape. The integration with the actual stream completion is exercised by the existing `tests/Feature/Chat/ProcessChatMessageTest.php`.

- [ ] **Step 2: Run the test**

```bash
php artisan test --compact tests/Feature/Chat/ProcessChatMessageDocumentMaterializationTest.php
```

Expected: PASS — pure parser-and-DB exercise that doesn't require the new code yet. Keep this test as a contract guard for the materialization shape.

- [ ] **Step 3: Add `materializeAssistantDocument` to `ProcessChatMessage`**

In `packages/Chat/src/Jobs/ProcessChatMessage.php`, locate where the streaming finishes (search for `$streamedResponse` or the existing `persistMentions()` call). Add a new private method:

```php
private function materializeAssistantDocument(\Laravel\Ai\StreamedAgentResponse $streamedResponse): void
{
    $assistantContent = $streamedResponse->text ?? '';

    if ($assistantContent === '') {
        return;
    }

    $document = $this->documentParser->buildFromText($assistantContent, [], $this->team);

    DB::table('agent_conversation_messages')
        ->where('conversation_id', $this->conversationId)
        ->where('role', 'assistant')
        ->latest('created_at')
        ->limit(1)
        ->update(['document' => json_encode($document, JSON_THROW_ON_ERROR)]);
}
```

(Adjust the `StreamedAgentResponse` import if the existing job already imports it under a different alias.)

Inject `TipTapDocumentParser` via the constructor — add `private readonly TipTapDocumentParser $documentParser` to the constructor (or, if `ProcessChatMessage` resolves dependencies via `app()`, use `resolve(TipTapDocumentParser::class)` inside the method). Since the job is dispatched with constructor args, the cleanest path is to add a method that resolves it lazily:

```php
private function getParser(): \Relaticle\Chat\Services\TipTapDocumentParser
{
    return resolve(\Relaticle\Chat\Services\TipTapDocumentParser::class);
}
```

…and call `$this->getParser()->buildFromText(...)` inside `materializeAssistantDocument`.

Call `$this->materializeAssistantDocument($streamedResponse)` immediately after the existing post-stream persistence calls (after `persistMentions()`, after `persistUserDocument()` from Task 3).

- [ ] **Step 4: Run the contract test again**

```bash
php artisan test --compact tests/Feature/Chat/ProcessChatMessageDocumentMaterializationTest.php
```

Expected: still PASS.

- [ ] **Step 5: Run the broader chat suite to catch regressions**

```bash
php artisan test --compact tests/Feature/Chat tests/Unit/Chat
```

Expected: green.

- [ ] **Step 6: Pre-commit gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
composer test:type-coverage
```

- [ ] **Step 7: Commit**

```bash
git add packages/Chat/src/Jobs/ProcessChatMessage.php tests/Feature/Chat/ProcessChatMessageDocumentMaterializationTest.php
git commit -m "feat(chat): assistant messages materialize document at stream end"
```

---

## Task 5: New `POST /chat/conversations` endpoint

**Files:**
- Modify: `packages/Chat/routes/chat.php` (add new route, do not yet remove init)
- Modify: `packages/Chat/src/Http/Controllers/ChatController.php` (add `createConversation` method)
- Test: `tests/Feature/Chat/CreateConversationTest.php` (new)

**Why:** Single endpoint that creates a conversation row + dispatches the first message. The dashboard and conversation page (when no current conversation) both POST to it. Returns `{conversation_id}`. The legacy `/chat/conversations/init` endpoint stays in place during this task; it's deleted in Task 8 once both clients have migrated.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Chat/CreateConversationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AiCreditBalance;
use Tests\Helpers\ChatDocument;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);

    AiCreditBalance::query()->create([
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

it('creates a conversation, dispatches the message, and returns the new id', function (): void {
    Queue::fake();

    $response = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Hello world'),
    ])->assertOk();

    $conversationId = $response->json('conversation_id');
    expect($conversationId)->toBeString()->not->toBeEmpty();

    expect(AgentConversation::query()->find($conversationId))->not->toBeNull();
    expect(AgentConversation::query()->find($conversationId)->title)->toBe('Hello world');

    Queue::assertPushed(ProcessChatMessage::class, function ($job) use ($conversationId): bool {
        return $job->conversationId === $conversationId
            && $job->message === 'Hello world';
    });
});

it('returns 422 when document is missing', function (): void {
    $this->postJson(route('chat.conversations.create'), [])->assertStatus(422);
});

it('returns 422 when document text is empty', function (): void {
    $this->postJson(route('chat.conversations.create'), [
        'document' => ['type' => 'doc', 'content' => []],
    ])->assertStatus(422);
});

it('returns 402 when credit balance is insufficient', function (): void {
    AiCreditBalance::query()->where('team_id', $this->team->getKey())->update([
        'credits_remaining' => 0,
    ]);

    $response = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Hi'),
    ])->assertStatus(402);

    expect($response->json('error'))->toBe('credits_exhausted');
});

it('filters cross-tenant mention IDs from the document before persisting', function (): void {
    Queue::fake();
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignCompany = Company::factory()->for($otherTeam)->create(['name' => 'Foreign']);

    $document = ChatDocument::fromText('Hi ', [
        ['type' => 'company', 'id' => $foreignCompany->getKey(), 'label' => 'Foreign'],
    ]);

    $this->postJson(route('chat.conversations.create'), [
        'document' => $document,
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class, function ($job): bool {
        return $job->mentions === [];
    });
});
```

- [ ] **Step 2: Add the route**

In `packages/Chat/routes/chat.php`, add the new route inside the `auth:web` middleware group, alongside the existing `/chat/conversations` routes:

```php
Route::post('/chat/conversations/create', [ChatController::class, 'createConversation'])
    ->name('chat.conversations.create');
```

(Note: a previous `/chat/conversations` POST route exists for `init`. Use the path `/chat/conversations/create` to avoid conflict during the migration window. In Task 8 we delete `init` and rename `/conversations/create` → `/conversations` if desired; for now keep the explicit suffix.)

- [ ] **Step 3: Run the test to verify it fails**

```bash
php artisan test --compact tests/Feature/Chat/CreateConversationTest.php
```

Expected: 5 FAIL — controller method doesn't exist yet, route returns 404.

- [ ] **Step 4: Implement `createConversation`**

In `packages/Chat/src/Http/Controllers/ChatController.php`, add a new public method (placement: just after the existing `init()` method for proximity):

```php
public function createConversation(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
{
    $validated = $request->validate([
        'document' => ['required', 'array'],
        'model' => ['nullable', 'string', \Illuminate\Validation\Rule::enum(\Relaticle\Chat\Enums\AiModel::class)],
    ]);

    /** @var \App\Models\User $user */
    $user = $request->user();
    $team = $user->currentTeam;
    abort_if($team === null, 403);

    if (! $this->creditService->reserveCredit($team)) {
        $balance = \Relaticle\Chat\Models\AiCreditBalance::query()
            ->where('team_id', $team->getKey())
            ->first();

        return response()->json([
            'error' => 'credits_exhausted',
            'message' => 'You have used all your AI credits for this billing period.',
            'reset_at' => $balance?->period_ends_at?->toIso8601String(),
            'upgrade_url' => url('/app/billing'),
        ], 402);
    }

    $parsed = $this->documentParser->parse($validated['document'], $team);

    if ($parsed['text'] === '') {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'document' => 'Message is empty.',
        ]);
    }

    $conversationId = (string) \Illuminate\Support\Str::uuid7();

    \Illuminate\Support\Facades\DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => \Relaticle\Chat\Support\TitleSanitizer::clean($parsed['text']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resolved = $this->modelResolver->resolve($user, $validated['model'] ?? null);

    dispatch(new \Relaticle\Chat\Jobs\ProcessChatMessage(
        user: $user,
        team: $team,
        message: $parsed['text'],
        conversationId: $conversationId,
        resolved: $resolved,
        mentions: $parsed['mentions'],
        document: $validated['document'],
    ));

    return response()->json(['conversation_id' => $conversationId]);
}
```

- [ ] **Step 5: Run the new test**

```bash
php artisan test --compact tests/Feature/Chat/CreateConversationTest.php
```

Expected: 5 passing.

- [ ] **Step 6: Run the broader suite**

```bash
php artisan test --compact tests/Feature/Chat
```

Expected: green.

- [ ] **Step 7: Pre-commit gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
composer test:type-coverage
```

- [ ] **Step 8: Commit**

```bash
git add packages/Chat/routes/chat.php packages/Chat/src/Http/Controllers/ChatController.php tests/Feature/Chat/CreateConversationTest.php
git commit -m "feat(chat): add POST /chat/conversations/create endpoint"
```

---

## Task 6: Dashboard POSTs to `/chat/conversations/create` and redirects

**Files:**
- Modify: `packages/Chat/resources/views/filament/pages/dashboard.blade.php`
- Modify: `app/Filament/Pages/ChatConversation.php` (drop `?message=` and `?model=` parsing)
- Test: `tests/Browser/Chat/DashboardFirstSendTest.php` (new)

**Why:** Dashboard's `submit()` currently does `location.href = '/chats?message=X&model=Y'`. This task replaces that with `fetch('/chat/conversations/create', {...})` then `location.href = '/chats/' + id`. The legacy `?message=` and `?model=` query-param parsing in `ChatConversation::mount()` becomes dead — remove it.

- [ ] **Step 1: Write the failing browser test**

Create `tests/Browser/Chat/DashboardFirstSendTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Models\AiCreditBalance;

it('navigates to the new conversation after first send from dashboard', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->create([
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}")
        ->type('[placeholder="Ask anything..."]', 'Hello')
        ->click('[aria-label="Send message"]')
        ->waitForLocation('/chats/');

    expect(parse_url($this->page()->url(), PHP_URL_PATH))
        ->toContain('/chats/');
});
```

(`waitForLocation` API name verified at write time — alternative is `waitForPath` or polling via `getCurrentLocation()`.)

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --compact tests/Browser/Chat/DashboardFirstSendTest.php
```

Expected: FAIL — current dashboard URL goes to `/chats?message=Hello`, not `/chats/{id}`.

- [ ] **Step 3: Update the dashboard's `submit()` factory method**

In `packages/Chat/resources/views/filament/pages/dashboard.blade.php`, locate the `submit()` method inside the `Alpine.data('dashboardChatInput', ...)` factory in the `@script` block (search for `'submit() {'` or the existing `location.href = '/chats?message='` line). Replace its body with:

```javascript
async submit() {
    if (this.editor && this.editor.isEmpty?.()) return;
    if (this.input.trim() === '' || this.submitting) return;
    this.submitting = true;
    this.error = null;

    const document = this.editor?.getDocument?.() ?? this.documentFromInput();

    try {
        const res = await fetch(@js(route('chat.conversations.create')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                document,
                model: this.selectedModel !== 'auto' ? this.selectedModel : undefined,
            }),
        });

        if (!res.ok) {
            this.submitting = false;
            if (res.status === 422) {
                const body = await res.json().catch(() => ({}));
                this.error = body?.errors?.document?.[0] ?? 'Message is empty.';
            } else if (res.status === 402) {
                window.location.href = @js(url('/app/billing'));
            } else {
                this.error = 'Could not send. Try again.';
            }
            return;
        }

        const { conversation_id } = await res.json();
        const url = new URL(@js(\App\Filament\Pages\ChatConversation::getUrl()), window.location.origin);
        url.pathname = url.pathname.replace(/\/?$/, '/' + conversation_id);
        window.location.href = url.toString();
    } catch (_) {
        this.submitting = false;
        this.error = 'Network error. Try again.';
    }
},
```

(Note: the `getDocument()` call assumes Task 9 has shipped. Until then, provide a fallback `documentFromInput()` helper that wraps `this.input` in a minimal TipTap doc structure. Add this helper to the factory:)

```javascript
documentFromInput() {
    const text = this.input.trim();
    if (text === '') {
        return { type: 'doc', content: [] };
    }
    return {
        type: 'doc',
        content: [{
            type: 'paragraph',
            content: [{ type: 'text', text }],
        }],
    };
},
```

- [ ] **Step 4: Drop the query-param parsing in `ChatConversation::mount()`**

In `app/Filament/Pages/ChatConversation.php`, locate the `mount` method. Remove the `$message` and `$model` query parsing — at the time this task lands, the dashboard has stopped sending them. The `mount` body becomes:

```php
public function mount(?string $conversationId = null): void
{
    $this->conversationId = $conversationId;

    if ($this->conversationId) {
        /** @var User $user */
        $user = Filament::auth()->user();

        $this->conversationTitle = (new FindConversation)
            ->execute($user, $this->conversationId)?->title;
    }

    $this->initialMessage = null;
    $this->initialModel = null;
}
```

(Keep the public properties `$initialMessage` and `$initialModel` — they're consumed by the chat-interface Blade view and removing them is part of Task 8.)

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test --compact tests/Browser/Chat/DashboardFirstSendTest.php
```

Expected: PASS.

- [ ] **Step 6: Manually verify in agent-browser**

```bash
agent-browser open https://app.relaticle.test/manuk-minasyans-team-trowx
# (developer login if needed)
agent-browser type 'textarea[placeholder="Ask anything..."]' 'Hello world'
agent-browser click '[aria-label="Send message"]'
sleep 2
agent-browser get url
```

Expected: URL is `/manuk-minasyans-team-trowx/chats/{ulid}`, not `/chats?message=Hello`.

- [ ] **Step 7: Pre-commit gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
composer test:type-coverage
```

- [ ] **Step 8: Commit**

```bash
git add packages/Chat/resources/views/filament/pages/dashboard.blade.php app/Filament/Pages/ChatConversation.php tests/Browser/Chat/DashboardFirstSendTest.php
git commit -m "refactor(chat): dashboard POSTs to /chat/conversations/create + redirects"
```

---

## Task 7: Conversation page first-send via `/chat/conversations/create` + replaceState

**Files:**
- Modify: `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php`
- Test: `tests/Browser/Chat/ConversationFirstSendTest.php` (new)

**Why:** When the user lands on `/chats` (no conversationId) and types a first message, today the frontend mints a UUID7 client-side and calls `/chat/conversations/init` then `/chat/send`. After this task, that path POSTs to `/chat/conversations/create` and uses `history.replaceState` to update the URL in place — preserving the existing optimistic streaming feel without a full page nav.

- [ ] **Step 1: Write the failing browser test**

Create `tests/Browser/Chat/ConversationFirstSendTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Models\AiCreditBalance;

it('updates URL via replaceState after first send on /chats', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->create([
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->navigate("/app/{$team->slug}/chats");

    // Mark a property on the editor element so we can detect a full page reload (which would erase it)
    $this->page()->evaluate('window.__pageMountedAt = Date.now()');

    $this->type('[data-chat-context="conversation"] textarea, [data-chat-context="conversation"] [contenteditable="true"]', 'Hello')
        ->click('[data-chat-context="conversation"] [aria-label="Send message"]')
        ->waitForLocation('/chats/');

    // If the page didn't reload, our marker should still exist
    $marker = $this->page()->evaluate('window.__pageMountedAt');
    expect($marker)->toBeNumeric();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --compact tests/Browser/Chat/ConversationFirstSendTest.php
```

Expected: FAIL — current first-send flow goes through init+send and stays on `/chats` (no `{id}` segment) until ProcessChatMessage updates state, OR full-reload pattern wipes the marker.

- [ ] **Step 3: Patch the conversation-page first-send branch**

In `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php`, locate `sendMessage()` in the `chatInterface()` Alpine factory (search for `async sendMessage()`). Add an early branch that handles the `conversationId === null` case:

```javascript
async sendMessage() {
    const text = this.input.trim();
    if (!text || this.isStreaming) return;
    if (text.length > 5000) return;

    this.isStreaming = true;

    if (this.conversationId === null || this.conversationId === '') {
        return this.sendFirstMessage();
    }

    return this.sendSubsequentMessage();
},
```

(Move the existing send body into a new `sendSubsequentMessage()` method — keeping all current behavior including streaming, mention persistence, etc.)

Add the new method:

```javascript
async sendFirstMessage() {
    const document = this.editor?.getDocument?.() ?? this.documentFromInput();

    try {
        const res = await fetch(@js(route('chat.conversations.create')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                document,
                model: this.selectedModel !== 'auto' ? this.selectedModel : undefined,
            }),
        });

        if (!res.ok) {
            this.isStreaming = false;
            if (res.status === 422) {
                const body = await res.json().catch(() => ({}));
                this.error = body?.errors?.document?.[0] ?? 'Message is empty.';
            } else if (res.status === 402) {
                this.handlePaywall(await res.json().catch(() => ({})));
            } else {
                this.error = 'Could not send. Try again.';
            }
            return;
        }

        const { conversation_id } = await res.json();
        this.conversationId = conversation_id;

        const newPath = window.location.pathname.replace(/\/?$/, '/' + conversation_id);
        history.replaceState({}, '', newPath);

        this.subscribeToConversation(conversation_id);

        // Eagerly push the user's message into the local list so the UI shows it
        // immediately (the assistant message will arrive via the streaming events
        // dispatched by ProcessChatMessage).
        this.messages.push({
            role: 'user',
            content: text,
            mentions: this.selectedMentions.slice(),
            created_at: new Date().toISOString(),
        });
        this.input = '';
        this.selectedMentions = [];
        if (this.editor?.clear) this.editor.clear();
    } catch (_) {
        this.isStreaming = false;
        this.error = 'Network error. Try again.';
    }
},

documentFromInput() {
    const text = this.input.trim();
    if (text === '') {
        return { type: 'doc', content: [] };
    }
    return {
        type: 'doc',
        content: [{
            type: 'paragraph',
            content: [{ type: 'text', text }],
        }],
    };
},
```

(`text` references — inside `sendFirstMessage`, capture `const text = this.input.trim()` at the top before the fetch, so the local-message push has the right value. The existing `sendSubsequentMessage` body already does this; mirror it.)

- [ ] **Step 4: Run the test**

```bash
php artisan test --compact tests/Browser/Chat/ConversationFirstSendTest.php
```

Expected: PASS.

- [ ] **Step 5: Run the conversation-page browser suite**

```bash
php artisan test --compact tests/Browser/Chat
```

Expected: green (or only pre-existing flakes).

- [ ] **Step 6: Pre-commit gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
composer test:type-coverage
```

- [ ] **Step 7: Commit**

```bash
git add packages/Chat/resources/views/livewire/chat/chat-interface.blade.php tests/Browser/Chat/ConversationFirstSendTest.php
git commit -m "refactor(chat): conversation page first send uses /chat/conversations/create + replaceState"
```

---

## Task 8: Delete `/chat/conversations/init` and the F-002 backfill block

**Files:**
- Modify: `packages/Chat/routes/chat.php` (remove `init` route, optionally rename `create` route)
- Modify: `packages/Chat/src/Http/Controllers/ChatController.php` (delete `init()` method, delete the auto-title backfill in `send()`)
- Modify or delete: `tests/Feature/Chat/AutoTitleAfterInitTest.php`
- Test: `tests/Feature/Chat/RefreshIdempotencyTest.php` (new)

**Why:** After Task 7 lands, no client calls `/chat/conversations/init` and no client relies on the auto-title backfill (because every conversation now gets its title set by `createConversation` at insert time). Delete both. Update or remove the test that targeted the legacy backfill flow.

- [ ] **Step 1: Write the new idempotency test**

Create `tests/Feature/Chat/RefreshIdempotencyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AgentConversation;
use Tests\Helpers\ChatDocument;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);

    AiCreditBalance::query()->create([
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

it('the title set at conversation creation time persists across re-fetches', function (): void {
    Queue::fake();

    $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Show me my recent companies please'),
    ])->assertOk();

    $conversation = AgentConversation::query()->latest('created_at')->first();
    expect($conversation->title)->toBe('Show me my recent companies please');

    // Simulate a second navigation by re-fetching the conversation
    $refetched = AgentConversation::query()->find($conversation->id);
    expect($refetched->title)->toBe('Show me my recent companies please');
});
```

- [ ] **Step 2: Run the test to verify it passes**

```bash
php artisan test --compact tests/Feature/Chat/RefreshIdempotencyTest.php
```

Expected: PASS — `createConversation` already sets the title at insert time per Task 5.

- [ ] **Step 3: Delete the legacy `init` route**

In `packages/Chat/routes/chat.php`, remove the line registering `chat.conversations.init`:

```php
Route::post('/chat/conversations', [ChatController::class, 'init'])->name('chat.conversations.init');
```

Also rename `chat.conversations.create` to use the cleaner path now that `init` is gone:

Replace:
```php
Route::post('/chat/conversations/create', [ChatController::class, 'createConversation'])
    ->name('chat.conversations.create');
```

With:
```php
Route::post('/chat/conversations', [ChatController::class, 'createConversation'])
    ->name('chat.conversations.create');
```

(Keep the route name `chat.conversations.create` so existing client code still resolves; only the URL path changes.)

- [ ] **Step 4: Delete the `init()` controller method**

In `packages/Chat/src/Http/Controllers/ChatController.php`, remove the entire `public function init(...)` method (currently around line 147).

- [ ] **Step 5: Delete the F-002 auto-title backfill block in `send()`**

In `packages/Chat/src/Http/Controllers/ChatController.php`'s `send()` method, locate the block added in commit `53ae1e3` (search for `where('title', '')` or `'title' => $title`). The current `else` branch reads:

```php
} else {
    $title = TitleSanitizer::clean($validated['message']);

    DB::table('agent_conversations')->insertOrIgnore([
        'id' => $conversation,
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversations')
        ->where('id', $conversation)
        ->where('user_id', (string) $user->getKey())
        ->where('title', '')
        ->update(['title' => $title, 'updated_at' => now()]);
}
```

Replace with:

```php
} else {
    // Conversation must already exist (created by /chat/conversations).
    // Defensive existence check to avoid silent message loss; reject otherwise.
    abort_unless(
        DB::table('agent_conversations')
            ->where('id', $conversation)
            ->where('user_id', (string) $user->getKey())
            ->exists(),
        404
    );
}
```

(After Task 5+7 land, every conversation referenced by `send` exists at the time of the call; the abort guards against malformed clients.)

After this change, `send` should no longer reference `$validated['message']` (Task 3 already replaced it with `$parsed['text']`). Verify by searching the method body:

```bash
grep -n "validated..message.\|validated..mentions" packages/Chat/src/Http/Controllers/ChatController.php
```

Expected: no matches. If any survive, replace with `$parsed['text']` / `$parsed['mentions']`.

Also: `$conversation === null` branch can no longer happen because Task 3's validator requires `conversation_id`. Replace the entire `if ($conversation === null) { ... } else { ... }` block with just the body of the else branch — or convert to a guard:

```php
abort_if($conversation === null, 422, 'conversation_id is required.');
```

This pairs with the validator update from Task 3 (which already requires `conversation_id`).

- [ ] **Step 6: Update `AutoTitleAfterInitTest`**

The test in `tests/Feature/Chat/AutoTitleAfterInitTest.php` references `route('chat.conversations.init')` which no longer exists.

Option A: rewrite to test that `chat.conversations.create` sets the title:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AiCreditBalance;
use Tests\Helpers\ChatDocument;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);

    AiCreditBalance::query()->create([
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

it('sets the conversation title from the first message at creation time', function (): void {
    Queue::fake();

    $response = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Show me my recent companies please'),
    ])->assertOk();

    $conversationId = $response->json('conversation_id');

    expect(AgentConversation::query()->find($conversationId)?->title)
        ->toBe('Show me my recent companies please');
});
```

Option B: delete the file entirely if `CreateConversationTest` already covers the title-at-creation case (it does — see Task 5's first test). Either is fine. Prefer deletion to remove duplicate coverage.

To delete:

```bash
rm tests/Feature/Chat/AutoTitleAfterInitTest.php
```

- [ ] **Step 7: Update other tests that referenced `chat.conversations.init`**

```bash
grep -rln "chat.conversations.init" tests/ 2>&1
```

For each match, replace with `chat.conversations.create` and update the request body from `{conversation_id: ...}` to `{document: ...}`. If a test no longer makes sense after the migration (e.g., it asserted a behavior that only existed during the two-step init+send flow), delete it.

- [ ] **Step 8: Run the full chat suite**

```bash
php artisan test --compact tests/Feature/Chat tests/Unit/Chat tests/Browser/Chat
```

Expected: green minus pre-existing baseline flakes.

- [ ] **Step 9: Pre-commit gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
composer test:type-coverage
```

- [ ] **Step 10: Commit**

```bash
git add packages/Chat/routes/chat.php packages/Chat/src/Http/Controllers/ChatController.php tests/Feature/Chat/RefreshIdempotencyTest.php
# include the deleted test in staging
git rm -f tests/Feature/Chat/AutoTitleAfterInitTest.php 2>/dev/null || true
# include any other modified tests
git add tests/
git commit -m "refactor(chat): delete /chat/conversations/init and F-002 auto-title backfill"
```

---

## Task 9: Add TipTap npm packages and `chatEditor` Alpine factory

**Files:**
- Modify: `package.json`
- Modify: `packages/Chat/resources/js/chat.js` (top-level entry, register Alpine.data)
- Create: `packages/Chat/resources/js/chat-editor.js`
- Create: `packages/Chat/resources/js/chat-mention-suggestion.js`

**Why:** Bring in `@tiptap/core`, `@tiptap/extension-mention`, `@tiptap/suggestion` and Prosemirror peers. Wire a reusable `Alpine.data('chatEditor', ...)` factory that views can compose. Hits the existing `/chat/mentions` endpoint for suggestions.

No view yet uses the factory in this task; the swap happens in Tasks 10 and 11.

- [ ] **Step 1: Install the npm packages**

```bash
cd /Users/manuk/Herd/relaticle
npm install --save \
  @tiptap/core@^3.0.0 \
  @tiptap/pm@^3.0.0 \
  @tiptap/extension-document@^3.0.0 \
  @tiptap/extension-paragraph@^3.0.0 \
  @tiptap/extension-text@^3.0.0 \
  @tiptap/extension-placeholder@^3.0.0 \
  @tiptap/extension-hard-break@^3.0.0 \
  @tiptap/extension-mention@^3.0.0 \
  @tiptap/suggestion@^3.0.0
```

(If `^3.0.0` resolves to a version whose API has shifted, pin to the version Filament ships — verify by `cat vendor/filament/forms/composer.json | grep tiptap`. The Filament-compatible version is the safest choice because they share the bundle conceptually.)

Expected: packages added to `package.json` `dependencies` and `package-lock.json` updated. No build run yet.

- [ ] **Step 2: Create the suggestion bridge**

Create `packages/Chat/resources/js/chat-mention-suggestion.js`:

```javascript
// Suggestion plugin configuration for the @tiptap/extension-mention extension.
// Wires TipTap's suggestion popup to our existing /chat/mentions endpoint and
// renders the existing dropdown markup so styling is consistent across surfaces.

const SUGGESTION_DEBOUNCE_MS = 150;
const MIN_QUERY_LENGTH = 2;

export function createMentionSuggestion({ getCsrfToken }) {
    let abortController = null;
    let popupEl = null;
    let activeIndex = 0;
    let items = [];
    let onSelect = null;

    function renderPopup({ query, fetching, error, results, activeIdx, onPick }) {
        if (!popupEl) {
            popupEl = document.createElement('div');
            popupEl.setAttribute('role', 'listbox');
            popupEl.setAttribute('aria-label', 'Mention suggestions');
            popupEl.className = 'absolute z-50 mb-2 max-h-64 overflow-auto rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800';
            popupEl.style.minWidth = '14rem';
        }

        let html = '';
        if (fetching && results.length === 0) {
            html = `<div class="px-3 py-3 text-center text-xs text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-2">
                    <span class="h-2 w-2 animate-pulse rounded-full bg-primary-500"></span>
                    Searching…
                </span>
            </div>`;
        } else if (error) {
            html = `<div class="px-3 py-3 text-center text-xs text-red-600 dark:text-red-400" role="alert">
                Couldn't load suggestions.
            </div>`;
        } else if (results.length === 0) {
            html = `<div class="px-3 py-3 text-center text-xs text-gray-500 dark:text-gray-400">
                No matches for "${escapeHtml(query)}".
            </div>`;
        } else {
            html = results.map((item, idx) => {
                const active = idx === activeIdx ? 'bg-primary-50 dark:bg-primary-900/30' : '';
                return `<button type="button" role="option" data-idx="${idx}"
                    class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700 ${active}">
                    <span class="truncate">${escapeHtml(item.label)}</span>
                    <span class="text-xs uppercase text-gray-400">${escapeHtml(typeLabel(item.type))}</span>
                </button>`;
            }).join('');
        }

        popupEl.innerHTML = html;

        popupEl.querySelectorAll('button[role="option"]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const idx = Number(btn.getAttribute('data-idx'));
                onPick?.(results[idx]);
            });
        });

        return popupEl;
    }

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function typeLabel(t) {
        return ({ company: 'Company', people: 'Person', opportunity: 'Deal', task: 'Task', note: 'Note' })[t] || t;
    }

    async function fetchResults(query) {
        if (abortController) abortController.abort();
        abortController = new AbortController();

        try {
            const res = await fetch('/chat/mentions?q=' + encodeURIComponent(query), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: abortController.signal,
                credentials: 'same-origin',
            });
            if (!res.ok) return { results: [], error: true };
            const body = await res.json();
            return {
                results: (body.data || []).map((item) => ({
                    type: item.type,
                    id: item.id,
                    label: item.name,
                })),
                error: false,
            };
        } catch (e) {
            if (e.name === 'AbortError') return null;
            return { results: [], error: true };
        }
    }

    let debounceTimer = null;
    function debouncedFetch(query, render) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(async () => {
            const result = await fetchResults(query);
            if (result === null) return;
            render(result);
        }, SUGGESTION_DEBOUNCE_MS);
    }

    return {
        char: '@',
        allowSpaces: true,
        items: () => items,
        render: () => {
            let editor;
            let clientRect;

            return {
                onStart: (props) => {
                    editor = props.editor;
                    clientRect = props.clientRect;

                    if (!props.query || props.query.length < MIN_QUERY_LENGTH) {
                        return;
                    }

                    activeIndex = 0;
                    items = [];
                    onSelect = (item) => props.command({ id: item.id, type: item.type, label: item.label });

                    document.body.appendChild(renderPopup({
                        query: props.query,
                        fetching: true,
                        error: false,
                        results: [],
                        activeIdx: activeIndex,
                        onPick: onSelect,
                    }));
                    positionPopup(clientRect());

                    debouncedFetch(props.query, ({ results, error }) => {
                        items = results;
                        renderPopup({ query: props.query, fetching: false, error, results, activeIdx: activeIndex, onPick: onSelect });
                    });
                },

                onUpdate: (props) => {
                    clientRect = props.clientRect;
                    onSelect = (item) => props.command({ id: item.id, type: item.type, label: item.label });

                    if (!props.query || props.query.length < MIN_QUERY_LENGTH) {
                        if (popupEl?.parentNode) popupEl.parentNode.removeChild(popupEl);
                        return;
                    }

                    if (!popupEl?.parentNode) {
                        document.body.appendChild(popupEl);
                    }
                    positionPopup(clientRect());

                    debouncedFetch(props.query, ({ results, error }) => {
                        items = results;
                        renderPopup({ query: props.query, fetching: false, error, results, activeIdx: activeIndex, onPick: onSelect });
                    });
                },

                onKeyDown: (props) => {
                    if (props.event.key === 'Escape') {
                        if (popupEl?.parentNode) popupEl.parentNode.removeChild(popupEl);
                        return true;
                    }
                    if (props.event.key === 'ArrowDown' && items.length > 0) {
                        activeIndex = (activeIndex + 1) % items.length;
                        renderPopup({ query: '', fetching: false, error: false, results: items, activeIdx: activeIndex, onPick: onSelect });
                        return true;
                    }
                    if (props.event.key === 'ArrowUp' && items.length > 0) {
                        activeIndex = (activeIndex - 1 + items.length) % items.length;
                        renderPopup({ query: '', fetching: false, error: false, results: items, activeIdx: activeIndex, onPick: onSelect });
                        return true;
                    }
                    if (props.event.key === 'Enter' && items.length > 0) {
                        onSelect?.(items[activeIndex]);
                        return true;
                    }
                    return false;
                },

                onExit: () => {
                    if (popupEl?.parentNode) popupEl.parentNode.removeChild(popupEl);
                },
            };
        },
    };

    function positionPopup(rect) {
        if (!popupEl || !rect) return;
        // Position above the cursor for chat-style placement (prefer upward).
        popupEl.style.position = 'absolute';
        popupEl.style.left = `${window.scrollX + rect.left}px`;
        popupEl.style.top = `${window.scrollY + rect.top - 280}px`; // 280 = max popup height + gap
    }
}
```

- [ ] **Step 3: Create the editor factory**

Create `packages/Chat/resources/js/chat-editor.js`:

```javascript
import { Editor } from '@tiptap/core';
import Document from '@tiptap/extension-document';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import HardBreak from '@tiptap/extension-hard-break';
import Placeholder from '@tiptap/extension-placeholder';
import Mention from '@tiptap/extension-mention';
import { createMentionSuggestion } from './chat-mention-suggestion';

export function chatEditor({ initialDocument, placeholder, onSubmit, onChange, autofocus }) {
    return {
        editorEl: null,
        editor: null,

        init() {
            this.editorEl = this.$refs.editor;

            const ChatMention = Mention.extend({
                addAttributes() {
                    return {
                        id: { default: null },
                        type: { default: null },
                        label: { default: null },
                    };
                },
                parseHTML() {
                    return [{ tag: 'span[data-mention-id]' }];
                },
                renderHTML({ node, HTMLAttributes }) {
                    return ['span', {
                        'data-mention-id': node.attrs.id,
                        'data-mention-type': node.attrs.type,
                        'class': 'inline-flex items-center rounded-md bg-primary-100 px-1.5 py-0.5 text-xs text-primary-800 dark:bg-primary-900/30 dark:text-primary-200',
                        ...HTMLAttributes,
                    }, '@' + (node.attrs.label ?? '')];
                },
            });

            this.editor = new Editor({
                element: this.editorEl,
                extensions: [
                    Document,
                    Paragraph,
                    Text,
                    HardBreak.configure({ keepMarks: false }),
                    Placeholder.configure({ placeholder: placeholder ?? 'Ask anything…' }),
                    ChatMention.configure({
                        HTMLAttributes: { class: 'mention' },
                        suggestion: createMentionSuggestion({}),
                    }),
                ],
                content: initialDocument ?? { type: 'doc', content: [] },
                editorProps: {
                    attributes: {
                        class: 'prose prose-sm max-w-none focus:outline-none min-h-[64px] px-4 pt-3 pb-2 text-sm leading-6',
                    },
                    handleKeyDown: (view, event) => {
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            onSubmit?.();
                            return true;
                        }
                        return false;
                    },
                },
                onUpdate: ({ editor }) => {
                    onChange?.({ document: editor.getJSON(), text: editor.getText() });
                },
            });

            if (autofocus) {
                this.$nextTick(() => this.editor?.commands.focus('end'));
            }
        },

        destroy() {
            this.editor?.destroy();
        },

        getDocument() {
            return this.editor?.getJSON() ?? { type: 'doc', content: [] };
        },

        getText() {
            return (this.editor?.getText() ?? '').trim();
        },

        setText(text) {
            this.editor?.commands.setContent({
                type: 'doc',
                content: text === '' ? [] : [{
                    type: 'paragraph',
                    content: [{ type: 'text', text }],
                }],
            });
        },

        clear() {
            this.editor?.commands.clearContent();
        },

        focus() {
            this.editor?.commands.focus('end');
        },

        isEmpty() {
            return this.editor?.isEmpty ?? true;
        },
    };
}
```

- [ ] **Step 4: Register the factory in the chat entry point**

In `packages/Chat/resources/js/chat.js` (or whichever JS file is the chat package's entry), add:

```javascript
import { chatEditor } from './chat-editor';

document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('chatEditor', chatEditor);
    }
});
```

- [ ] **Step 5: Build assets**

```bash
npm run build 2>&1 | tail -10
```

Expected: vite/rolldown reports `chat-*.js` chunks built. Bundle grows by ~50–60KB gzipped (TipTap + Prosemirror peers).

- [ ] **Step 6: Sanity-check the bundle loads**

```bash
agent-browser open https://app.relaticle.test/manuk-minasyans-team-trowx
agent-browser eval "typeof window.Alpine.data('chatEditor', () => {})"
```

Expected: no console errors. The factory isn't yet used by any view, so no visual difference.

- [ ] **Step 7: Pre-commit gate**

```bash
vendor/bin/pint --dirty --format agent
```

(No PHP changed, but `pint` is a no-op cost.)

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json packages/Chat/resources/js/chat-editor.js packages/Chat/resources/js/chat-mention-suggestion.js packages/Chat/resources/js/chat.js
git commit -m "feat(chat): add TipTap dependencies and chatEditor Alpine factory"
```

---

## Task 10: Swap conversation page to TipTap, delete `MentionRenderer.php`

**Files:**
- Modify: `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php`
- Delete: `packages/Chat/src/Support/MentionRenderer.php`
- Delete: any tests targeting `MentionRenderer` (search and remove)
- Test: `tests/Browser/Chat/MentionDeletionTest.php` (new)

**Why:** Replace the `<textarea>` + mention dropdown markup + `selectedMentions` pill row with the new `chatEditor` factory. Replace the `renderMentions(content, mentions)` JS helper with `renderMessageContent(message)` which uses TipTap's JS `generateHTML()`. Delete `MentionRenderer.php` since the renderer no longer falls back to it (every message has a `document` after Task 1's wipe-and-NOT-NULL).

This is the heaviest task. The view file is 1600+ lines; the changes are concentrated in the input area (around lines 354–530) and the message-rendering area (around line 60).

- [ ] **Step 1: Write the failing browser test for atomic mention deletion**

Create `tests/Browser/Chat/MentionDeletionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Relaticle\Chat\Models\AiCreditBalance;

it('deletes the entire mention chip with a single backspace', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Company::factory()->for($team)->create(['name' => 'Acme Corp']);

    AiCreditBalance::query()->create([
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->navigate("/app/{$team->slug}/chats")
        ->click('[data-chat-context="conversation"] [contenteditable="true"]')
        ->type('[data-chat-context="conversation"] [contenteditable="true"]', 'Tell me about @Acme')
        ->wait(500); // suggestion debounce + render

    // Click the suggestion item
    $this->click('[role="listbox"] [role="option"]:first-child')
        ->wait(200);

    // Verify the chip is rendered as a span with data-mention-id
    $hasChip = $this->page()->evaluate(
        'document.querySelector("[data-chat-context=conversation] [contenteditable] span[data-mention-id]") !== null'
    );
    expect($hasChip)->toBeTrue();

    // Press backspace once
    $this->keys('[data-chat-context="conversation"] [contenteditable="true"]', 'Backspace')
        ->wait(100);

    // Chip should be gone after one keystroke
    $stillHasChip = $this->page()->evaluate(
        'document.querySelector("[data-chat-context=conversation] [contenteditable] span[data-mention-id]") !== null'
    );
    expect($stillHasChip)->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --compact tests/Browser/Chat/MentionDeletionTest.php
```

Expected: FAIL — current input is a `<textarea>`, no `[contenteditable="true"]` element.

- [ ] **Step 3: Replace the input area markup**

In `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php`, locate the input area (search for `<form x-on:submit.prevent="sendMessage()">`). Replace the existing `<form>` block — including the textarea, mention dropdown, mention pills, and submit button row — with:

```blade
<form x-on:submit.prevent="sendMessage()">
    <div
        x-data="chatEditor({
            initialDocument: { type: 'doc', content: [] },
            placeholder: 'Ask anything...',
            autofocus: !{{ $conversationId ? 'true' : 'false' }} || true,
            onSubmit: () => $root.dispatchEvent(new CustomEvent('chat:editor-submit', { bubbles: true })),
            onChange: ({ document, text }) => {
                $root.dispatchEvent(new CustomEvent('chat:editor-change', { bubbles: true, detail: { document, text } }));
            },
        })"
        x-on:chat:editor-submit.window="sendMessage()"
        x-on:chat:editor-change.window="input = $event.detail.text"
        x-init="$nextTick(() => { window.__chatEditor = $data; })"
        class="relative rounded-2xl border border-gray-200 bg-white transition focus-within:border-primary-500 dark:border-gray-700 dark:bg-gray-800"
    >
        <div x-ref="editor" class="relative"></div>

        {{-- Controls row --}}
        <div class="flex items-center justify-between gap-2 px-3 pb-2">
            <span
                x-show="getText().length > 4000"
                x-cloak
                x-text="`${getText().length.toLocaleString()} / 5,000`"
                :class="{
                    'text-gray-500 dark:text-gray-400': getText().length <= 4900,
                    'text-amber-600 dark:text-amber-400': getText().length > 4900 && getText().length <= 5000,
                    'text-red-600 dark:text-red-400': getText().length > 5000,
                }"
                class="text-[11px]"
                aria-live="polite"
            ></span>
            <div x-show="getText().length <= 4000" class="flex-1"></div>

            <div class="flex items-center gap-2">
                {{-- Model picker (existing markup, unchanged from current state — see chat-interface.blade.php) --}}
                @include('chat::livewire.chat.partials._model-picker')

                <button
                    x-show="!isStreaming"
                    type="submit"
                    class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-600 text-white transition hover:bg-primary-700 disabled:bg-primary-200 disabled:text-white dark:disabled:bg-primary-900/40 dark:disabled:text-primary-300"
                    :disabled="isEmpty() || getText().length > 5000"
                    aria-label="Send message"
                >
                    <x-heroicon-s-arrow-up class="h-4 w-4" />
                </button>
                <button
                    x-show="isStreaming"
                    type="button"
                    x-on:click="cancelStream()"
                    class="flex h-7 w-7 items-center justify-center rounded-lg bg-gray-900 text-white transition hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-900 dark:hover:bg-gray-300"
                    aria-label="Stop generation"
                >
                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <rect x="6" y="6" width="12" height="12" rx="2"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</form>
```

(Extract the existing model-picker block into a partial `packages/Chat/resources/views/livewire/chat/partials/_model-picker.blade.php` to keep the new view tidy. The partial is the entire `<div class="relative">` for the picker — copy-paste from the current chat-interface.blade.php.)

Create `packages/Chat/resources/views/livewire/chat/partials/_model-picker.blade.php` with the existing model-picker markup. Reference: it's the block starting around line 459 (the `x-data="{ menuOpen: false }"` div).

- [ ] **Step 4: Update `chatInterface()` factory to remove mention state**

In the same file, locate the `Alpine.data('chatInterface', ...)` factory (around line 538). Remove these properties from the data block:

- `mention: { ... }`
- `selectedMentions: []`

Remove these methods (no longer needed since the editor owns this state):

- `mentionTypeLabel(type)` — kept on the editor side
- `onTextareaInput(event)` — replaced by the editor's onUpdate
- `detectMentionTrigger(textarea)` — replaced by suggestion plugin
- `fetchMentions(query)` — moved to chat-mention-suggestion.js
- `selectMention(item)` — moved to suggestion plugin
- `closeMention()` — moved to suggestion plugin
- `removeMention(mention)` — no pill row, no removal needed
- `mentionMoveActive(delta)` — moved to suggestion plugin

Replace `sendSubsequentMessage` (the existing `sendMessage` body that sends to `/chat/send`) so it uses the editor's document:

```javascript
async sendSubsequentMessage() {
    const editor = window.__chatEditor;
    if (!editor) return;

    const document = editor.getDocument();
    const text = editor.getText();

    if (!text || text.length > 5000) {
        this.isStreaming = false;
        return;
    }

    // Add the user message to the local list optimistically
    this.messages.push({
        role: 'user',
        content: text,
        document,
        created_at: new Date().toISOString(),
    });

    editor.clear();
    this.input = '';

    try {
        const res = await fetch(@js(route('chat.send')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                conversation_id: this.conversationId,
                document,
                model: this.selectedModel !== 'auto' ? this.selectedModel : undefined,
            }),
        });

        if (!res.ok) {
            this.isStreaming = false;
            this.handleSendError(res);
            return;
        }
    } catch (_) {
        this.isStreaming = false;
        this.error = 'Network error.';
    }
},
```

- [ ] **Step 5: Replace `renderMentions` with `renderMessageContent`**

In the same file, locate `renderMentions(content, mentions)` (around line 631) and replace it with:

```javascript
renderMessageContent(message) {
    if (!message.document) {
        // Defensive fallback: a stale row from before the migration.
        // Returns plain text (escaped) so we never crash the render.
        return this.escapeHtml(message.content ?? '');
    }
    return this.tipTapRenderHTML(message.document);
},

tipTapRenderHTML(document) {
    // Walk the document tree producing the same chip markup the editor renders.
    // Imported lazily to avoid a circular dep on the editor module.
    return this.walkDocumentToHtml(document);
},

walkDocumentToHtml(node) {
    if (!node) return '';
    if (node.type === 'doc' || node.type === 'paragraph') {
        const children = (node.content ?? []).map((c) => this.walkDocumentToHtml(c)).join('');
        return node.type === 'paragraph' ? `<p>${children}</p>` : children;
    }
    if (node.type === 'text') {
        return this.escapeHtml(node.text ?? '');
    }
    if (node.type === 'mention') {
        const id = this.escapeAttr(node.attrs?.id ?? '');
        const type = this.escapeAttr(node.attrs?.type ?? '');
        const label = this.escapeHtml(node.attrs?.label ?? '');
        return `<span data-mention-id="${id}" data-mention-type="${type}" class="inline-flex items-center rounded-md bg-primary-100 px-1.5 py-0.5 text-xs text-primary-800 dark:bg-primary-900/30 dark:text-primary-200">@${label}</span>`;
    }
    if (node.type === 'hardBreak') {
        return '<br>';
    }
    return '';
},
```

(The existing `escapeHtml` and `escapeAttr` helpers stay. Leave them.)

In the message-rendering template (around line 60), replace `<span x-html="renderMentions(msg.content, msg.mentions)"...>` with `<span x-html="renderMessageContent(msg)"...>`. Drop the `<template x-if="msg.mentions && msg.mentions.length > 0">` / fallback branches — the new helper handles both cases.

- [ ] **Step 6: Delete `MentionRenderer.php`**

```bash
git rm packages/Chat/src/Support/MentionRenderer.php
```

Search for callers:

```bash
grep -rn "MentionRenderer\|Support.MentionRenderer" --include="*.php" packages/Chat tests app 2>&1
```

Expected: only the deleted file references. If any callers remain (e.g., tests asserting against `MentionRenderer::render()` output), delete or rewrite them — they're part of the legacy fallback that no longer exists.

- [ ] **Step 7: Build assets**

```bash
npm run build 2>&1 | tail -3
```

- [ ] **Step 8: Run the new browser test**

```bash
php artisan test --compact tests/Browser/Chat/MentionDeletionTest.php
```

Expected: PASS.

- [ ] **Step 9: Run the full chat browser suite**

```bash
php artisan test --compact tests/Browser/Chat
```

Expected: green minus pre-existing flakes. Update any test that referenced the textarea (`textarea[placeholder="Ask anything..."]`) — switch to `[contenteditable="true"]`.

- [ ] **Step 10: Manual verification with agent-browser**

```bash
agent-browser open https://app.relaticle.test/manuk-minasyans-team-trowx/chats
# (developer login if needed, click into the contenteditable editor)
agent-browser type '[contenteditable="true"]' 'Hi @Air'
sleep 1
agent-browser screenshot /Users/manuk/Herd/relaticle/.context/testing/screenshots/tiptap-mention.png
```

Expected: suggestion popup appears with Airbnb (or whatever is in the team's data); selecting it produces an inline chip; pressing Backspace removes the chip in one keystroke.

- [ ] **Step 11: Pre-commit gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
composer test:type-coverage
```

- [ ] **Step 12: Commit**

```bash
git add packages/Chat/resources/views/livewire/chat/chat-interface.blade.php packages/Chat/resources/views/livewire/chat/partials/_model-picker.blade.php tests/Browser/Chat/MentionDeletionTest.php
# the deleted MentionRenderer.php is already staged via `git rm`
git commit -m "refactor(chat): swap conversation page to TipTap, delete MentionRenderer"
```

---

## Task 11: Swap dashboard input to TipTap

**Files:**
- Modify: `packages/Chat/resources/views/filament/pages/dashboard.blade.php`

**Why:** Same swap as Task 10 but on the dashboard. The factory-driven `chatEditor` Alpine component replaces the existing `<textarea>` + mention pills + dropdown markup. The dashboard's existing `dashboardChatInput()` factory shrinks down to "model picker + submit dispatcher" — all mention state moves to `chatEditor`.

- [ ] **Step 1: Replace the dashboard input markup**

In `packages/Chat/resources/views/filament/pages/dashboard.blade.php`, locate the form (search for `<form @submit.prevent="submit()">`). Replace its `<div>` block (the rounded shell, textarea, mention pills, mention dropdown, controls row) with:

```blade
<form @submit.prevent="submit()" class="mt-10">
    <div
        x-data="chatEditor({
            initialDocument: { type: 'doc', content: [] },
            placeholder: 'Ask anything...',
            autofocus: true,
            onSubmit: () => $root.dispatchEvent(new CustomEvent('dashboard:editor-submit', { bubbles: true })),
            onChange: ({ document, text }) => {
                $root.dispatchEvent(new CustomEvent('dashboard:editor-change', { bubbles: true, detail: { document, text } }));
            },
        })"
        x-on:dashboard:editor-submit.window="submit()"
        x-on:dashboard:editor-change.window="input = $event.detail.text"
        x-init="$nextTick(() => { window.__dashboardEditor = $data; })"
        class="relative rounded-2xl border border-gray-200 bg-white transition focus-within:border-primary-500 dark:border-gray-700 dark:bg-gray-800"
    >
        <div x-ref="editor" class="relative"></div>

        <div class="flex items-center justify-end gap-2 px-3 pb-2">
            {{-- Reuse the same model-picker partial Task 10 extracted --}}
            @include('chat::livewire.chat.partials._model-picker')

            <button
                type="submit"
                class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-600 text-white transition hover:bg-primary-700 disabled:bg-primary-200 disabled:text-white dark:disabled:bg-primary-900/40 dark:disabled:text-primary-300"
                :disabled="isEmpty() || submitting"
                aria-label="Send message"
            >
                <x-heroicon-s-arrow-up class="h-4 w-4" />
            </button>
        </div>
    </div>
</form>
```

- [ ] **Step 2: Slim down the `dashboardChatInput()` factory**

In the `@script` block at the bottom of `dashboard.blade.php`, the `Alpine.data('dashboardChatInput', ...)` factory currently owns the full mention state. Strip it down to:

```javascript
Alpine.data('dashboardChatInput', (chatCreateUrl, defaultModel) => ({
    submitting: false,
    error: null,
    selectedModel: defaultModel || 'auto',
    menuOpen: false,
    input: '',

    modelOptions: [
        { value: 'auto', label: 'Auto', provider: null },
        { value: 'claude-sonnet', label: 'Sonnet 4.6', provider: 'anthropic' },
        { value: 'claude-opus', label: 'Opus 4.7', provider: 'anthropic' },
        { value: 'gpt-5-5', label: 'GPT 5.5', provider: 'openai' },
        { value: 'gpt-5-4', label: 'GPT 5.4', provider: 'openai' },
        { value: 'gemini-3-flash', label: 'Gemini 3 Flash', provider: 'gemini' },
        { value: 'gemini-3-1-pro', label: 'Gemini 3.1 Pro', provider: 'gemini' },
    ],

    providerIcons: @js([
        'anthropic' => svg('ri-claude-fill')->toHtml(),
        'openai' => svg('ri-openai-fill')->toHtml(),
        'gemini' => svg('ri-gemini-fill')->toHtml(),
    ]),

    providerIconHtml(provider) { return provider ? (this.providerIcons[provider] || '') : ''; },
    providerIconColor(provider) {
        return ({
            anthropic: 'text-[#D4763C]',
            openai: 'text-gray-900 dark:text-gray-200',
            gemini: 'text-blue-500',
        })[provider] || '';
    },
    modelLabel(value) {
        const found = this.modelOptions.find((o) => o.value === value);
        return (found || this.modelOptions[0]).label;
    },
    modelProvider(value) {
        const found = this.modelOptions.find((o) => o.value === value);
        return found?.provider ?? null;
    },

    async submit() {
        const editor = window.__dashboardEditor;
        if (!editor || editor.isEmpty() || this.submitting) return;
        this.submitting = true;
        this.error = null;

        try {
            const res = await fetch(chatCreateUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({
                    document: editor.getDocument(),
                    model: this.selectedModel !== 'auto' ? this.selectedModel : undefined,
                }),
            });

            if (!res.ok) {
                this.submitting = false;
                if (res.status === 422) {
                    const body = await res.json().catch(() => ({}));
                    this.error = body?.errors?.document?.[0] ?? 'Message is empty.';
                } else if (res.status === 402) {
                    window.location.href = @js(url('/app/billing'));
                } else {
                    this.error = 'Could not send. Try again.';
                }
                return;
            }

            const { conversation_id } = await res.json();
            window.location.href = @js(url('/app')) + window.location.pathname.split('/')[2] + '/chats/' + conversation_id;
        } catch (_) {
            this.submitting = false;
            this.error = 'Network error. Try again.';
        }
    },
}));
```

(The path-construction at the redirect uses the team-slug from the current URL. If your route helper exposes `\App\Filament\Pages\ChatConversation::getUrl()` reliably with the tenant context, prefer that — it returns `/app/{slug}/chats` and we append `/{id}`. The exact form is verified at write time.)

In the page-level `x-data` invocation, change from `dashboardChatInput(@js(...), @js(...))` to pass the new endpoint URL:

```blade
x-data="dashboardChatInput(@js(route('chat.conversations.create')), @js(auth()->user()?->ai_preferences['default_model'] ?? 'auto'))"
```

- [ ] **Step 3: Build assets**

```bash
npm run build 2>&1 | tail -3
```

- [ ] **Step 4: Re-run the dashboard browser test from Task 6**

```bash
php artisan test --compact tests/Browser/Chat/DashboardFirstSendTest.php
```

Expected: PASS — the test only checks the post-send URL pattern, which is unchanged.

- [ ] **Step 5: Add a focused dashboard mention browser test**

Append to `tests/Browser/Chat/DashboardFirstSendTest.php`:

```php
it('renders an atomic mention chip on the dashboard input', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    \App\Models\Company::factory()->for($team)->create(['name' => 'Acme']);

    AiCreditBalance::query()->create([
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->navigate("/app/{$team->slug}")
        ->click('[contenteditable="true"]')
        ->type('[contenteditable="true"]', '@Acme')
        ->wait(500)
        ->click('[role="listbox"] [role="option"]:first-child')
        ->wait(200);

    $hasChip = $this->page()->evaluate(
        'document.querySelector("[contenteditable] span[data-mention-id]") !== null'
    );
    expect($hasChip)->toBeTrue();
});
```

- [ ] **Step 6: Run all dashboard tests**

```bash
php artisan test --compact tests/Browser/Chat/DashboardFirstSendTest.php
```

Expected: 2 passing.

- [ ] **Step 7: Manual verification**

```bash
agent-browser open https://app.relaticle.test/manuk-minasyans-team-trowx
agent-browser screenshot /Users/manuk/Herd/relaticle/.context/testing/screenshots/dashboard-tiptap.png
```

Expected: dashboard input is the TipTap editor with placeholder "Ask anything…"; mention typing produces atomic chips; sending navigates to `/chats/{id}`.

- [ ] **Step 8: Pre-commit gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
composer test:type-coverage
```

- [ ] **Step 9: Commit**

```bash
git add packages/Chat/resources/views/filament/pages/dashboard.blade.php tests/Browser/Chat/DashboardFirstSendTest.php
git commit -m "refactor(chat): swap dashboard to TipTap input"
```

---

## Wrap-up

- [ ] **Step W1: Run the full chat suite**

```bash
php artisan test --compact tests/Unit/Chat tests/Feature/Chat tests/Browser/Chat
```

Expected: green minus the documented baseline flakes. Compare passing count to the wrap-up snapshot from Step 0d — should be (baseline + ~12 new tests).

- [ ] **Step W2: Run the project gate scripts**

```bash
vendor/bin/pint --dirty --test --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --no-progress --memory-limit=2G
composer test:type-coverage
```

All must pass; type coverage ≥ 99.9%.

- [ ] **Step W3: Smoke-test the live app end-to-end**

```bash
agent-browser open https://app.relaticle.test/manuk-minasyans-team-trowx
# Login via Developer Login if needed
# 1. Type a mention into the dashboard editor, send
# 2. Verify URL becomes /chats/{ulid}
# 3. Verify the user message renders with atomic chip
# 4. Wait for assistant response, verify it streams in
# 5. Refresh the page → both messages persist, no re-fire
# 6. Click browser back → dashboard renders empty
# 7. Click forward → conversation as it was
```

Each numbered check should pass without console errors.

- [ ] **Step W4: Update PR description (if needed)**

Append to PR #209's description:

```markdown
## TipTap mentions + server-owned conversations (2026-05-07)

Implements specs:
- `docs/superpowers/specs/2026-05-07-tiptap-mention-editor-and-storage-design.md`
- `docs/superpowers/specs/2026-05-07-server-owned-first-message-handoff-design.md`

11 commits sequenced server-first. Migration step (commit 1) wipes alpha
chat data so document jsonb can be NOT NULL from day one.
```

- [ ] **Step W5: Push and confirm CI**

```bash
git push
gh run list --branch feat/dashboard-chat -L 1
```

If CI is still running, surface the URL.

---

## Self-review notes

**Spec coverage check:**
- TipTap-input + atomic mentions: Tasks 9, 10, 11
- `document jsonb NOT NULL` column: Task 1
- Alpha data wipe: Task 1
- TipTapDocumentParser (parse, buildFromText): Task 2
- `/chat/send` accepts document: Task 3
- User message persists document: Task 3 (via ProcessChatMessage)
- Assistant message materializes document at stream end: Task 4
- POST /chat/conversations endpoint: Task 5
- Dashboard fetches + redirects: Task 6
- Conversation page first-send + replaceState: Task 7
- Delete /chat/conversations/init + F-002 backfill: Task 8
- chatEditor Alpine factory: Task 9
- Conversation page swap + delete MentionRenderer: Task 10
- Dashboard input swap: Task 11
- Test additions: TipTapDocumentParserTest, ChatSendDocumentTest, ProcessChatMessageDocumentMaterializationTest, CreateConversationTest, DashboardFirstSendTest, ConversationFirstSendTest, RefreshIdempotencyTest, MentionDeletionTest, MessageDocumentColumnTest

Every spec section has a task. Every "Out of scope" item from the specs is genuinely deferred (no incidental drift).

**Type/method consistency check:**
- `TipTapDocumentParser::parse(array $document, Team $team): array{text, mentions}` — used identically in Tasks 3, 5
- `TipTapDocumentParser::buildFromText(string $text, array $mentionRows, Team $team): array` — used in Task 4
- `ProcessChatMessage` constructor adds `array $document` — Tasks 3, 5 dispatch with it; Task 3 persists it via `persistUserDocument`; Task 4 builds the assistant doc using the parser
- `chatEditor` factory exposes `getDocument()`, `getText()`, `setText()`, `clear()`, `focus()`, `isEmpty()` — used by Tasks 10, 11
- Route name `chat.conversations.create` is stable from Task 5 onward (path renames in Task 8 but name stays)

**Placeholder check:**
- No "TBD", "TODO", "implement later"
- No "similar to Task N" — every code block is self-contained
- A few "verified at write time" notes appear (e.g., the exact `waitForLocation` API name in Pest browser plugin, the exact existing constructor argument list of `ChatController` and `ProcessChatMessage`). These are inevitable for a plan that depends on existing code state; they are flagged where they appear so the implementer reads + adjusts rather than copy-pastes blindly.
