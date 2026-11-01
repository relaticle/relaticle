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
| 7 | `email_conversation_states` | `id`, `workspace_id`, `user_id`, `conversation_key`, `done_at`; unique `(workspace_id, user_id, conversation_key)` |
| 7 | `users.single_key_shortcuts` | bool default true |
| 8 | `emails.subject_search`, `emails.body_search` | `tsvector` nullable, GIN, trigger-maintained, `simple` config |
| 9 | `email_link_suppressions` | `id`, `workspace_id`, `email_id` (cascade), `emailable_type`, `emailable_id`, `user_id`, `created_at`; unique `(email_id, emailable_type, emailable_id)` |
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
| 2 | `Relaticle\EmailIntegration\Services\ConversationKeyResolver` | `resolve(FetchedEmailData $data, ConnectedAccount $account): string`, `resolveStored(Email $email): string` (app sends, backfill), `keysToMerge(Email $email)` |
| 2, 4 | `email:backfill-*` commands | take `--chunk=` and re-queue themselves within the 300 s `imports` limit; registered outside the feature flag (`EmailIntegrationServiceProvider.php:215-218`) |
| 3 | `Email::isLinkedToRecord()`, scope `Email::withRecordLinkState()` | linked-only rule without one query per row |
| 3 | linked-only condition | on the default branch only (`VisibleEmailScope.php:106-120`, `PrivacyService.php:58-65`); explicit shares and the viewer's own copy stay visible |
| 3 | `ApplyDefaultSharingTierToExistingEmailsAction` | never widens past mail, raising or lowering; PR 13 only adds the notice |
| 2 | `Relaticle\EmailIntegration\Actions\RefreshConversationSummary` | `execute(string $workspaceId, string $conversationKey): void`, idempotent |
| 2 | `Relaticle\EmailIntegration\Jobs\MergeConversations` | `(string $workspaceId, string $emailId)`, unique per workspace and email until processing; merges every key linked to the email into the oldest |
| 2 | `Relaticle\EmailIntegration\Models\EmailConversation` | model over `email_conversations`, no text columns |
| 2 | `email:backfill-conversations` | command, `--force`, `--workspace=` |
| 4 | `Relaticle\EmailIntegration\Enums\AutomatedReason` | cases `List`, `AutoSubmitted`, `Bulk`, `RoleSender`, `ProviderCategory` |
| 4 | `Relaticle\EmailIntegration\Services\AutomatedMailDetector` | `detect(FetchedEmailData $data, string $workspaceId): ?AutomatedReason` |
| 4 | `email:backfill-automated-reasons` | command |
| 5 | `Relaticle\EmailIntegration\Enums\ConversationView` | `NeedsReply`, `AwaitingReply`, `All`, `Unlinked`, `Automated`; PR 7 adds `Done` and the "not Done" predicate |
| 5 | `Relaticle\EmailIntegration\Data\ConversationFilters` | plain `final readonly` data, includes `mailbox` and a record scope |
| 5 | `Relaticle\EmailIntegration\Data\ConversationRow` | plain `final readonly` data, fields per spec plus `ownerName`, `senderNames`, `viewerHasPendingRequest`; `access` is an `EmailPrivacyTier`; count and time come from the viewer's visible copies, never the summary row |
| 5 | `Relaticle\EmailIntegration\Queries\ConversationsQuery` | `paginate(User, ConversationView, ConversationFilters, int $perPage = 50, ?string $cursor = null): CursorPaginator`, `counts(User, ?ConversationFilters = null): array<string, int>`, `messages(User, string $conversationKey): Collection<int, Email>`; PR 7 adds `visibleKeys()`, PR 9 `copies()`, PR 11 `rows()` and `countsCacheKey()`. Alias on import: `Relaticle\Chat\Queries\ConversationsQuery` exists |
| 5 | `App\Features\EmailConversations` | Pennant with the `array` store, resolving from a per-workspace id list in config. A workspace joins only after its backfill leaves zero null keys. No null-key shim |
| 5 | `MarkSenderAsPerson` | writes `automated_sender_overrides` from the row |
| 5 | `Email::appUrl()` | one URL builder, pointing at `email.open`; a locked `hostsReader` flag stops two readers opening on `?email=` |
| 5 | Needs reply business-day threshold | constant `3` until a setting is needed |
| 5 | route `email.open`, `GET /email/open/{email}` | resolver controller in the package |
| 5 | `<x-email-integration::conversation-row>` | replaces `list-row-wide` |
| 6 | `<x-email-integration::reader-drawer>` | replaces `reader-overlay` |
| 7 | `Relaticle\EmailIntegration\Enums\EmailShortcut` | keys, scope, label key, action |
| 7 | Actions `MarkConversationsDone`, `ReopenConversations`, `MarkConversationsRead`, `MarkConversationsUnread` | batch input `User $user, array $conversationKeys`, return `TriageOutcome` (done and skipped counts with reasons) |
| 7 | chat `j`, `k`, `n` | move to a focus scope on the chat list; `single_key_shortcuts` off disables them too |
| 8 | `Relaticle\EmailIntegration\Enums\EmailFilter` | the one email filter owner (an enum, because `Queries` classes must end in `Query`) |
| 8 | search order | recency, not `ts_rank`, so cursor pagination holds; addresses and names match by `ILIKE` on trigram indexes; PR 8 creates `pg_trgm` |
| 9 | Actions `LinkEmailToRecord`, `UnlinkEmailFromRecord` | `execute(User $user, Email $email, Model $record)` acting on every visible copy in the conversation; bulk Link and the `l` key land here |
| 9 | `Email::onRecord()` | owns which mail belongs on a record, the opportunity window included |
| 10 | `Relaticle\EmailIntegration\Enums\MailboxHealth` | `Healthy`, `Stale`, `ReauthRequired`, `Error`, `PausedBilling`; `needsReconnect()`, `notifiesAdminsAtOnce()`, `remedyUrl()` |
| 10 | `email:check-mailbox-health` | command, every 15 minutes, writes through `RecordMailboxHealth`; reconnect also resets health |
| 10 | health basis | `last_synced_at`, which after the fix means last successful sync; SystemAdmin stale threshold moves to 24 hours |
| 11 | `Relaticle\EmailIntegration\Events\ConversationChanged` | broadcast on `App.Models.User.{id}`, payload `workspace_id`, `conversation_key`; unique and dispatched after commit; only to members whose current workspace matches |
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
