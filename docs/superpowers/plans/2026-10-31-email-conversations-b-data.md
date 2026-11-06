# Email Conversations, Part B: conversation data and automated mail

> **For agentic workers:** REQUIRED SUB-SKILL: use the `sdd-lean` skill to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every stored email carries a `conversation_key` that joins copies and replies across
the mailboxes of one workspace, its `References` header, and an `automated_reason`. A text-free
`email_conversations` row summarises each conversation. Commands queued from migrations
backfill existing mail.

**Architecture:** Both providers fill `FetchedEmailData::$references`, and Graph now fills
`inReplyTo`. `ConversationKeyResolver` picks the key in `StoreEmailAction` and `SendEmailAction`.
`RefreshConversationSummary` rebuilds one summary row after each write. `MergeConversations`
joins two keys when a late parent links them. PR 4 adds `AutomatedMailDetector`, which stores
the reason at sync and honours `automated_sender_overrides`.

**Spec:** `docs/superpowers/specs/2026-10-31-email-conversations-design.md`, PR 2 and PR 4.
Reasoning: D5 and D8 in `docs/email/decisions/a-page-and-list.md`, D32 in
`docs/email/decisions/e-speed-ai-and-later.md`. The master plan
`docs/superpowers/plans/2026-10-31-email-conversations.md` holds the global constraints, loops,
checks and commit style; they are not repeated here.

Paths are relative to `packages/EmailIntegration/` unless they start with `app/`, `database/`,
`config/`, `lang/` or `tests/`.

## Plan notes: code reality against the spec and contracts

1. **Graph drops `In-Reply-To`.** `src/Services/MicrosoftGraphMailService.php:152` hardcodes
   `inReplyTo: null`, though line 125 already selects `internetMessageHeaders`. PR 2 reads
   `In-Reply-To` and `References` from those headers. Graph returns transport headers only for
   received mail, so a Sent Items copy has none. Outbound Microsoft copies join through the
   provider thread (rule 2) or another mailbox's copy (rule 3). `conversationIndex` holds no
   Message-IDs and is not used.
2. **No index serves a workspace-wide Message-ID lookup.** The only one is
   `(workspace_id, user_id, rfc_message_id)` in `database/migrations/2026_03_21_050430_create_emails_table.php`.
   PostgreSQL 17 cannot skip its middle column. PR 2 adds `(workspace_id, rfc_message_id)`,
   `(workspace_id, in_reply_to)`, the contract's `(workspace_id, conversation_key)` and a GIN
   index over the `references` ids, built concurrently.
3. **`references` is a reserved SQL word.** Eloquent quotes it. Raw SQL here writes `"references"`.
4. **`rfc_message_id` and `in_reply_to` are `text`**
   (`database/migrations/2026_09_29_210525_widen_provider_sourced_email_and_calendar_columns.php`).
   A btree entry fails above about 2,700 bytes, so the rehearsal measures the longest value first.
5. **The resolver contract cannot serve every caller.** The master table names only
   `resolve(FetchedEmailData, ConnectedAccount)`. App sends (`src/Actions/SendEmailAction.php:125-153`)
   and the backfill hold an `Email`. A sent message never reaches the resolver at sync, because
   `StoreEmailJob` returns early for a provider id it holds (`src/Jobs/StoreEmailJob.php:107`, `168-174`).
   PR 2 adds `resolveStored(Email): string` and `keysToMerge(Email): list<string>`, and updates
   the master contracts table in the same PR.
6. **A plain cap of 50 ids loses the root** that rule 4 hashes. `MessageIdList::references()`
   keeps the first id plus the newest 49, as RFC 5322 section 3.6.4 advises.
7. **The `imports` lane stops a job at 300 seconds, 2 tries** (`config/horizon.php:228-241`). One
   run cannot key the 156,632-email mailbox. Both commands take `--chunk=` (rows per run) and,
   with `--force`, queue themselves again on `imports` while work remains.
8. **Flag-gated commands vanish on installs with the flag off.** `registerCommands()` runs only
   after the flag check (`src/EmailIntegrationServiceProvider.php:114`, `187-194`). Both new
   commands join `RemoveOwnDomainRecordsCommand` in `registerCleanupThatIgnoresTheFeatureFlag()`
   (`src/EmailIntegrationServiceProvider.php:215-218`).
9. **No null-key shim.** The spec lists one under PR 2, but a shimmed key has no
   `email_conversations` row, so no view could list it. Part C drops it: a workspace joins the
   `EmailConversations` flag only after `email:backfill-conversations --workspace=<id>` leaves
   zero null keys.
10. **Detector signature.** The spec writes `detect(FetchedEmailData)`; the contract adds
    `string $workspaceId`. The override lookup needs it, so the contract wins.
11. **`automated_reason` ships in PR 4** in its own migration, per the contracts table. The spec's
    migration table groups it with `conversation_key`.
12. **`email_conversations.created_at`** is in the contract, not the spec. The contract wins.
13. **`provider_category` has no live producer today.** Gmail flags its four bulk categories as
    `isBulkMail` unless the mail is relayed for another sender (`src/Services/GmailService.php:162`,
    `480-502`), and `StoreEmailJob` drops all of it (`src/Jobs/StoreEmailJob.php:145-149`). The
    relayed mail it keeps is form notifications from real people, which must stay visible. So the
    detector maps `isBulkMail` to `ProviderCategory` only after the other rules. It fires once
    D32's revisit relaxes the drop. The case stays because the contract names it.
14. **Microsoft keeps more list mail than Gmail.** Graph flags bulk only on `List-Unsubscribe` or
    `Precedence: bulk|junk` (`src/Services/MicrosoftGraphMailService.php:29`, `172-181`). Mail with
    `List-Id`, `Precedence: list` or `Auto-Submitted` is stored today
    (`tests/Feature/EmailIntegration/MicrosoftGraphMailServiceTest.php:540-548`). Gmail keeps
    Primary mail with `List-Unsubscribe`. The detector marks all of these.
15. **Two role-sender lists disagree.** `AutomatedSenderMatcher` reads
    `config/email-integration.php:43-60`; `EmailClassifier::MARKETING_SENDERS`
    (`src/Services/EmailClassifier.php:41`) adds `marketing` and lacks `bounce` and `automated`.
    The detector uses the matcher, as the spec says. The history backfill adds the `Marketing`
    label and is bounded by `--before=`, so a rerun never touches new mail.
16. **The relay rule is private to `GmailService`** (`src/Services/GmailService.php:480-515`). PR 4
    moves it to `Support\RelayedSender` unchanged, so the detector can use it for both providers.
17. **No surface writes an override in PR 4.** "Not automated" sits on the row and record tabs that
    PR 5 builds, and the testing rules forbid an action test with no entry point. PR 4 ships the
    table, model and detector read. PR 5 adds `MarkSenderAsPerson::execute(User $user, string $emailAddress): void`.
18. **`Email` has no workspace scope**, only `ActiveAccountScope` and soft deletes
    (`src/Models/Email.php:71-73`, `105`). Every query filters `workspace_id`. The resolver drops
    both scopes so keys stay stable across a disconnect. The summary keeps them, so it counts
    only reachable mail.
19. **Line numbers.** The spec's `StoreEmailJob.php:147` is the `if` of the drop at 145 to 149.
    `GmailService.php:149` reads `In-Reply-To`; nothing reads `References`.

## Decisions made for the implementer

- **Member messages.** `last_member_outbound_at` counts outbound copies and inbound copies sent
  from a member or mailbox address. A teammate replying from an unconnected mailbox still clears
  Needs reply (D5). To overrule, drop the `orWhereHas` in `memberMessages()`.
- **Matches with a null key are skipped.** During the backfill, a reply whose parent has no key
  yet takes a root-hash key. The parent's backfill finds it through `keysToMerge()` and merges.
- **Merge direction.** The key whose oldest message is older survives; a tie keeps the smaller
  string. Racing jobs converge.
- **Drafts carry no key** and outbound mail is never automated.
- **Relayed mail skips the sender rules.** Header rules still apply.
- **Overrides are lowercase, unique per workspace.** `user_id` is nullable with `nullOnDelete`,
  like `database/migrations/2026_09_28_061112_keep_workspace_email_rules_when_their_creator_is_deleted.php`.

---

# PR 2: conversation data

**Branch:** `feat/email-conversation-data` from `main`.

**Goal:** New and existing mail carries `conversation_key` and `references`. Graph replies thread.
One `email_conversations` row per conversation holds timestamps, counts and directions. Late
parents merge split conversations.

## File map

| File | Change | Owns |
|---|---|---|
| `database/migrations/2026_11_02_100000_add_conversation_columns_to_emails_table.php` | create | the two columns |
| `database/migrations/2026_11_02_100100_index_emails_for_conversation_lookups.php` | create | four concurrent indexes |
| `database/migrations/2026_11_02_100200_create_email_conversations_table.php` | create | the summary table |
| `database/migrations/2026_11_02_100300_queue_conversation_backfill.php` | create | queues the backfill |
| `src/Data/FetchedEmailData.php` | modify | `references` |
| `src/Support/MessageIdList.php` | create | parsing and ordering Message-ID headers |
| `src/Services/GmailService.php`, `src/Services/MicrosoftGraphMailService.php` | modify | reply headers |
| `src/Models/Email.php` | modify | fillable, docblock |
| `src/Models/EmailConversation.php` | create | summary model |
| `src/Services/ConversationKeyResolver.php` | create | rules 1 to 4, merge detection |
| `src/Actions/RefreshConversationSummary.php` | create | one summary row |
| `src/Jobs/MergeConversations.php` | create | key rewrite |
| `src/Actions/StoreEmailAction.php`, `src/Actions/SendEmailAction.php` | modify | write keys |
| `src/Services/EmailSendingService.php`, `src/Actions/DisconnectConnectedAccountAction.php` | modify | refresh summaries |
| `src/Console/Commands/BackfillConversationsCommand.php` | create | the backfill |
| `src/EmailIntegrationServiceProvider.php` | modify | registration outside the flag |
| `docs/superpowers/plans/2026-10-31-email-conversations.md` | modify | contracts table |
| `tests/Feature/EmailIntegration/{GmailMailServiceTest,MicrosoftGraphMailServiceTest,StoreEmailActionTest,SendEmailActionTest}.php` | modify | behaviour |
| `tests/Feature/EmailIntegration/BackfillConversationsCommandTest.php` | create | backfill |

Generate migrations with `php artisan make:migration --no-interaction` in this order. The dates
are placeholders.

---

### Task 1: Store References and read Graph In-Reply-To

**Files:**
- Create: the columns migration, `src/Support/MessageIdList.php`
- Modify: `src/Data/FetchedEmailData.php`, `src/Services/GmailService.php:149`, `src/Services/MicrosoftGraphMailService.php:152`, `src/Models/Email.php:44-99`, `src/Actions/StoreEmailAction.php:62-75`

**Interfaces:**
- Produces: `FetchedEmailData::$references` (`?string`, last argument, default null).
  `MessageIdList::parse(?string): list<string>`, `first(?string): ?string`,
  `references(?string): ?string`, `parents(?string $inReplyTo, ?string $references): list<string>`,
  `root(?string $inReplyTo, ?string $references, ?string $own): ?string`.

- [ ] **Step 1: Write the failing tests**

Append to `GmailMailServiceTest.php`:

```php
it('reads In-Reply-To and References from Gmail headers', function (): void {
    $data = fetchGmailMessageFromHeader('Ana <ana@acme.example>', extraHeaders: [
        'In-Reply-To' => ' <parent@acme.example> ',
        'References' => "<root@acme.example>\r\n <parent@acme.example>",
    ]);

    expect($data->inReplyTo)->toBe('<parent@acme.example>')
        ->and($data->references)->toBe('<root@acme.example> <parent@acme.example>');
});

it('keeps the root Message-ID when References holds more than fifty ids', function (): void {
    $ids = array_map(fn (int $i): string => "<m{$i}@acme.example>", range(1, 60));

    $kept = explode(' ', (string) fetchGmailMessageFromHeader('Ana <ana@acme.example>', extraHeaders: [
        'References' => implode(' ', $ids),
    ])->references);

    expect($kept)->toHaveCount(50)
        ->and($kept[0])->toBe('<m1@acme.example>')
        ->and($kept[1])->toBe('<m12@acme.example>')
        ->and(end($kept))->toBe('<m60@acme.example>');
});
```

Append to `MicrosoftGraphMailServiceTest.php`:

```php
it('reads In-Reply-To and References from Graph message headers', function (): void {
    Http::fake([
        ...graphWellKnownFolderFakes(),
        'https://graph.microsoft.com/v1.0/me/messages/MSG1*' => Http::response(graphMessagePayload([
            'internetMessageHeaders' => [
                ['name' => 'in-reply-to', 'value' => '<parent@acme.example>'],
                ['name' => 'References', 'value' => '<root@acme.example> <parent@acme.example>'],
            ],
        ])),
    ]);

    $email = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount())->fetchMessage('MSG1');

    expect($email->inReplyTo)->toBe('<parent@acme.example>')
        ->and($email->references)->toBe('<root@acme.example> <parent@acme.example>');
});
```

In `StoreEmailActionTest.php`, add `references: $overrides['references'] ?? null,` as the last
argument of `makeFetchedEmailData()`, then:

```php
it('stores the References header on the email', function (): void {
    $email = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'references' => '<root@acme.example> <parent@acme.example>',
    ]));

    expect($email->refresh()->references)->toBe('<root@acme.example> <parent@acme.example>');
});
```

- [ ] **Step 2: Run and watch them fail**

Run: `php artisan test --compact tests/Feature/EmailIntegration --filter='(GmailMailServiceTest|MicrosoftGraphMailServiceTest|StoreEmailActionTest)::.*References'`
Expected: FAIL with `Unknown named parameter $references`.

- [ ] **Step 3: Implement**

Migration body:

```php
Schema::table('emails', function (Blueprint $table): void {
    $table->string('conversation_key', 64)->nullable();
    $table->text('references')->nullable();
});
```

Nullable with no default, so PostgreSQL changes only the catalogue.

```php
final readonly class MessageIdList
{
    private const int MAX_REFERENCES = 50;

    /** @return list<string> */
    public static function parse(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        preg_match_all('/<[^<>\s]+>/', $header, $matches);

        return array_values(array_unique($matches[0] !== [] ? $matches[0] : preg_split('/\s+/', trim($header))));
    }

    public static function first(?string $header): ?string
    {
        return self::parse($header)[0] ?? null;
    }

    public static function references(?string $header): ?string
    {
        $ids = self::parse($header);

        if (count($ids) > self::MAX_REFERENCES) {
            $ids = [$ids[0], ...array_slice($ids, 1 - self::MAX_REFERENCES)];
        }

        return $ids === [] ? null : implode(' ', $ids);
    }

    /** @return list<string> In-Reply-To first, then References newest to oldest */
    public static function parents(?string $inReplyTo, ?string $references): array
    {
        return array_values(array_unique([...self::parse($inReplyTo), ...array_reverse(self::parse($references))]));
    }

    public static function root(?string $inReplyTo, ?string $references, ?string $own): ?string
    {
        return self::parse($references)[0] ?? self::first($inReplyTo) ?? self::first($own);
    }
}
```

`FetchedEmailData`: add `public ?string $references = null,` after `isBulkMail`.

`GmailService::fetchMessage()`: line 149 becomes
`inReplyTo: MessageIdList::first($headers->get('in-reply-to')?->getValue()),` and the last
argument is `references: MessageIdList::references($headers->get('references')?->getValue()),`.

`MicrosoftGraphMailService::fetchMessage()`: line 152 becomes
`inReplyTo: MessageIdList::first($this->headerValue($message, 'in-reply-to')),`, the last argument
is `references: MessageIdList::references($this->headerValue($message, 'references')),`, and
beside `hasBulkMailHeaders()`:

```php
    /** @param  array<string, mixed>  $message */
    private function headerValue(array $message, string $name): ?string
    {
        foreach ($message['internetMessageHeaders'] ?? [] as $header) {
            if (Str::lower((string) ($header['name'] ?? '')) === $name) {
                return (string) ($header['value'] ?? '');
            }
        }

        return null;
    }
```

`Email`: add both columns to `#[Fillable]` and as `string|null` properties in the docblock.
`StoreEmailAction`: add `'references' => $data->references,` to the create array.

- [ ] **Step 4: Run** the Step 2 command, then the three files in full with
`--parallel --processes=4 --filter='(GmailMailServiceTest|MicrosoftGraphMailServiceTest|StoreEmailActionTest)::'`. Expected: PASS.

- [ ] **Step 5: Format and commit.** `vendor/bin/pint --dirty --format agent`.
Commit: `feat(email): store references and read graph in-reply-to`

---

### Task 2: Resolve a conversation key at store

**Files:**
- Create: the index migration, `src/Services/ConversationKeyResolver.php`
- Modify: `src/Actions/StoreEmailAction.php:62-75`

**Interfaces:**
- Produces: `resolve(FetchedEmailData $data, ConnectedAccount $account): string`,
  `resolveStored(Email $email): string`, `keysToMerge(Email $email): list<string>`, and
  `static keyFor(string $workspaceId, string $root): string`.

- [ ] **Step 1: Write the failing tests**

Append to `StoreEmailActionTest.php`:

```php
function secondMailbox(Workspace $workspace): ConnectedAccount
{
    $teammate = User::factory()->create();
    $workspace->users()->attach($teammate, ['role' => 'admin']);

    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $teammate->getKey(),
    ]));
}

it('gives a reply the conversation key of its stored parent', function (): void {
    $parent = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'p', 'rfcMessageId' => '<p@acme.example>', 'threadId' => 't1',
    ]));

    $reply = resolve(StoreEmailAction::class)->execute(secondMailbox($this->workspace), makeFetchedEmailData([
        'providerMessageId' => 'r', 'rfcMessageId' => '<r@acme.example>', 'threadId' => 't2',
        'inReplyTo' => '<p@acme.example>',
    ]));

    expect($reply->conversation_key)->toBe($parent->conversation_key)->toHaveLength(64);
});

it('joins a reply that arrived before its parent once the parent is stored', function (): void {
    $reply = resolve(StoreEmailAction::class)->execute(secondMailbox($this->workspace), makeFetchedEmailData([
        'providerMessageId' => 'r', 'rfcMessageId' => '<r@acme.example>', 'threadId' => 'tb',
        'inReplyTo' => '<p@acme.example>', 'references' => '<root@acme.example> <p@acme.example>',
    ]));

    $parent = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'p', 'rfcMessageId' => '<p@acme.example>', 'threadId' => 'ta',
        'inReplyTo' => '<root@acme.example>', 'references' => '<root@acme.example>',
    ]));

    expect($parent->conversation_key)
        ->toBe($reply->conversation_key)
        ->toBe(ConversationKeyResolver::keyFor($this->workspace->getKey(), '<root@acme.example>'));
});

it('follows References to a stored ancestor when In-Reply-To is missing', function (): void {
    $first = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'f', 'rfcMessageId' => '<first@acme.example>', 'threadId' => 'ta',
    ]));
    resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'root', 'rfcMessageId' => '<root@acme.example>', 'threadId' => 'ta',
    ]));

    $reply = resolve(StoreEmailAction::class)->execute(secondMailbox($this->workspace), makeFetchedEmailData([
        'providerMessageId' => 'r', 'rfcMessageId' => '<r@acme.example>', 'threadId' => 'tb',
        'inReplyTo' => null, 'references' => '<root@acme.example> <unseen@acme.example>',
    ]));

    expect($reply->conversation_key)
        ->toBe($first->conversation_key)
        ->not->toBe(ConversationKeyResolver::keyFor($this->workspace->getKey(), '<root@acme.example>'));
});

it('keeps two messages apart when they only share a subject', function (): void {
    $one = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => '1', 'rfcMessageId' => '<1@acme.example>', 'threadId' => 't1', 'subject' => 'Pricing',
    ]));
    $two = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => '2', 'rfcMessageId' => '<2@acme.example>', 'threadId' => 't2', 'subject' => 'Re: Pricing',
    ]));

    expect($two->conversation_key)->not->toBe($one->conversation_key);
});

it('never joins a conversation in another workspace on a shared Message-ID', function (): void {
    $foreign = Email::factory()->create([
        'workspace_id' => User::factory()->withWorkspace()->create()->currentWorkspace->getKey(),
        'rfc_message_id' => '<shared@acme.example>',
        'conversation_key' => str_repeat('f', 64),
    ]);

    $reply = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'inReplyTo' => '<shared@acme.example>',
    ]));

    expect($reply->conversation_key)
        ->not->toBe($foreign->conversation_key)
        ->toBe(ConversationKeyResolver::keyFor($this->workspace->getKey(), '<shared@acme.example>'));
});
```

The "two mailboxes hold one message" case is proved in Task 3 by a message count of 1.
In the References test, the ancestor's key comes from the thread rule, so a root hash cannot
pass it by accident.

Append to `MicrosoftGraphMailServiceTest.php`, with differing `conversationId` values so only
the header rule can link them:

```php
it('threads a Graph reply onto the stored message it answers', function (): void {
    $account = makeAzureAccount();
    $store = function (string $id, array $overrides) use ($account): Email {
        Http::fake([
            ...graphWellKnownFolderFakes(),
            "https://graph.microsoft.com/v1.0/me/messages/{$id}*" => Http::response(graphMessagePayload(['id' => $id, ...$overrides])),
        ]);

        return resolve(StoreEmailAction::class)->execute($account, resolve(MicrosoftGraphServiceFactory::class)->make($account)->fetchMessage($id));
    };

    $parent = $store('P1', ['internetMessageId' => '<p@acme.example>', 'conversationId' => 'c1']);
    $reply = $store('R1', [
        'internetMessageId' => '<r@acme.example>',
        'conversationId' => 'c2',
        'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => '<p@acme.example>']],
    ]);

    expect($reply->conversation_key)->toBe($parent->conversation_key);
});
```

- [ ] **Step 2: Run and watch them fail**

Run: `php artisan test --compact tests/Feature/EmailIntegration --filter='(StoreEmailActionTest|MicrosoftGraphMailServiceTest)::.*(conversation|References to a stored|share a subject|threads a Graph)'`
Expected: FAIL, `Class "...ConversationKeyResolver" not found`.

- [ ] **Step 3: Add the indexes**

Copy `database/migrations/2026_10_16_222058_index_foreign_key_columns.php`: `$withinTransaction = false`
and its `dropInvalidIndex()`. The loop runs over:

```php
    private const array INDEXES = [
        'emails_workspace_conversation_key_index' => 'btree (workspace_id, conversation_key)',
        'emails_workspace_rfc_message_id_index' => 'btree (workspace_id, rfc_message_id)',
        'emails_workspace_in_reply_to_index' => 'btree (workspace_id, in_reply_to)',
        'emails_references_ids_index' => 'gin (string_to_array("references", \' \'))',
    ];
    // per entry: DB::statement("create index concurrently if not exists {$index} on emails using {$definition}");
```

- [ ] **Step 4: Write the resolver**

```php
final readonly class ConversationKeyResolver
{
    public function resolve(FetchedEmailData $data, ConnectedAccount $account): string
    {
        return $this->resolveFor(
            (string) $account->workspace_id,
            (string) $account->getKey(),
            $data->threadId,
            $data->rfcMessageId,
            MessageIdList::parents($data->inReplyTo, $data->references),
            MessageIdList::root($data->inReplyTo, $data->references, $data->rfcMessageId) ?? "{$account->getKey()}:{$data->providerMessageId}",
            null,
        );
    }

    public function resolveStored(Email $email): string
    {
        return $this->resolveFor(
            $email->workspace_id,
            $email->connected_account_id,
            (string) $email->thread_id,
            $email->rfc_message_id,
            MessageIdList::parents($email->in_reply_to, $email->references),
            MessageIdList::root($email->in_reply_to, $email->references, $email->rfc_message_id) ?? $email->getKey(),
            $email->getKey(),
        );
    }

    /** @return list<string> keys held by this message's other copies or by stored replies to it */
    public function keysToMerge(Email $email): array
    {
        if ($email->rfc_message_id === null || $email->conversation_key === null) {
            return [];
        }

        return $this->keyed($email->workspace_id, $email->getKey())
            ->where('conversation_key', '!=', $email->conversation_key)
            ->where(fn (Builder $query): Builder => $query
                ->where('rfc_message_id', $email->rfc_message_id)
                ->orWhere('in_reply_to', $email->rfc_message_id)
                ->orWhereRaw('string_to_array("references", \' \') @> array[?]::text[]', [$email->rfc_message_id]))
            ->distinct()
            ->pluck('conversation_key')
            ->all();
    }

    public static function keyFor(string $workspaceId, string $root): string
    {
        return hash('sha256', $workspaceId.$root);
    }

    /** @param  list<string>  $parents */
    private function resolveFor(string $workspaceId, string $accountId, string $threadId, ?string $rfcMessageId, array $parents, string $root, ?string $exceptId): string
    {
        $keyed = fn (): Builder => $this->keyed($workspaceId, $exceptId)->orderBy('conversation_key');

        return $this->keyOfParent($keyed(), $parents)
            ?? ($threadId === '' ? null : $keyed()->where('connected_account_id', $accountId)->where('thread_id', $threadId)->value('conversation_key'))
            ?? ($rfcMessageId === null ? null : $keyed()->where('rfc_message_id', $rfcMessageId)->value('conversation_key'))
            ?? self::keyFor($workspaceId, $root);
    }

    /**
     * @param  Builder<Email>  $keyed
     * @param  list<string>  $parents
     */
    private function keyOfParent(Builder $keyed, array $parents): ?string
    {
        if ($parents === []) {
            return null;
        }

        $keys = $keyed->whereIn('rfc_message_id', $parents)->pluck('conversation_key', 'rfc_message_id');

        return collect($parents)->map(fn (string $id): ?string => $keys->get($id))->filter()->first();
    }

    /** @return Builder<Email> */
    private function keyed(string $workspaceId, ?string $exceptId): Builder
    {
        return Email::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('conversation_key')
            ->when($exceptId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptId));
    }
}
```

The order is the spec's: parents (In-Reply-To, then References newest first), same mailbox and
provider thread, another copy of this message, then the root hash. When copies disagree, any
stable pick works, because `keysToMerge()` joins the rest.

- [ ] **Step 5: Use it.** Add to the create array in `StoreEmailAction::storeForAccount()`:
`'conversation_key' => resolve(ConversationKeyResolver::class)->resolve($data, $connectedAccount),`.

- [ ] **Step 6: Run** the Step 2 command, then both files in full. Expected: PASS.
`LazilyRefreshDatabase` runs the concurrent index migration outside a transaction.

- [ ] **Step 7: Format and commit.** Commit: `feat(email): store a conversation key on every email`

---

### Task 3: Summarise each conversation without text

**Files:**
- Create: the summary table migration, `src/Models/EmailConversation.php`, `src/Actions/RefreshConversationSummary.php`
- Modify: `src/Actions/StoreEmailAction.php:144` and `265-275`, `src/Services/EmailSendingService.php:52-60`

**Interfaces:**
- Produces: `RefreshConversationSummary::execute(string $workspaceId, string $conversationKey): void`.
  Idempotent; deletes the row when no reachable message remains.

- [ ] **Step 1: Write the failing tests**

```php
it('summarises reply timing on the conversation without storing text', function (): void {
    $this->travelTo(now()->startOfHour());
    $inbound = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'in', 'rfcMessageId' => '<in@acme.example>', 'sentAt' => now()->subHours(2), 'hasAttachments' => true,
    ]));
    resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'out', 'rfcMessageId' => '<out@acme.example>', 'inReplyTo' => '<in@acme.example>',
        'direction' => EmailDirection::OUTBOUND, 'folder' => EmailFolder::Sent, 'sentAt' => now()->subHour(),
        'participants' => [['email_address' => $this->account->email_address, 'name' => null, 'role' => 'from']],
    ]));

    $summary = EmailConversation::query()->where('conversation_key', $inbound->conversation_key)->sole();

    expect($summary->message_count)->toBe(2)
        ->and($summary->first_message_at->equalTo(now()->subHours(2)))->toBeTrue()
        ->and($summary->last_external_inbound_at->equalTo(now()->subHours(2)))->toBeTrue()
        ->and($summary->last_member_outbound_at->equalTo(now()->subHour()))->toBeTrue()
        ->and($summary->has_attachments)->toBeTrue()
        ->and($summary->getAttributes())->not->toHaveKeys(['subject', 'snippet']);
});

it('counts one message once when two mailboxes hold it', function (): void {
    $mine = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'a', 'rfcMessageId' => '<shared@acme.example>', 'threadId' => 'ta',
    ]));
    resolve(StoreEmailAction::class)->execute(secondMailbox($this->workspace), makeFetchedEmailData([
        'providerMessageId' => 'b', 'rfcMessageId' => '<shared@acme.example>', 'threadId' => 'tb',
    ]));

    expect(EmailConversation::query()->where('workspace_id', $this->workspace->getKey())->sole())
        ->conversation_key->toBe($mine->conversation_key)
        ->message_count->toBe(1);
});

it('treats mail from a teammate as member mail, not external inbound', function (): void {
    $email = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'participants' => [['email_address' => strtoupper(secondMailbox($this->workspace)->email_address), 'name' => null, 'role' => 'from']],
    ]));

    expect(EmailConversation::query()->where('conversation_key', $email->conversation_key)->sole())
        ->last_external_inbound_at->toBeNull()
        ->last_member_outbound_at->not->toBeNull();
});
```

- [ ] **Step 2: Run and watch them fail**

Run: `php artisan test --compact tests/Feature/EmailIntegration/StoreEmailActionTest.php --filter='summarises|counts one message|teammate as member'`
Expected: FAIL, `Class "...EmailConversation" not found`.

- [ ] **Step 3: Table and model**

```php
Schema::create('email_conversations', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    TenantMigration::addForeignKey($table);
    $table->string('conversation_key', 64);
    $table->unsignedInteger('message_count');
    $table->timestamp('first_message_at');
    $table->timestamp('last_message_at');
    $table->timestamp('last_external_inbound_at')->nullable();
    $table->timestamp('last_member_outbound_at')->nullable();
    $table->boolean('has_attachments')->default(false);
    $table->boolean('is_automated')->default(false);
    $table->timestamps();

    $table->unique(['workspace_id', 'conversation_key']);
    $table->index(['workspace_id', 'last_message_at']);
    $table->index(['workspace_id', 'last_external_inbound_at']);
});
```

A btree serves `desc` scans, so the spec's `desc` needs no extra syntax.

`EmailConversation`: `final`, `HasUlids`, `HasWorkspace`, `#[Fillable]` with every column but
`id` and the timestamps, `datetime` casts for the four message timestamps, `boolean` casts for
the two flags, and a typed `@property` docblock (`CarbonImmutable`). No factory.

- [ ] **Step 4: Write the action**

```php
final readonly class RefreshConversationSummary
{
    public function execute(string $workspaceId, string $conversationKey): void
    {
        $totals = $this->messages($workspaceId, $conversationKey)->toBase()
            ->selectRaw('count(distinct coalesce(rfc_message_id, id::text)) as message_count')
            ->selectRaw('min(sent_at) as first_message_at, max(sent_at) as last_message_at')
            ->selectRaw('coalesce(bool_or(has_attachments), false) as has_attachments')
            ->first();

        if ((int) ($totals->message_count ?? 0) === 0) {
            EmailConversation::query()->where('workspace_id', $workspaceId)->where('conversation_key', $conversationKey)->delete();

            return;
        }

        $members = $this->memberAddresses($workspaceId);

        EmailConversation::query()->upsert([[
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspaceId,
            'conversation_key' => $conversationKey,
            'message_count' => (int) $totals->message_count,
            'first_message_at' => $totals->first_message_at,
            'last_message_at' => $totals->last_message_at,
            'last_external_inbound_at' => $this->externalInbound($workspaceId, $conversationKey, $members)->max('sent_at'),
            'last_member_outbound_at' => $this->memberMessages($workspaceId, $conversationKey, $members)->max('sent_at'),
            'has_attachments' => (bool) $totals->has_attachments,
            'is_automated' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['workspace_id', 'conversation_key'], [
            'message_count', 'first_message_at', 'last_message_at', 'last_external_inbound_at',
            'last_member_outbound_at', 'has_attachments', 'is_automated', 'updated_at',
        ]);
    }

    /** @return Builder<Email> */
    private function messages(string $workspaceId, string $conversationKey): Builder
    {
        return Email::query()
            ->where('workspace_id', $workspaceId)
            ->where('conversation_key', $conversationKey)
            ->whereIn('status', [EmailStatus::SYNCED, EmailStatus::SENT])
            ->whereNotNull('sent_at');
    }

    /**
     * @param  list<string>  $members
     * @return Builder<Email>
     */
    private function externalInbound(string $workspaceId, string $conversationKey, array $members): Builder
    {
        return $this->messages($workspaceId, $conversationKey)
            ->where('direction', EmailDirection::INBOUND)
            ->whereDoesntHave('participants', fn (Builder $query): Builder => $this->sentBy($query, $members));
    }

    /**
     * @param  list<string>  $members
     * @return Builder<Email>
     */
    private function memberMessages(string $workspaceId, string $conversationKey, array $members): Builder
    {
        return $this->messages($workspaceId, $conversationKey)->where(fn (Builder $query): Builder => $query
            ->where('direction', EmailDirection::OUTBOUND)
            ->orWhereHas('participants', fn (Builder $participants): Builder => $this->sentBy($participants, $members)));
    }

    /**
     * @param  Builder<EmailParticipant>  $query
     * @param  list<string>  $members
     * @return Builder<EmailParticipant>
     */
    private function sentBy(Builder $query, array $members): Builder
    {
        return $query->where('role', EmailParticipantRole::FROM->value)->whereIn(DB::raw('lower(email_address)'), $members);
    }

    /** @return list<string> */
    private function memberAddresses(string $workspaceId): array
    {
        return Workspace::query()->findOrFail($workspaceId)->allUsers()->pluck('email')
            ->merge(ConnectedAccount::query()->withoutGlobalScopes()->where('workspace_id', $workspaceId)->pluck('email_address'))
            ->map(fn (string $address): string => Str::lower($address))
            ->unique()->values()->all();
    }
}
```

`sentBy()` is private, so the arch rule on public builder parameters does not apply.

- [ ] **Step 5: Refresh after every write.** In `StoreEmailAction`, after
`SyncEmailThreadAction` at line 144, call
`resolve(RefreshConversationSummary::class)->execute($email->workspace_id, $email->conversation_key);`.
In `adoptPendingSentEmail()`, refresh the adopted row's key after its update. In
`EmailSendingService::finalizeSentEmail()`, refresh after `SyncEmailThreadAction`. Guard both on
a non-null key.

- [ ] **Step 6: Run** the Step 2 command, then the whole file. Expected: PASS.

- [ ] **Step 7: Format and commit.** Commit: `feat(email): summarise each conversation without text`

---

### Task 4: Merge two conversations when a late parent links them

**Files:**
- Create: `src/Jobs/MergeConversations.php`
- Modify: `src/Actions/StoreEmailAction.php`

**Interfaces:**
- Produces: `MergeConversations(string $workspaceId, string $key, string $otherKey)` on
  `emails-sync`, unique per workspace and key pair.

- [ ] **Step 1: Write the failing test**

```php
it('merges two conversations when a late parent links them', function (): void {
    $this->travelTo(now()->startOfHour());

    $grandparent = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'g', 'rfcMessageId' => '<g@acme.example>', 'threadId' => 'ta', 'sentAt' => now()->subDays(2),
    ]));
    $reply = resolve(StoreEmailAction::class)->execute(secondMailbox($this->workspace), makeFetchedEmailData([
        'providerMessageId' => 'r', 'rfcMessageId' => '<r@acme.example>', 'threadId' => 'tb',
        'inReplyTo' => '<p@acme.example>', 'sentAt' => now(),
    ]));

    expect($reply->conversation_key)->not->toBe($grandparent->conversation_key);

    resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'p', 'rfcMessageId' => '<p@acme.example>', 'threadId' => 'tc',
        'inReplyTo' => '<g@acme.example>', 'sentAt' => now()->subDay(),
    ]));

    expect(Email::query()->where('workspace_id', $this->workspace->getKey())->distinct()->pluck('conversation_key')->all())
        ->toBe([$grandparent->conversation_key])
        ->and(EmailConversation::query()->where('workspace_id', $this->workspace->getKey())->sole()->message_count)->toBe(3);
});
```

The queue is `sync` in tests, so the after-commit dispatch runs before the assertions.

- [ ] **Step 2: Run and watch it fail**

Run: `php artisan test --compact tests/Feature/EmailIntegration/StoreEmailActionTest.php --filter='late parent'`
Expected: FAIL, two keys and two summary rows.

- [ ] **Step 3: Write the job**

```php
#[Queue('emails-sync')]
#[Tries(3)]
#[UniqueFor(600)]
final class MergeConversations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $key,
        public readonly string $otherKey,
    ) {}

    public function uniqueId(): string
    {
        $pair = [$this->key, $this->otherKey];
        sort($pair);

        return "{$this->workspaceId}:".implode(':', $pair);
    }

    public function handle(CurrentWorkspace $currentWorkspace, RefreshConversationSummary $refresh): void
    {
        $currentWorkspace->within($this->workspaceId, function () use ($refresh): void {
            [$keep, $retire] = $this->survivorFirst();

            Email::query()->withoutGlobalScopes()
                ->where('workspace_id', $this->workspaceId)
                ->where('conversation_key', $retire)
                ->toBase()
                ->update(['conversation_key' => $keep]);

            $refresh->execute($this->workspaceId, $retire);
            $refresh->execute($this->workspaceId, $keep);
        });
    }

    /** @return array{string, string} */
    private function survivorFirst(): array
    {
        $keep = Email::query()->withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->whereIn('conversation_key', [$this->key, $this->otherKey])
            ->groupBy('conversation_key')
            ->orderByRaw('min(sent_at) asc nulls last, conversation_key asc')
            ->value('conversation_key') ?? $this->key;

        return [$keep, $keep === $this->key ? $this->otherKey : $this->key];
    }
}
```

`toBase()->update()` leaves `updated_at` alone: a key rewrite is not a user edit.

- [ ] **Step 4: Dispatch from the store,** after the summary refresh:

```php
                foreach (resolve(ConversationKeyResolver::class)->keysToMerge($email) as $otherKey) {
                    MergeConversations::dispatch($email->workspace_id, $email->conversation_key, $otherKey)->afterCommit();
                }
```

- [ ] **Step 5: Run** the Step 2 command and the whole file. Expected: PASS.

- [ ] **Step 6: Format and commit.** Commit: `feat(email): merge conversations a late parent links`

---

### Task 5: Key app sends and rebuild after a disconnect

**Files:**
- Modify: `src/Actions/SendEmailAction.php:125-153`, `src/Actions/DisconnectConnectedAccountAction.php:39`
- Test: `SendEmailActionTest.php`

- [ ] **Step 1: Write the failing tests**

```php
it('gives a queued reply the conversation key of the email it answers', function (): void {
    $original = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'conversation_key' => str_repeat('a', 64),
    ]);

    $reply = app(SendEmailAction::class)->execute($this->user, [
        'connected_account_id' => $this->account->id,
        'subject' => 'Re: Pricing',
        'body_html' => '<p>Thanks</p>',
        'to' => [['email' => 'ana@acme.example', 'name' => null]],
        'in_reply_to_email_id' => $original->id,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    expect($reply->conversation_key)->toBe(str_repeat('a', 64));
});

it('starts a new conversation for a new queued email', function (): void {
    $email = app(SendEmailAction::class)->execute($this->user, [
        'connected_account_id' => $this->account->id,
        'subject' => 'Intro',
        'body_html' => '<p>Hi</p>',
        'to' => [['email' => 'ana@acme.example', 'name' => null]],
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    expect($email->conversation_key)->toBe(ConversationKeyResolver::keyFor($this->workspace->id, $email->rfc_message_id));
});
```

- [ ] **Step 2: Run and watch them fail.** `php artisan test --compact tests/Feature/EmailIntegration/SendEmailActionTest.php --filter='conversation'`.
Expected: FAIL, null key.

- [ ] **Step 3: Implement.** Inject `private ConversationKeyResolver $resolver` and, inside the
transaction right after the create at line 125, write
`$email->updateQuietly(['conversation_key' => $this->resolver->resolveStored($email)]);`.
A queued row does not count in the summary, which reads only `synced` and `sent`. The send
refreshes it (Task 3).

A disconnect hides the mailbox's mail through `ActiveAccountScope`, so its summaries go stale.
After the transaction in `DisconnectConnectedAccountAction::execute()`:

```php
        Artisan::queue('email:backfill-conversations', ['--force' => true, '--workspace' => $account->workspace_id])
            ->onQueue('imports')
            ->afterCommit();
```

The command test in Task 6 covers the rebuild, because the command is the entry point.

- [ ] **Step 4: Run** the Step 2 command and `EmailAccountsPageTest.php`. Expected: PASS.

- [ ] **Step 5: Format and commit.** Commit: `feat(email): key queued sends and rebuild after disconnect`

---

### Task 6: Backfill keys and summaries

**Files:**
- Create: `src/Console/Commands/BackfillConversationsCommand.php`, the queue migration, `tests/Feature/EmailIntegration/BackfillConversationsCommandTest.php`
- Modify: `src/EmailIntegrationServiceProvider.php:215-218`, the master contracts table

**Interfaces:**
- Produces: `email:backfill-conversations {--force} {--workspace=} {--chunk=20000}`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Console\Commands\BackfillConversationsCommand;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailConversation;

mutates(BackfillConversationsCommand::class);

beforeEach(function (): void {
    $this->workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $this->mailbox = fn (): ConnectedAccount => ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => User::factory()->create()->getKey(),
    ]));
    $this->email = fn (ConnectedAccount $mailbox, array $attributes = []): Email => Email::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $mailbox->user_id,
        'connected_account_id' => $mailbox->getKey(),
        'conversation_key' => null,
        ...$attributes,
    ]);
});

it('reports unkeyed mail without writing', function (): void {
    ($this->email)(($this->mailbox)());

    $this->artisan('email:backfill-conversations')->expectsOutputToContain('1 email(s) without a conversation key')->assertSuccessful();

    expect(Email::query()->whereNull('conversation_key')->count())->toBe(1)
        ->and(EmailConversation::query()->count())->toBe(0);
});

it('keys history across mailboxes and builds one summary per conversation', function (): void {
    [$mine, $theirs] = [($this->mailbox)(), ($this->mailbox)()];
    ($this->email)($mine, ['rfc_message_id' => '<p@acme.example>', 'thread_id' => 'ta', 'sent_at' => now()->subDay()]);
    ($this->email)($theirs, ['rfc_message_id' => '<p@acme.example>', 'thread_id' => 'tb', 'sent_at' => now()->subDay()]);
    ($this->email)($theirs, ['rfc_message_id' => '<r@acme.example>', 'thread_id' => 'tc', 'in_reply_to' => '<p@acme.example>']);
    ($this->email)($mine, ['rfc_message_id' => '<x@acme.example>', 'thread_id' => 'td']);

    $this->artisan('email:backfill-conversations', ['--force' => true])->assertSuccessful();

    expect(Email::query()->whereNull('conversation_key')->count())->toBe(0)
        ->and(Email::query()->distinct()->count('conversation_key'))->toBe(2)
        ->and(EmailConversation::query()->orderByDesc('message_count')->pluck('message_count')->all())->toBe([2, 1]);
});

it('changes nothing when it runs a second time', function (): void {
    $mailbox = ($this->mailbox)();
    ($this->email)($mailbox, ['rfc_message_id' => '<p@acme.example>']);
    ($this->email)($mailbox, ['rfc_message_id' => '<r@acme.example>', 'in_reply_to' => '<p@acme.example>', 'thread_id' => 'other']);
    $this->artisan('email:backfill-conversations', ['--force' => true])->assertSuccessful();
    $keys = Email::query()->orderBy('id')->pluck('conversation_key', 'id')->all();
    $summaries = EmailConversation::query()->get(['id', 'message_count', 'updated_at'])->toArray();

    $this->travel(1)->hours();
    $this->artisan('email:backfill-conversations', ['--force' => true])
        ->expectsOutputToContain('0 email(s) keyed, 0 summary(ies) rebuilt')
        ->assertSuccessful();

    expect(Email::query()->orderBy('id')->pluck('conversation_key', 'id')->all())->toBe($keys)
        ->and(EmailConversation::query()->get(['id', 'message_count', 'updated_at'])->toArray())->toBe($summaries);
});

it('drops the summary of a disconnected mailbox', function (): void {
    $mailbox = ($this->mailbox)();
    ($this->email)($mailbox);
    $this->artisan('email:backfill-conversations', ['--force' => true])->assertSuccessful();

    $mailbox->delete();
    $this->artisan('email:backfill-conversations', ['--force' => true, '--workspace' => $this->workspace->getKey()])->assertSuccessful();

    expect(EmailConversation::query()->count())->toBe(0);
});

it('queues the rest on the imports lane when a run reaches its chunk', function (): void {
    Queue::fake();
    $mailbox = ($this->mailbox)();
    ($this->email)($mailbox);
    ($this->email)($mailbox);

    $this->artisan('email:backfill-conversations', ['--force' => true, '--chunk' => 1])->assertSuccessful();

    expect(Email::query()->whereNull('conversation_key')->count())->toBe(1);
    Queue::assertPushedOn('imports', QueuedCommand::class);
});

it('leaves drafts without a key', function (): void {
    ($this->email)(($this->mailbox)(), ['status' => EmailStatus::DRAFT, 'sent_at' => null]);

    $this->artisan('email:backfill-conversations', ['--force' => true])->assertSuccessful();

    expect(Email::query()->whereNotNull('conversation_key')->count())->toBe(0);
});
```

- [ ] **Step 2: Run and watch them fail.** `php artisan test --compact tests/Feature/EmailIntegration/BackfillConversationsCommandTest.php`.
Expected: FAIL, `The command "email:backfill-conversations" does not exist.`

- [ ] **Step 3: Write the command**

```php
#[Description('Give stored emails a conversation key and rebuild conversation summaries')]
#[Signature('email:backfill-conversations
    {--force : Write keys and summaries instead of reporting them}
    {--workspace= : Only this workspace}
    {--chunk=20000 : Emails to key in one run before queueing the rest}')]
final class BackfillConversationsCommand extends Command
{
    public function handle(ConversationKeyResolver $resolver, RefreshConversationSummary $refresh, CurrentWorkspace $currentWorkspace): int
    {
        if (! $this->option('force')) {
            $this->comment("{$this->unkeyed()->count()} email(s) without a conversation key. Re-run with --force to write them.");

            return self::SUCCESS;
        }

        $budget = max(1, (int) $this->option('chunk'));
        $keyed = 0;

        foreach ($this->unkeyed()->lazyById(500) as $email) {
            if ($keyed === $budget) {
                $this->queueRest();
                $this->comment("{$keyed} email(s) keyed. The rest is queued.");

                return self::SUCCESS;
            }

            $currentWorkspace->within($email->workspace_id, function () use ($email, $resolver): void {
                $this->key($email, $resolver);
            });
            $keyed++;
        }

        $this->comment("{$keyed} email(s) keyed, {$this->rebuildStaleSummaries($refresh)} summary(ies) rebuilt.");

        return self::SUCCESS;
    }

    private function key(Email $email, ConversationKeyResolver $resolver): void
    {
        $email->timestamps = false;
        $email->conversation_key = $resolver->resolveStored($email);
        $email->saveQuietly();

        foreach ($resolver->keysToMerge($email) as $otherKey) {
            MergeConversations::dispatchSync($email->workspace_id, $email->conversation_key, $otherKey);
        }
    }

    /** @return Builder<Email> */
    private function unkeyed(): Builder
    {
        return Email::query()->withoutGlobalScopes()
            ->whereNull('conversation_key')
            ->where('status', '!=', EmailStatus::DRAFT)
            ->when($this->option('workspace'), fn (Builder $query, string $id): Builder => $query->where('workspace_id', $id));
    }

    private function queueRest(): void
    {
        Artisan::queue('email:backfill-conversations', array_filter([
            '--force' => true,
            '--workspace' => $this->option('workspace'),
            '--chunk' => $this->option('chunk'),
        ]))->onQueue('imports')->delay(now()->addMinute());
    }
}
```

`timestamps = false` keeps `updated_at`, which the rehearsal diff checks. `saveQuietly()` keeps
`EmailObserver` from relinking.

`rebuildStaleSummaries(RefreshConversationSummary $refresh): int` refreshes only what differs, so
a rerun writes nothing. It builds `$live`: `Email::query()` (default scopes, so disconnected mail
drops out) with a non-null key, status `synced` or `sent`, a `sent_at`, the `--workspace` filter,
grouped by `workspace_id, conversation_key`, selecting
`count(distinct coalesce(rfc_message_id, id::text)) as message_count, max(sent_at) as last_message_at`.
Then it cursors over the union of two key sets and calls `$refresh->execute()` for each:

```php
        $stale = DB::query()->fromSub($live, 'live')
            ->leftJoin('email_conversations as summary', fn (JoinClause $join): JoinClause => $join
                ->on('summary.workspace_id', '=', 'live.workspace_id')
                ->on('summary.conversation_key', '=', 'live.conversation_key'))
            ->where(fn (QueryBuilder $query): QueryBuilder => $query
                ->whereNull('summary.id')
                ->orWhereColumn('summary.message_count', '!=', 'live.message_count')
                ->orWhereColumn('summary.last_message_at', '!=', 'live.last_message_at'))
            ->select('live.workspace_id', 'live.conversation_key');

        $orphans = EmailConversation::query()->toBase()
            ->when($this->option('workspace'), fn (QueryBuilder $query, string $id): QueryBuilder => $query->where('workspace_id', $id))
            ->whereNotExists(fn (QueryBuilder $query): QueryBuilder => $query->fromSub($live, 'live')
                ->whereColumn('live.workspace_id', 'email_conversations.workspace_id')
                ->whereColumn('live.conversation_key', 'email_conversations.conversation_key'))
            ->select('workspace_id', 'conversation_key');
```

- [ ] **Step 4: Register and queue.** Add the command to the list in
`registerCleanupThatIgnoresTheFeatureFlag()`. The migration copies
`database/migrations/2026_10_20_185932_queue_own_domain_record_removal.php`:

```php
        Artisan::queue('email:backfill-conversations', ['--force' => true])
            ->onQueue('imports')
            ->delay(now()->addMinutes(5))
            ->afterCommit();
```

In the master contracts table, add `resolveStored(Email $email): string` and
`keysToMerge(Email $email): list<string>` to the resolver row, and `--chunk=` to the command row.

- [ ] **Step 5: Run** the Step 2 command and `composer test:arch` (it checks the command name and
the delay). Expected: PASS.

- [ ] **Step 6: Format and commit.** Commit: `feat(email): backfill conversation keys and summaries`

---

### Task 7: Rehearse on anonymised production data

Follow core.md, Database. Record every number in the PR body.

- [ ] **Step 1: Measure first** (read-only, production):

```sql
select max(octet_length(rfc_message_id)) as rfc, max(octet_length(in_reply_to)) as irt from emails;
select count(*) filter (where n > 1)::numeric / nullif(count(*), 0) as multi_mailbox_share
from (select workspace_id, rfc_message_id, count(distinct connected_account_id) n
      from emails where rfc_message_id is not null group by 1, 2) x;
```

Both lengths must be under 2,000 bytes. The second query answers the spec's open question 5.

- [ ] **Step 2: Export.** `pg_dump -s`, `pg_dump -a -t migrations`, then `\copy` with masking:

| Table | Masking |
|---|---|
| `emails` | `subject`, `snippet` null. `rfc_message_id`, `in_reply_to` as `'<' \|\| md5(value) \|\| '>'`. `thread_id`, `provider_message_id` as `md5(value)` |
| `email_participants` | `name` null. `email_address` as `md5(local part) \|\| '@' \|\| md5(domain) \|\| '.example'` |
| `connected_accounts` | tokens and `display_name` null. `email_address` masked the same way |
| `users` | `email` masked the same way, `name` `'User'`, one fixed password hash, two-factor columns null |
| `workspaces`, `workspace_user`, `email_threads` | names and `subject` replaced; the rest as is |

One mask on every address keeps member detection intact.

- [ ] **Step 3: Before state** (scratch database, before `migrate`):

```sql
create table rehearsal_before as
select id, md5(row(workspace_id, user_id, connected_account_id, rfc_message_id, provider_message_id,
                   thread_id, in_reply_to, status, sent_at, deleted_at, updated_at)::text) as fingerprint
from emails;
select count(*) as emails, count(*) filter (where status <> 'draft') as keyable from emails;
select count(*) as threads from email_threads;
```

- [ ] **Step 4: Migrate and run.** `DB_DATABASE=<scratch> php artisan migrate --force`; confirm
only the four new migrations ran and time each. Run
`DB_DATABASE=<scratch> php artisan email:backfill-conversations --force --chunk=20000` in a shell
loop until it stops queueing, timing each chunk. Set the default so a chunk takes under 200 seconds.

- [ ] **Step 5: After state**

```sql
-- expect 0: every non-draft email has a key
select count(*) from emails where status <> 'draft' and conversation_key is null;
-- expect 0: no key spans two workspaces
select count(*) from (select conversation_key from emails where conversation_key is not null
                      group by 1 having count(distinct workspace_id) > 1) x;
-- expect 0: no other column changed
select count(*) from emails e join rehearsal_before b using (id)
where md5(row(e.workspace_id, e.user_id, e.connected_account_id, e.rfc_message_id, e.provider_message_id,
              e.thread_id, e.in_reply_to, e.status, e.sent_at, e.deleted_at, e.updated_at)::text) <> b.fingerprint;
-- expect 0: every reachable conversation has a summary
select count(*) from (
  select distinct e.workspace_id, e.conversation_key from emails e
  join connected_accounts a on a.id = e.connected_account_id and a.deleted_at is null
  where e.status in ('synced', 'sent') and e.sent_at is not null and e.deleted_at is null) k
left join email_conversations c using (workspace_id, conversation_key) where c.id is null;
-- report: conversations against per-mailbox threads, and the largest conversations
select (select count(*) from email_conversations) as conversations, (select count(*) from email_threads) as threads;
select message_count, count(*) from email_conversations group by 1 order by 1 desc limit 10;
```

Conversations must not outnumber threads. Inspect any conversation above 500 messages before merge.

- [ ] **Step 6: Prove the rerun is a no-op.** Rerun with `--force`: expect
`0 email(s) keyed, 0 summary(ies) rebuilt.` Rerun `migrate`: expect `Nothing to migrate.` Drop
the scratch database and delete the export.

## Deploy notes (PR 2)

- No column is dropped, so no `horizon:pause`.
- `ADD COLUMN` takes a brief exclusive lock while sync jobs write. Put the rehearsal time in the
  PR body and deploy between sync ticks if it passes one second.
- A worker that booted before the deploy stores rows without a key. The backfill starts 5
  minutes later, after Horizon restarts, and keys them.
- Watch `imports` until the command stops queueing, then confirm
  `select count(*) from emails where status <> 'draft' and conversation_key is null` is 0.
  PR 4 waits for that.

## PR 2 checklist

- [ ] Every test in Tasks 1 to 6 exists and passes; `composer test:affected` is green.
- [ ] `composer test:arch` passes.
- [ ] The master contracts table lists the added resolver methods and `--chunk`.
- [ ] Rehearsal numbers in the PR body: counts, migration and index times, chunk time,
      conversations against threads, cross-mailbox share, no-op rerun.
- [ ] Nothing reads `email_conversations` yet; PR 5 adds `ConversationsQuery`. There is no null-key shim.

---

# PR 4: automated mail

**Branch:** `feat/email-automated-mail`, from `feat/email-conversation-data` until PR 2 merges,
then rebased on `main`.

**Goal:** Every stored inbound email says why it is automated, or that it is not. A workspace
can mark a sender as a person. History gets a reason from the sender and the `Marketing` label.
Gmail's Primary hint stops overriding the classifier's rules.

## How detection meets today's bulk drop

`StoreEmailJob` keeps dropping `isBulkMail` before the store (`src/Jobs/StoreEmailJob.php:145-149`),
as D32 decided. The detector runs in `StoreEmailAction` on what survives: Gmail Primary mail with
list headers, Microsoft mail with `List-Id`, `Precedence: list` or `Auto-Submitted`, and role
senders on both. It never widens or narrows the drop. The order is the spec's: `list`,
`auto_submitted`, `bulk`, `role_sender`, `provider_category`. A test in `StoreEmailJobTest`
pins the drop.

## File map

| File | Change | Owns |
|---|---|---|
| `database/migrations/2026_11_09_100000_add_automated_reason_to_emails_table.php` | create | the column |
| `database/migrations/2026_11_09_100100_create_automated_sender_overrides_table.php` | create | overrides |
| `database/migrations/2026_11_09_100200_queue_automated_reason_backfill.php` | create | queues the backfill |
| `src/Data/FetchedEmailData.php` | modify | `headers`, `KEPT_HEADERS` |
| `src/Support/RelayedSender.php` | create | the relay rule |
| `src/Services/GmailService.php`, `src/Services/MicrosoftGraphMailService.php` | modify | headers |
| `src/Enums/AutomatedReason.php`, `src/Models/AutomatedSenderOverride.php` | create | enum, model |
| `src/Services/AutomatedMailDetector.php` | create | the reason |
| `src/Models/Email.php`, `src/Actions/StoreEmailAction.php` | modify | write the reason |
| `src/Services/EmailClassifier.php` | modify | Primary hint as fallback |
| `src/Actions/RefreshConversationSummary.php`, `src/Console/Commands/BackfillConversationsCommand.php` | modify | `is_automated` |
| `src/Console/Commands/BackfillAutomatedReasonsCommand.php` | create | the backfill |
| `src/EmailIntegrationServiceProvider.php` | modify | registration |
| `lang/en/filament/pages/email-inbox.php` | modify | `automated_reasons.*` |
| `tests/Feature/EmailIntegration/{GmailMailServiceTest,MicrosoftGraphMailServiceTest,StoreEmailActionTest,StoreEmailJobTest}.php` | modify | behaviour |
| `tests/Feature/EmailIntegration/BackfillAutomatedReasonsCommandTest.php` | create | backfill |

---

### Task 1: Keep the automation headers and share the relay rule

**Files:**
- Create: `src/Support/RelayedSender.php`
- Modify: `src/Data/FetchedEmailData.php`, `src/Services/GmailService.php:145-163` and `476-515`, `src/Services/MicrosoftGraphMailService.php:148-166`

**Interfaces:**
- Produces: `FetchedEmailData::KEPT_HEADERS = ['list-unsubscribe', 'list-id', 'precedence', 'auto-submitted']`,
  `FetchedEmailData::$headers` (`array<string, string>`, lowercase keys, last argument, default `[]`),
  `RelayedSender::matches(array $participants, bool $hasListUnsubscribe): bool`.

- [ ] **Step 1: Write the failing tests.** Add `mutates(RelayedSender::class);` to
`GmailMailServiceTest.php` and append:

```php
it('keeps the headers that mark automated mail', function (): void {
    $data = fetchGmailMessageFromHeader('Ana <ana@acme.example>', extraHeaders: [
        'List-Id' => '<sales.acme.example>',
        'Auto-Submitted' => 'auto-replied',
        'X-Mailer' => 'Outlook',
    ]);

    expect($data->headers)->toBe(['list-id' => '<sales.acme.example>', 'auto-submitted' => 'auto-replied']);
});
```

Append to `MicrosoftGraphMailServiceTest.php`:

```php
it('keeps the headers that mark automated mail from Graph', function (): void {
    Http::fake([
        ...graphWellKnownFolderFakes(),
        'https://graph.microsoft.com/v1.0/me/messages/MSG1*' => Http::response(graphMessagePayload([
            'internetMessageHeaders' => [['name' => 'Precedence', 'value' => 'list'], ['name' => 'Received', 'value' => 'from mx']],
        ])),
    ]);

    expect(resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount())->fetchMessage('MSG1')->headers)
        ->toBe(['precedence' => 'list']);
});
```

The relay tests at `GmailMailServiceTest.php:939-962` guard the move.

- [ ] **Step 2: Run and watch them fail.** `php artisan test --compact tests/Feature/EmailIntegration --filter='(GmailMailServiceTest|MicrosoftGraphMailServiceTest)::.*automated mail'`.
Expected: FAIL, undefined property `$headers`.

- [ ] **Step 3: Implement.** Add the constant and `public array $headers = [],` to the DTO.
Gmail's last argument:

```php
            headers: $headers->only(FetchedEmailData::KEPT_HEADERS)
                ->map(fn (MessagePartHeader $header): string => (string) $header->getValue())
                ->all(),
```

Move `isRelayedForAnotherSender()` and `registrableDomainOf()` into `Support\RelayedSender`,
which takes `PublicSuffixList` in its constructor. Line 162 becomes
`isBulkMail: $this->isBulkCategory($labelIds) && ! resolve(RelayedSender::class)->matches($participants, $headers->has('list-unsubscribe')),`.
Graph's last argument:

```php
            headers: collect($message['internetMessageHeaders'] ?? [])
                ->mapWithKeys(fn (array $header): array => [Str::lower((string) ($header['name'] ?? '')) => (string) ($header['value'] ?? '')])
                ->only(FetchedEmailData::KEPT_HEADERS)
                ->all(),
```

- [ ] **Step 4: Run** both files in full. Expected: PASS, the relay tests included.

- [ ] **Step 5: Format and commit.** Commit: `feat(email): keep the headers that mark automated mail`

---

### Task 2: Store why mail is automated

**Files:**
- Create: the column and overrides migrations, `src/Enums/AutomatedReason.php`, `src/Models/AutomatedSenderOverride.php`, `src/Services/AutomatedMailDetector.php`
- Modify: `src/Models/Email.php:74-99` and `517-534`, `src/Actions/StoreEmailAction.php:62-75`, `lang/en/filament/pages/email-inbox.php`

**Interfaces:**
- Produces: `AutomatedReason` (`List = 'list'`, `AutoSubmitted = 'auto_submitted'`, `Bulk = 'bulk'`,
  `RoleSender = 'role_sender'`, `ProviderCategory = 'provider_category'`).
  `AutomatedMailDetector::detect(FetchedEmailData $data, string $workspaceId): ?AutomatedReason` and
  `detectStored(Email $email, bool $labelledMarketing): ?AutomatedReason` for the backfill.

- [ ] **Step 1: Write the failing tests.** Add `headers: $overrides['headers'] ?? [],` and
`isBulkMail: $overrides['isBulkMail'] ?? false,` to `makeFetchedEmailData()`, then:

```php
it('stores why inbound mail is automated', function (array $overrides, ?AutomatedReason $reason): void {
    $email = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData($overrides));

    expect($email->refresh()->automated_reason)->toBe($reason);
})->with([
    'list unsubscribe' => [['headers' => ['list-unsubscribe' => '<https://x.example/u>']], AutomatedReason::List],
    'list id' => [['headers' => ['list-id' => '<team.acme.example>']], AutomatedReason::List],
    'auto reply' => [['headers' => ['auto-submitted' => 'auto-replied']], AutomatedReason::AutoSubmitted],
    'auto submitted no' => [['headers' => ['auto-submitted' => ' No ']], null],
    'precedence list' => [['headers' => ['precedence' => 'list']], AutomatedReason::Bulk],
    'precedence first-class' => [['headers' => ['precedence' => 'first-class']], null],
    'role sender' => [['participants' => [['email_address' => 'noreply@stripe.example', 'name' => null, 'role' => 'from']]], AutomatedReason::RoleSender],
    'provider category' => [['isBulkMail' => true, 'participants' => [['email_address' => 'news@brand.example', 'name' => null, 'role' => 'from']]], AutomatedReason::ProviderCategory],
    'list beats role sender' => [['headers' => ['list-id' => '<x>'], 'participants' => [['email_address' => 'noreply@x.example', 'name' => null, 'role' => 'from']]], AutomatedReason::List],
    'a person' => [[], null],
]);

it('never marks mail the mailbox sent as automated', function (): void {
    $email = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'direction' => EmailDirection::OUTBOUND, 'folder' => EmailFolder::Sent, 'headers' => ['list-id' => '<x>'],
    ]));

    expect($email->refresh()->automated_reason)->toBeNull();
});

it('does not hide a form notification relayed for a visitor', function (): void {
    $email = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'participants' => [
            ['email_address' => 'noreply@forms.example', 'name' => null, 'role' => 'from'],
            ['email_address' => 'polly@5forests.example', 'name' => null, 'role' => 'reply_to'],
        ],
    ]));

    expect($email->refresh()->automated_reason)->toBeNull();
});

it('honours only this workspace marking a sender as a person', function (): void {
    AutomatedSenderOverride::query()->create(['workspace_id' => $this->workspace->getKey(), 'email_address' => 'NoReply@Founder.example']);
    AutomatedSenderOverride::query()->create([
        'workspace_id' => User::factory()->withWorkspace()->create()->currentWorkspace->getKey(),
        'email_address' => 'noreply@stripe.example',
    ]);
    $from = fn (string $address): array => ['participants' => [['email_address' => $address, 'name' => null, 'role' => 'from']]];

    $founder = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        ...$from('noreply@founder.example'), 'providerMessageId' => 'f', 'rfcMessageId' => '<f@x>', 'headers' => ['auto-submitted' => 'auto-generated'],
    ]));
    $stripe = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        ...$from('noreply@stripe.example'), 'providerMessageId' => 's', 'rfcMessageId' => '<s@x>',
    ]));

    expect($founder->refresh()->automated_reason)->toBeNull()
        ->and($stripe->refresh()->automated_reason)->toBe(AutomatedReason::RoleSender);
});
```

Append to `StoreEmailJobTest.php`, faking the mail factory the way the test at line 111 does
(extract its fake into a `fakeMailServiceReturning(ConnectedAccount, FetchedEmailData)` helper if
none exists, and point that test at it):

```php
it('still drops bulk mail before the detector sees it', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    fakeMailServiceReturning($account, new FetchedEmailData(...[...get_object_vars(inboundFetchedEmail('bulk-1')), 'isBulkMail' => true]));

    new StoreEmailJob($account, 'bulk-1')->handle(resolve(MailServiceFactoryInterface::class), resolve(StoreEmailAction::class));

    expect(Email::query()->where('provider_message_id', 'bulk-1')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run and watch them fail.** `php artisan test --compact tests/Feature/EmailIntegration --filter='(StoreEmailActionTest|StoreEmailJobTest)::.*(automated|relayed|as a person|bulk mail before)'`.
Expected: FAIL, `Class "...AutomatedReason" not found`. The drop test passes already; it pins
behaviour.

- [ ] **Step 3: Migrations.** `$table->string('automated_reason', 32)->nullable();` on `emails`, and:

```php
Schema::create('automated_sender_overrides', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    TenantMigration::addForeignKey($table);
    $table->string('email_address');
    $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
    $table->timestamp('created_at');

    $table->unique(['workspace_id', 'email_address']);
});
```

- [ ] **Step 4: Enum, model, detector.** `AutomatedReason` implements `HasLabel`; each case maps
to `__('filament/pages/email-inbox.automated_reasons.<value>')` in an exhaustive `match`. Labels:
"Mailing list", "Automatic reply", "Bulk mail", "Sent from a no-reply address", "Filed as
promotions by Gmail". `AutomatedSenderOverride`: `HasUlids`, `HasWorkspace`,
`#[Fillable(['workspace_id', 'email_address', 'user_id'])]`, `const UPDATED_AT = null;`, and an
`email_address` mutator (`Attribute::set()`) that lowercases.

```php
final readonly class AutomatedMailDetector
{
    private const array BULK_PRECEDENCE = ['bulk', 'junk', 'list'];

    public function __construct(private AutomatedSenderMatcher $roleSenders, private RelayedSender $relayed) {}

    public function detect(FetchedEmailData $data, string $workspaceId): ?AutomatedReason
    {
        $sender = $this->sender($data->participants);

        if ($data->direction === EmailDirection::OUTBOUND || $this->isPerson($workspaceId, $sender)) {
            return null;
        }

        $headers = array_map(fn (string $value): string => Str::lower(trim($value)), $data->headers);

        return match (true) {
            isset($headers['list-unsubscribe']) || isset($headers['list-id']) => AutomatedReason::List,
            isset($headers['auto-submitted']) && $headers['auto-submitted'] !== 'no' => AutomatedReason::AutoSubmitted,
            in_array($headers['precedence'] ?? '', self::BULK_PRECEDENCE, true) => AutomatedReason::Bulk,
            default => $this->bySender($data->participants, $sender, $data->isBulkMail),
        };
    }

    public function detectStored(Email $email, bool $labelledMarketing): ?AutomatedReason
    {
        $participants = $email->participants->map(fn (EmailParticipant $participant): array => $participant->only(['email_address', 'name', 'role']))->all();
        $sender = $this->sender($participants);

        if ($email->direction === EmailDirection::OUTBOUND || $this->isPerson($email->workspace_id, $sender) || $this->relayed->matches($participants, false)) {
            return null;
        }

        return $this->bySender($participants, $sender, false) ?? ($labelledMarketing ? AutomatedReason::RoleSender : null);
    }

    /** @param  array<int, array{email_address: string, name: string|null, role: string}>  $participants */
    private function bySender(array $participants, ?string $sender, bool $isBulkMail): ?AutomatedReason
    {
        return match (true) {
            $this->relayed->matches($participants, false) => null,
            $sender !== null && $this->roleSenders->matches($sender) => AutomatedReason::RoleSender,
            $isBulkMail => AutomatedReason::ProviderCategory,
            default => null,
        };
    }

    private function isPerson(string $workspaceId, ?string $sender): bool
    {
        return $sender !== null && AutomatedSenderOverride::query()
            ->where('workspace_id', $workspaceId)
            ->where('email_address', $sender)
            ->exists();
    }

    /** @param  array<int, array{email_address: string, name: string|null, role: string}>  $participants */
    private function sender(array $participants): ?string
    {
        $from = collect($participants)->firstWhere('role', EmailParticipantRole::FROM->value);

        return $from === null ? null : Str::lower($from['email_address']);
    }
}
```

- [ ] **Step 5: Write the reason.** `Email`: fillable `automated_reason`, cast
`AutomatedReason::class`, docblock property. `StoreEmailAction` create array:
`'automated_reason' => resolve(AutomatedMailDetector::class)->detect($data, $connectedAccount->workspace_id),`.
Run `rg -n AutomatedReason packages/SystemAdmin` to confirm no `match` needs a sweep.

- [ ] **Step 6: Run** the Step 2 command, then both files in full. Expected: PASS.

- [ ] **Step 7: Format and commit.** Commit: `feat(email): store why mail is automated`

---

### Task 3: Stop the Gmail Primary hint from beating the rules

**Files:**
- Modify: `src/Services/EmailClassifier.php:10-19` and `43-63`

- [ ] **Step 1: Write the failing test** in `StoreEmailActionTest.php`:

```php
it('lets a pricing subject beat the Gmail primary hint', function (): void {
    $email = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'subject' => 'Pricing for 40 seats',
        'providerCategory' => EmailCategory::Personal,
    ]));

    $this->assertDatabaseHas('email_labels', ['email_id' => $email->getKey(), 'label' => EmailCategory::Sales->value]);
});
```

The existing test at line 108 ("trusts the provider's native category") keeps passing: no rule
matches its subject, so the hint still decides.

- [ ] **Step 2: Run.** `php artisan test --compact tests/Feature/EmailIntegration/StoreEmailActionTest.php --filter='primary hint|native category'`.
Expected: the new test FAILS with `Personal`.

- [ ] **Step 3: Implement.** Delete the early return at lines 45 to 48. Add
`$data->providerCategory instanceof EmailCategory => $data->providerCategory,` to the `match`
between the Marketing arm and `$isInternal`. Fix the class docblock's order.

- [ ] **Step 4: Run** the Step 2 command and `EmailThreadSummaryServiceTest.php`. Expected: PASS.
Commit: `fix(email): let classifier rules beat the gmail primary hint`

---

### Task 4: Keep automated mail out of reply timing

**Files:**
- Modify: `src/Actions/RefreshConversationSummary.php`, `src/Console/Commands/BackfillConversationsCommand.php`

- [ ] **Step 1: Write the failing tests** in `StoreEmailActionTest.php`:

```php
it('keeps automated replies out of the external inbound time', function (): void {
    $this->travelTo(now()->startOfHour());
    $person = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'p', 'rfcMessageId' => '<p@acme.example>', 'sentAt' => now()->subHour(),
    ]));
    resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'a', 'rfcMessageId' => '<a@acme.example>', 'inReplyTo' => '<p@acme.example>',
        'headers' => ['auto-submitted' => 'auto-replied'], 'sentAt' => now(),
    ]));

    expect(EmailConversation::query()->where('conversation_key', $person->conversation_key)->sole())
        ->last_external_inbound_at->equalTo(now()->subHour())->toBeTrue()
        ->is_automated->toBeFalse();
});

it('marks a conversation automated when every message in it is', function (): void {
    $email = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData(['headers' => ['list-id' => '<news>']]));

    expect(EmailConversation::query()->where('conversation_key', $email->conversation_key)->sole()->is_automated)->toBeTrue();
});
```

- [ ] **Step 2: Run** with `--filter='automated replies|every message'`. Expected: FAIL.

- [ ] **Step 3: Implement.** In the totals add
`->selectRaw('bool_and(automated_reason is not null) as is_automated')` and write
`'is_automated' => (bool) $totals->is_automated`. Add `->whereNull('automated_reason')` to
`externalInbound()`. In the command's `$live`, select the same `bool_and` and add
`->orWhereColumn('summary.is_automated', '!=', 'live.is_automated')` to `$stale`.

- [ ] **Step 4: Run** the Step 2 command and `BackfillConversationsCommandTest.php`. Expected: PASS.
Commit: `feat(email): keep automated mail out of reply timing`

---

### Task 5: Backfill automated reasons

**Files:**
- Create: `src/Console/Commands/BackfillAutomatedReasonsCommand.php`, the queue migration, `tests/Feature/EmailIntegration/BackfillAutomatedReasonsCommandTest.php`
- Modify: `src/EmailIntegrationServiceProvider.php:215-218`

**Interfaces:**
- Produces: `email:backfill-automated-reasons {--force} {--before=} {--after=} {--workspace=} {--chunk=20000}`.
  `--force` requires `--before`. It reads inbound, non-draft rows with a null reason created
  before `--before`, with ids above `--after` (the last id a queued run read).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Console\Commands\BackfillAutomatedReasonsCommand;
use Relaticle\EmailIntegration\Enums\AutomatedReason;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Models\AutomatedSenderOverride;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Models\EmailLabel;
use Relaticle\EmailIntegration\Models\EmailParticipant;

mutates(BackfillAutomatedReasonsCommand::class);

beforeEach(function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $this->workspace = $owner->currentWorkspace;
    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->getKey(), 'user_id' => $owner->getKey(),
    ]));
    $this->backfill = fn (array $options = []): mixed => $this->artisan('email:backfill-automated-reasons', ['--force' => true, '--before' => now()->toIso8601String(), ...$options]);
    $this->history = function (string $from, ?EmailCategory $label = null, ?string $replyTo = null): Email {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->account->user_id,
            'connected_account_id' => $this->account->getKey(),
            'created_at' => now()->subMonth(),
            'conversation_key' => hash('sha256', $from),
        ]);
        EmailParticipant::query()->create(['email_id' => $email->getKey(), 'email_address' => $from, 'role' => 'from']);
        if ($replyTo !== null) {
            EmailParticipant::query()->create(['email_id' => $email->getKey(), 'email_address' => $replyTo, 'role' => 'reply_to']);
        }
        if ($label instanceof EmailCategory) {
            EmailLabel::query()->create(['email_id' => $email->getKey(), 'label' => $label->value, 'source' => 'system', 'created_at' => now()]);
        }

        return $email;
    };
});

it('marks role senders and Marketing history, and leaves people alone', function (): void {
    $noreply = ($this->history)('noreply@stripe.example');
    $marketing = ($this->history)('marketing@brand.example', EmailCategory::Marketing);
    $person = ($this->history)('ana@acme.example', EmailCategory::Sales);

    ($this->backfill)()->assertSuccessful();

    expect($noreply->refresh()->automated_reason)->toBe(AutomatedReason::RoleSender)
        ->and($marketing->refresh()->automated_reason)->toBe(AutomatedReason::RoleSender)
        ->and($person->refresh()->automated_reason)->toBeNull()
        ->and(EmailConversation::query()->where('conversation_key', $noreply->conversation_key)->sole()->is_automated)->toBeTrue();
});

it('skips senders marked as a person and relayed form notifications', function (): void {
    AutomatedSenderOverride::query()->create(['workspace_id' => $this->workspace->getKey(), 'email_address' => 'noreply@founder.example']);
    $overridden = ($this->history)('noreply@founder.example');
    $relayed = ($this->history)('noreply@forms.example', EmailCategory::Marketing, 'polly@5forests.example');

    ($this->backfill)()->assertSuccessful();

    expect($overridden->refresh()->automated_reason)->toBeNull()
        ->and($relayed->refresh()->automated_reason)->toBeNull();
});

it('leaves mail stored after the cutoff alone and changes nothing on a rerun', function (): void {
    $old = ($this->history)('noreply@stripe.example');
    $fresh = ($this->history)('marketing@brand.example', EmailCategory::Marketing);
    $fresh->forceFill(['created_at' => now()->addMinute()])->saveQuietly();

    ($this->backfill)()->assertSuccessful();
    ($this->backfill)()->expectsOutputToContain('0 email(s) marked')->assertSuccessful();

    expect($old->refresh()->automated_reason)->toBe(AutomatedReason::RoleSender)
        ->and($fresh->refresh()->automated_reason)->toBeNull();
});

it('reports without writing', function (): void {
    $email = ($this->history)('noreply@stripe.example');

    $this->artisan('email:backfill-automated-reasons', ['--before' => now()->toIso8601String()])
        ->expectsOutputToContain('1 email(s) would be marked')
        ->assertSuccessful();

    expect($email->refresh()->automated_reason)->toBeNull();
});
```

- [ ] **Step 2: Run and watch them fail.** Expected: the command does not exist.

- [ ] **Step 3: Write the command.** Same shape as `BackfillConversationsCommand`. The loop:

```php
        $write = (bool) $this->option('force');
        $budget = max(1, (int) $this->option('chunk'));
        [$marked, $read, $lastId] = [0, 0, null];
        /** @var array<string, array<string, true>> $touched */
        $touched = [];

        foreach ($this->candidates()->lazyById(500) as $email) {
            if ($write && $read === $budget) {
                $this->refreshSummaries($touched);
                $this->queueRest(after: (string) $lastId);

                return self::SUCCESS;
            }

            [$read, $lastId] = [$read + 1, $email->getKey()];
            $reason = $detector->detectStored($email, $email->labels->contains('label', EmailCategory::Marketing->value));

            if (! $reason instanceof AutomatedReason) {
                continue;
            }

            $marked++;

            if ($write) {
                $email->timestamps = false;
                $email->automated_reason = $reason;
                $email->saveQuietly();
                $touched[$email->workspace_id][(string) $email->conversation_key] = true;
            }
        }
```

`candidates()` is `Email::query()->withoutGlobalScopes()` with
`with(['participants', 'labels' => fn (HasMany $query): HasMany => $query->where('source', 'system')])`,
`whereNull('automated_reason')`, inbound, not draft, `created_at < Date::parse($before)`, and the
`--after` and `--workspace` filters. `lazyById` walks ids in order, so `--after` never skips a
row. `refreshSummaries()` calls `RefreshConversationSummary` per touched key inside
`CurrentWorkspace::within()`, skipping an empty key. `queueRest(string $after)` re-queues with
`--force`, `--before`, `--after`, `--workspace` and `--chunk` on `imports`, one minute later.
Print `"{$marked} email(s) marked."` or `"{$marked} email(s) would be marked. Re-run with --force to write them."`.
`--force` without `--before` prints an error and returns `self::FAILURE`.

- [ ] **Step 4: Register and queue.** Register next to `BackfillConversationsCommand`. Migration:

```php
        Artisan::queue('email:backfill-automated-reasons', [
            '--force' => true,
            '--before' => now()->addMinutes(5)->toIso8601String(),
        ])->onQueue('imports')->delay(now()->addMinutes(5))->afterCommit();
```

The cutoff sits at the job's start, so rows a pre-deploy worker stored without a reason are
covered.

- [ ] **Step 5: Run** the test file and `composer test:arch`. Expected: PASS.

- [ ] **Step 6: Format and commit.** Commit: `feat(email): backfill automated reasons for stored mail`

---

### Task 6: Rehearse on anonymised production data

- [ ] **Step 1: Export.** Production must show zero unkeyed non-draft emails first. Export as in
PR 2, Task 7, plus `email_labels` (`email_id`, `label`, `source`, `created_at`), `emailables`
(`email_id`, `emailable_type`, `emailable_id`) and `email_conversations` as is. Keep role local
parts readable so the role rule still works, on every address column:

```sql
case when split_part(lower(email_address), '@', 1)
          ~ '(no-?reply|no_reply|donotreply|do-not-reply|notice|notification|newsletter|mailer|bounce|postmaster|auto-?reply|automated|marketing)'
     then split_part(lower(email_address), '@', 1)
     else md5(split_part(lower(email_address), '@', 1)) end
|| '@' || md5(split_part(lower(email_address), '@', 2)) || '.example'
```

Hashed domains lose subdomain links, so some relayed mail counts as plain role senders here.
Report that count apart.

- [ ] **Step 2: Before state**

```sql
create table rehearsal_before as
select id, md5(row(workspace_id, conversation_key, rfc_message_id, thread_id, status, sent_at, updated_at)::text) as fingerprint from emails;
select l.label, count(*) from emails e join email_labels l on l.email_id = e.id and l.source = 'system'
where e.direction = 'inbound' group by 1 order by 2 desc;
select count(*) filter (where is_automated) as automated, count(*) as conversations from email_conversations;
```

- [ ] **Step 3: Migrate and run.** Only the three new migrations run. Loop
`email:backfill-automated-reasons --force --before=<now>`, passing `--after` from each run, and
time each chunk.

- [ ] **Step 4: After state**

```sql
select automated_reason, count(*) from emails group by 1 order by 2 desc;
-- expect 0
select count(*) from emails where automated_reason is not null and direction = 'outbound';
-- expect 0: no other column changed
select count(*) from emails e join rehearsal_before b using (id)
where md5(row(e.workspace_id, e.conversation_key, e.rfc_message_id, e.thread_id, e.status, e.sent_at, e.updated_at)::text) <> b.fingerprint;
-- Marketing left unmarked: expect relayed or overridden mail only
select count(*) from emails e join email_labels l on l.email_id = e.id and l.source = 'system' and l.label = 'Marketing'
where e.direction = 'inbound' and e.automated_reason is null;
-- report: automated share of linked mail (D8, D32), and summaries that became automated
select count(*) filter (where automated_reason is not null)::numeric / count(*)
from emails e where exists (select 1 from emailables x where x.email_id = e.id);
select count(*) filter (where is_automated) as automated, count(*) as conversations from email_conversations;
```

- [ ] **Step 5: Prove the rerun is a no-op.** Rerun with the same `--before`: expect
`0 email(s) marked.` Rerun `migrate`: `Nothing to migrate.` Run `email:backfill-conversations --force`:
expect `0 email(s) keyed, 0 summary(ies) rebuilt.`, which shows both commands agree on
`is_automated`. Drop the scratch database and delete the export.

## Deploy notes (PR 4)

- Deploy only after PR 2's backfill shows zero unkeyed non-draft emails.
- Additive column and a new table; no `horizon:pause`.
- The backfill starts 5 minutes after `migrate` on `imports` and re-queues until done.

## PR 4 checklist

- [ ] Every test in Tasks 1 to 5 exists and passes; `composer test:affected` is green.
- [ ] `rg -n AutomatedReason packages/SystemAdmin` shows nothing to sweep.
- [ ] The relay tests at `GmailMailServiceTest.php:939-962` pass unchanged.
- [ ] Rehearsal numbers in the PR body: reasons by count, relayed exceptions, Marketing left
      unmarked, summaries that became automated, chunk time, no-op rerun.
- [ ] The PR body names PR 5's follow-up: `MarkSenderAsPerson` and the "Not automated" control.
