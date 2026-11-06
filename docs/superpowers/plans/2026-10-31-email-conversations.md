# Email Conversations Implementation Plan (master)

> **For agentic workers:** REQUIRED SUB-SKILL: use the `sdd-lean` skill to implement each part plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The `Emails` page becomes a CRM Conversations list with record context, a non-modal
drawer reader, keyboard triage, full-text search, linking, sync health and Rela cards, as the
spec defines.

**Spec:** `docs/superpowers/specs/2026-10-31-email-conversations-design.md`. Decisions D1 to D32
are reasoned in `docs/email/decisions/`. When the spec and a part plan disagree, the spec wins;
fix the plan.

**Tech stack:** Laravel 12 on PHP 8.5, Filament v5, Livewire v4, Alpine, Tailwind v4, Pest 5,
PostgreSQL 17 (`pg_trgm`), Horizon, Reverb, laravel/ai in `packages/Chat`.

## Part plans

| Part | File | PRs |
|---|---|---|
| A | [2026-10-31-email-conversations-a-foundations.md](2026-10-31-email-conversations-a-foundations.md) | 1 polish, 3 privacy rule, 10 sync health |
| B | [2026-10-31-email-conversations-b-data.md](2026-10-31-email-conversations-b-data.md) | 2 conversation data, 4 automated mail |
| C | [2026-10-31-email-conversations-c-list-and-drawer.md](2026-10-31-email-conversations-c-list-and-drawer.md) | 5 Conversations page, 6 drawer |
| D | [2026-10-31-email-conversations-d-triage-search-live.md](2026-10-31-email-conversations-d-triage-search-live.md) | 7 triage and keyboard, 8 search, 11 live updates |
| E | [2026-10-31-email-conversations-e-linking-sharing.md](2026-10-31-email-conversations-e-linking-sharing.md) | 9 linking, 13 sharing and access |
| F | [2026-10-31-email-conversations-f-composer-rela.md](2026-10-31-email-conversations-f-composer-rela.md) | 12 composer, 14 Rela in the reader |

## Sequencing

```
week 1   PR1 polish ─┐   PR2 data ─────┐   PR3 privacy rule   PR10 health
week 2               │   PR4 automated ┤
week 3               └──────────────── PR5 Conversations (flagged) ── PR8 search
week 4                                 PR6 drawer ── PR11 live
week 5                                 PR7 triage   PR9 linking   PR12 composer
week 6                                 PR13 sharing and access      PR14 Rela
```

Hard dependencies: PR4 needs PR2. PR5 needs PR2, PR3 and PR4. PR6, PR8 and PR11 need PR5.
PR7, PR9, PR12 and PR13 need PR6. PR14 needs PR6 and PR12. PR1, PR2, PR3 and PR10 start at once.

Each PR is a branch from current `main`, named `feat/email-<slug>` (for example
`feat/email-conversation-data`). A PR that depends on an unmerged PR branches from it and is
rebased when the parent merges.

## Global constraints

Every part plan repeats only what it adds to these.

- **Postgres only.** Migrations have `up()` only. Datetimes are UTC, written from PHP `now()`.
  Never `useCurrent()` or `DB::raw('now()')`.
- **Rehearse every migration that touches `emails`, `email_participants` or
  `connected_accounts` on anonymised production data** before merge, per core.md, Database. The
  rehearsal notes go in the PR body: row counts before and after, run time, lock time.
- **Backfills that need app code are commands.** They report by default, write on `--force`, are
  chunked with `eachById`, rerun safely, and are queued from a migration with
  `Artisan::queue($command, ['--force' => true])->onQueue('imports')->delay(now()->addMinutes(5))->afterCommit()`.
- **Actions** are `final readonly`, one public `execute()`, named verb first with no `Action`
  suffix, authorize and check workspace ownership inside. Eloquent writes never sit in Livewire,
  Filament or chat tools (`EloquentWriteOutsideActionRule`).
- **Queries** are `final readonly` in `packages/EmailIntegration/src/Queries`, take the acting
  user, read no ambient user, request or workspace, and never write. Add the folder to the
  `phpstan.neon` write ban if missing.
- **Workspace scope fails closed.** Jobs and commands that start unbound run inside
  `CurrentWorkspace::within($workspace, fn () => ...)`.
- **Package boundary.** `App\Http`, `App\Jobs`, `App\Policies`, `App\ActivityLog` and
  `App\Console` never use `Relaticle\EmailIntegration`. Anything the app reaches checks the
  `EmailIntegration` feature flag.
- **Copy.** Every user-facing string through `__()`. No em-dash (U+2014) anywhere. Record words:
  company, person, people, opportunity, workspace. The metadata tier's label becomes
  "Who and when" in PR13 only.
- **Comments.** A non-obvious why, two lines max. No comments in tests. Docblocks carry types.
- **Dates** are `CarbonImmutable`. Never name the mutable `Carbon`. Tests steer time with
  `$this->travelTo()`.
- **Methods** stay under 60 lines (`MethodLengthRule`).
- **Model and job configuration** uses attributes: `#[Fillable]`, `#[Table]`, `#[Tries]`,
  `#[Timeout]`, `#[UniqueFor]`.
- **Tests** go through real entry points in `tests/Feature/EmailIntegration` and
  `tests/Browser/EmailIntegration`. Extend existing files first. Each file declares `mutates(...)`.
  No `tests/Unit`. Browser tests run `->with(['inLightMode', 'inDarkMode'])` and once in WebKit
  (`--browser safari`) for every surface the PR changes.
- **UI verification.** Every visual change is clicked through with agent-browser at 1440 and
  390 px, light and dark, empty state included, before the PR is marked ready. Screenshots go in
  the PR body.
- **Chat tools.** A new tool takes batch input, needs a `ProposalEntity` case when it writes, a
  `toolLabels` entry in `chat-interface.blade.php`, and passes
  `php artisan test tests/Browser/Chat/LoadingShimmerTest.php`. `SurfaceParityTest` must pass.
- **Enum cases.** When adding or removing a case, sweep `packages/SystemAdmin` for `match` over
  that enum (it is outside PHPStan).

### Loop per task

1. `vendor/bin/pint --dirty --format agent`
2. `composer test:affected`, or `php artisan test --compact <paths>` when it refuses.

### Once per PR, before pushing

3. `vendor/bin/rector --dry-run` (apply with `vendor/bin/rector` if it suggests changes)
4. `vendor/bin/phpstan analyse`
5. `composer test:lint`
6. `composer test:arch`

Never run `composer test:pest`, `test:pest:full`, `test:type-coverage` or `test:browser`
locally to confirm a push. CI runs them. After the push, watch the `Tests` workflow:
`gh run watch --exit-status $(gh run list --branch <branch> --workflow Tests --limit 1 --json databaseId --jq '.[0].databaseId')`.

Commits: conventional, lowercase, present tense, subject under 72 characters, scope `email`
(for example `feat(email): store a conversation key on every email`), no AI attribution.

## Cross-PR contracts

Part plans must use these names and shapes exactly. A part that needs to change one updates
this table in the same PR.

### Columns and tables

| Owner PR | Name | Shape |
|---|---|---|
| 2 | `emails.conversation_key` | `string(64)` nullable, index `(workspace_id, conversation_key)` |
| 2 | `emails.references` | `text` nullable |
| 2 | `email_conversations` | `id` ULID, `workspace_id`, `conversation_key` (unique with workspace), `message_count`, `first_message_at`, `last_message_at`, `last_external_inbound_at` nullable, `last_member_outbound_at` nullable, `has_attachments`, `is_automated`, `created_at`, `updated_at` |
| 4 | `emails.automated_reason` | `string(32)` nullable |
| 4 | `automated_sender_overrides` | `id`, `workspace_id`, `email_address` (unique with workspace), `user_id`, `created_at` |
| 7 | `email_conversation_states` | `id`, `workspace_id`, `user_id`, `conversation_key`, `done_at`, `done_through` (nullable: the earlier of the `last_external_inbound_at` the viewer's row showed and the summary's value at the mark, null when either is null; the factory sets `now()`), timestamps; unique `(workspace_id, user_id, conversation_key)`; model `EmailConversationState`; no job clears a row, a newer external inbound outranks it |
| 7 | `users.single_key_shortcuts` | bool default true; written only by `App\Actions\User\UpdateKeyboardShortcuts::execute(User $user, bool $enabled): void` from the Profile toggle (`App\Livewire\App\Profile\UpdateKeyboardShortcuts`), which saves on change and updates `[data-single-key-shortcuts]` plus `$store.shortcuts.readPreference()` without a navigation |
| 8 | `emails.subject_search`, `emails.body_search` | `tsvector` nullable, GIN, trigger-maintained, `simple` config |
| 9 | `email_link_suppressions` | `id`, `workspace_id`, `email_id` (cascade), `emailable_type`, `emailable_id`, `user_id` (nullable, null on delete, so an unlink outlives the user who made it), `created_at`; unique `(email_id, emailable_type, emailable_id)`, index `(workspace_id, emailable_type, emailable_id)`; model `EmailLinkSuppression` with `#[Scope] matchingConversationOf(Email)`: rows on any copy with the same `conversation_key`, the same `rfc_message_id`, or the email itself |
| 10 | `connected_accounts.health`, `health_reason`, `health_changed_at`, `health_notified_at` | `string(24)` default `healthy`, `string(64)` nullable, timestamps nullable |
| 12 | `emails.draft_version` | integer default 0 |
| 12 | `users.email_undo_seconds` | smallint default 10; replaces `undo_send_window_seconds` config |
| 12 | `email_follow_ups` | follow-up task that closes itself on an external reply (part F) |
| 13 | `connected_accounts.sharing_confirmed_at` | timestamp nullable |
| 13 | `email_access_requests.note` | `string(500)` nullable, plus a partial unique index for record requests |
| 14 | `ai_summaries.user_id` | per-viewer summaries; unique key widens to include it |
| 14 | `agent_conversations.email_conversation_key` | ties a chat conversation to an email conversation |

### Classes

| Owner PR | Class | Contract |
|---|---|---|
| 2 | `Relaticle\EmailIntegration\Services\ConversationKeyResolver` | `resolve(FetchedEmailData $data, ConnectedAccount $account): string`, `resolveStored(Email $email): string` (app sends, backfill), `keysToMerge(Email $email): list<string>` |
| 2, 4 | `email:backfill-*` commands | take `--chunk=` and re-queue themselves within the 300 s `imports` limit; registered outside the feature flag (`EmailIntegrationServiceProvider.php:215-218`) |
| 3 | `Email::isLinkedToRecord()`, scope `Email::withRecordLinkState()` | linked-only rule without one query per row |
| 3 | linked-only condition | on the default branch only (`VisibleEmailScope.php:106-120`, `PrivacyService.php:58-65`); explicit shares and the viewer's own copy stay visible |
| 3 | `ApplyDefaultSharingTierToExistingEmailsAction` | never widens past mail, raising or lowering; PR 13 only adds the notice |
| 2 | `Relaticle\EmailIntegration\Actions\RefreshConversationSummary` | `execute(string $workspaceId, string $conversationKey, bool $broadcast = true, list<string> $alsoNotify = []): void`, idempotent; dispatches `ConversationChanged` with `$alsoNotify` unless a backfill passes `broadcast: false` (PR 11) |
| 2 | `Relaticle\EmailIntegration\Jobs\MergeConversations` | `(string $workspaceId, string $emailId)`, unique per workspace and email until processing; merges every key linked to the email into the oldest |
| 2 | `Relaticle\EmailIntegration\Models\EmailConversation` | model over `email_conversations`, no text columns |
| 2 | `email:backfill-conversations` | command, `--force`, `--workspace=`, `--chunk=10000` (emails keyed plus summaries rebuilt per run) |
| 4 | `Relaticle\EmailIntegration\Enums\AutomatedReason` | cases `List`, `AutoSubmitted`, `Bulk`, `RoleSender`, `ProviderCategory` |
| 4 | `Relaticle\EmailIntegration\Services\AutomatedMailDetector` | `detect(FetchedEmailData $data, string $workspaceId): ?AutomatedReason` |
| 4 | `email:backfill-automated-reasons` | command |
| 5, 7 | `Relaticle\EmailIntegration\Enums\ConversationView` | `NeedsReply`, `AwaitingReply`, `All`, `Unlinked`, `Automated`, `Done` (PR 7: a secondary tab, automated excluded, ordered like every view by `last_message_at desc, id desc` so the cursor holds); Needs reply excludes conversations Done for the viewer, Done while `last_external_inbound_at <= done_through` or no external inbound exists (send times sync late, so the mark's own timestamp is never compared) |
| 5, 8 | `Relaticle\EmailIntegration\Data\ConversationFilters` | plain `final readonly` data: `mailboxIds`, `direction`, `hasAttachment`, `sentAfter` (inclusive), `sentBefore`, `recordType` with `recordIds` or `recordName`, `categories`, `from`, `to`, `search`, PR 9 `conversationKeys` (null for every conversation, a list for only those; the Unlinked group expansion); PR 8 adds `fromTree(array, string $viewerZone)`, `withTyped(string, string $viewerZone)`, `toTree()` |
| 5 | `Relaticle\EmailIntegration\Data\ConversationPage` | plain `final readonly` data: `list<ConversationRow> $rows`, `?string $nextCursor`, `?string $previousCursor` |
| 5 | `Relaticle\EmailIntegration\Data\ConversationRow` | plain `final readonly` data, fields per spec plus `ownerName`, `senderNames`, `viewerHasPendingRequest`; `access` is an `EmailPrivacyTier`; count and time come from the viewer's visible copies, never the summary row; PR 9: `records` holds every linked company, opportunity and person the viewer may see, sorted company, opportunity, person then name (the spec's "first three" cap is lifted, because the drawer offers unlink on each and the list's "+N" counted only up to three); the list row still shows two chips plus "+N" |
| 5 | `Relaticle\EmailIntegration\Queries\ConversationsQuery` | `paginate(User, ConversationView, ConversationFilters, int $perPage = 50, ?string $cursor = null): ConversationPage` (rows plus encoded `nextCursor` and `previousCursor`, read off the summary models before they become rows), `counts(User, ?ConversationFilters = null): array<string, int>` (each stops at `ConversationView::COUNT_CAP`, shown through `ConversationView::countLabel()`), `count(User, ConversationView, ConversationFilters): int` (one capped count), `contains(User, string $conversationKey, ConversationFilters): bool`, `messages(User, string $conversationKey): Collection<int, Email>`, `viewerCopy(User, Email): ?Email` (the viewer's preferred copy of the same message, shared by the resolver and the drawer; PR 6); PR 7 adds `visibleKeys(User, list<string> $conversationKeys): list<string>` (the keys the viewer may see in their workspace), `conversationRow(User, string $conversationKey): ?ConversationRow` (one row with the list's visibility, for the reader when the open conversation is not on the loaded page) and `reachedCopies(User, list<string> $conversationKeys): Collection<int, object{id, conversation_key, direction}>` (visible copies in the viewer's mailbox or shared to them at full, newest first per key; the same rule as a row's `is_unread`), and every row carries `isDone`, PR 9 `copies(User, Email): Collection<int, Email>` (the delivered copies of the email's conversation the viewer reached in their workspace: their own and those shared to them at full, each mailbox's copy of each message, ordered by id, the email alone when its key is null; no preferred-copy reduction; link and unlink act on these) and routes a record filter naming one record through `Email::onRecord()` (a filter over several records or a name matches stored links through `Email::onRecords()`, with no opportunity window), PR 11 `audience(string $workspaceId, string $conversationKey, list<string> $alsoNotify = []): list<string>` and `audiences(string $workspaceId, list<string> $conversationKeys): array<string, list<string>>` (member ids who may see each conversation, one query per member), `countsCacheKey(User): string` (`counts()` is cached 60 s per viewer, workspace and filters under a generation that forgetting this key drops; `rows()` was not needed: the page re-reads its rows on every round trip). Alias on import: `Relaticle\Chat\Queries\ConversationsQuery` exists |
| 5 | `App\Features\EmailConversations` | Pennant with the `array` store, resolving from a per-workspace id list in config. A workspace joins only after its backfill leaves zero null keys. No null-key shim |
| 5 | `MarkSenderAsPerson` | writes `automated_sender_overrides` from the row |
| 5 | `Email::appUrl()` | one URL builder, pointing at `email.open`; a locked `hostsReader` flag stops two readers opening on `?email=`; PR 6 adds a locked `readerOnRecordPage`, where the bell reloads through `email.open` because the Emails tab may not be mounted |
| 5 | Needs reply business-day threshold | constant `3` until a setting is needed |
| 5 | route `email.open`, `GET /email/open/{email}` | resolver controller in the package |
| 5 | `<x-email-integration::conversation-row>` | replaces `list-row-wide` |
| 6 | `<x-email-integration::reader-drawer>` | replaces `reader-overlay` |
| 7 | `Relaticle\EmailIntegration\Enums\EmailShortcut`, `ShortcutScope` | `keys()`, `scopes()` (`List`, `Reader`, `Composer`, `Global`), `label(?ShortcutScope)` (Esc reads "Clear selection" in `List`), `action()`, `isCharacterKey()`, `firesInEditors()`, `forStore()` (each entry also carries `labels` per scope for the `?` sheet); `resources/js/filament/shortcuts.js` holds the one dispatcher as `Alpine.store('shortcuts')` with `register(name, {scope, entries, handlers, isActive})`, `allows(event, {editors, character})` (also false while a Filament dropdown or select panel is open), `hint(action, label)`, `keysFor(action)`, `liveKeys(entry)`; the off switch acts per pressed key, a one-character key being a character key; `Open` is `o` only, Enter stays with the focused control; `Select` (`x`) toggles the focused row; `Close` (Escape) also runs in `List`, where it clears the selection, and an open reader resolves first; `ConventionsTest` fails two cases sharing a key in overlapping scopes, an `isCharacterKey()` that disagrees with the keys, and a published `public/js/app` script that differs from its source |
| 7 | Actions `MarkConversationsDone`, `ReopenConversations`, `MarkConversationsRead`, `MarkConversationsUnread` | batch input `User $user, array $conversationKeys`, except `MarkConversationsDone::execute(User $user, array $seenThrough)`, a map of conversation key to the ISO UTC `data-last-inbound` its row rendered or null (a value that is not a date fails validation on `keys`; the page method `markDone(array $seenThrough)` and the client's `act` send it for every key, the reader's Done sends the value the drawer renders from `selectedConversationRow`, Undo replays the original map) (at most `PrepareConversationTriage::MAX_KEYS = 100`, de-duplicated), return `Relaticle\EmailIntegration\Data\TriageOutcome` (`appliedKeys`, `skipped` counts per `TriageSkipReason`: `NotAvailable` for keys the viewer cannot see, `NotInYourMailbox` for read and unread with no reached copy); the shared step `PrepareConversationTriage` authorizes and filters through `visibleKeys()`; Done and Reopen forget the actor's `countsCacheKey()`; read marks every reached copy, unread clears the newest reached inbound copy |
| 7 | chat `j`, `k`, `n` | move to a focus scope on the chat list; `single_key_shortcuts` off disables them too |
| 8 | `Relaticle\EmailIntegration\Enums\EmailFilter` | the one email filter owner (an enum, because `Queries` classes must end in `Query`) |
| 8 | `Email::listColumns()` | every `emails` column but `subject_search` and `body_search`; list reads select it, so a page never carries the vectors |
| 8 | search order | recency, not `ts_rank`, so cursor pagination holds; addresses and names match by `ILIKE` on trigram indexes; PR 8 creates `pg_trgm` |
| 9 | Actions `LinkEmailToRecord`, `UnlinkEmailFromRecord` | `execute(User $user, Email $email, Company\|People\|Opportunity $record)` (link returns `bool`, true when it attached, pinned or lifted a suppression on any copy) acting on every copy `ConversationsQuery::copies()` returns; both take the conversation's advisory lock (`Email::lockConversation()`, also taken by `LinkEmailAction`) and read the copies under it; the shared step `PrepareEmailRecordLink` answers 403 unless the user may view the email and update the record, and the record, the email and the user's current workspace match, and throws a `ValidationException` on `email_id` when the user reached no copy; link writes `link_source = manual` (an `auto` row becomes a pin), deletes the record's suppressions on those copies and increments metrics for newly linked copies whose own sync linking already ran (`linked_at` set); unlink detaches, writes one suppression per copy, returns `array<string, 'auto'|'manual'|null>` (each changed copy's link source before, null for a copy it only suppressed) and queues `RecomputeRecordEmailMetricsJob` (unique per record, `emails-import`) after commit, which recounts what the increments counted: linked emails with `linked_at` set, deleted mail and disconnected mailboxes included, so an unlink moves the counters only by what it unlinked (a recount racing a concurrent increment can end one short, accepted); both dispatch `ConversationChanged`, unlink with the audience it had before; bulk Link and the `l` key land here; `RestoreEmailRecordLinks::execute(User, Email, record, array $changed)` is the drawer's Undo: under the same prepare step it lifts the suppressions on the reached copies named in that map and reattaches only the copies that were linked, with their original source, then queues the recount |
| 9 | `Email::onRecord()` | `#[Scope] onRecord(Company\|People\|Opportunity $record)`; owns which mail belongs on a record and the record tabs, their badge and a one-record filter read it. A company or person: a stored `emailables` row for it (raw `exists`, so a trashed record and an unbound workspace still match). An opportunity: a `manual` pin at any date, or a link to it, its `company_id` company or its `contact_id` person sent from `created_at - 30 days` up to `close_date + 30 days` (end only when `close_date` is past, read through `Opportunity::closeDate()`, which looks the close-date field up once per record instance and reads the value off the loaded relation). In both, each matching link must have no suppression for its own record on the same copy, or on another copy of the conversation held by the same user unless a pin on any copy overrides it, and the same holds for a suppression of the opportunity itself; another member's suppression never hides this copy (sync linking still reads suppressions conversation-wide, so later replies stay off). `#[Scope] onRecords(string $morphType, QueryBuilder\|list<string> $recordIds)` is the stored-link half, which a filter naming several records uses: no window |
| 9 | `EmailLinkTargetsQuery`, chat `LinkEmailsTool`/`UnlinkEmailsTool`, MCP `link-emails-tool`/`unlink-emails-tool` | `EmailLinkTargetsQuery::find(User, string $type, string $id): Company\|People\|Opportunity\|null` and `search(User, string $type, string $search): array<string, string>` (limit 20) own the record a link may name: company, people or opportunity only, filtered by the viewer's `current_workspace_id`; the drawer picker, the Unlinked view, the chat adapters `LinkAssistantEmail`/`UnlinkAssistantEmail` (`execute(User, array{email_id, record_type, record_id}): Email`, `ValidationException` on `email_id` or `record_id`) and the MCP tools read it. Chat items and MCP `links[]` items carry the same three keys (`SurfaceParityTest`). `ProposalEntity::EmailLink` (`email_links`) and `EmailUnlink` (`email_unlinks`): indivisible, approved with their plan, own receipt. Chat needs `RecordsUpdate`; MCP needs the `email:draft` grant plus the `update` ability, and checks every pair before it writes any |
| 10 | `Relaticle\EmailIntegration\Enums\MailboxHealth` | `Healthy`, `Stale`, `ReauthRequired`, `Error`, `PausedBilling`; `needsReconnect()`, `notifiesAdminsAtOnce()`, `remedyUrl()` |
| 10 | `email:check-mailbox-health` | command, every 15 minutes, writes through `RecordMailboxHealth`; reconnect also resets health |
| 10 | health basis | `last_synced_at`, which after the fix means last successful sync; SystemAdmin stale threshold moves to 24 hours |
| 11 | `Relaticle\EmailIntegration\Events\ConversationChanged` | `new ConversationChanged(string $workspaceId, string $conversationKey, list<string> $alsoNotify = [])`, broadcast on `App.Models.User.{id}`, payload `workspace_id`, `conversation_key`; dispatched after commit, not unique (the client debounces); audience is `ConversationsQuery::audience()` after the change plus `$alsoNotify`, the members who saw it before; only to members whose current workspace matches; `ConversationChanged::broadcasts()` (feature on and broadcaster not `null`) gates `broadcastWhen()` and every before-write audience read; `ForgetConversationCountsListener` forgets the counts of the workspace's active members, and `SaveMailboxSharingTier` calls it too |
| 12 | unknown `{{ tag }}` | blocked in `SendEmailAction` and `SendEmailBatchAction` too, not only the composer |
| 12 | Undo | valid while `now() < scheduled_for`; turns the email back into a draft in place |
| 13 | bulk Change sharing and Request access | land here |
| 14 | `AiCreditType::Draft`, `MessageOrigin::EmailReview` | sweep `packages/SystemAdmin` `match` statements; summaries use the existing `Summary` type |
| 14 | Chat-owned Livewire components | mounted by alias in the drawer and composer, so EmailIntegration never imports Chat; an arch test pins it |

## Definition of done for the whole project

- Every spec test in "Testing" exists and passes in CI.
- `EmailConversations` is on for every workspace, and the old record list, `list-row-wide`,
  the modal reader and the flag's config id list are deleted.
- The success metrics table in the spec has a 30-day reading.
- `docs/email/findings.md` P0 and P1 items are closed or moved to an issue.

## Calls made while planning (founder may overrule)

1. Teammate replies, even from an unconnected mailbox, clear Needs reply (part B).
2. Merges keep the conversation whose oldest message is older (part B).
3. The category badge takes the enum's colours, so Sales turns red (part A, note 3).
4. Done also turns off chat's focused `j` and `k` when single keys are off (part D, note 3).
5. Rela cards appear only on request; only the summary runs on open, and only on the Emails page
   (part F, note 7). Each card is a paid chat turn.
6. Record-level access requests share current mail only, not future mail (part E, note 11).
7. Public-domain senders stay visible in Unlinked senders (part E, note 18).
8. The "no provider configured" help page is deferred: no docs page covers mailbox setup yet
   (part A, note 4).
