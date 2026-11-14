# Email Conversations, part F: composer and Rela in the reader (PR 12, PR 14)

> **For agentic workers:** REQUIRED SUB-SKILL: use the `sdd-lean` skill to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The composer stops losing text, sends when the seller means it, and can be taken back. New mail opens in a corner window over the record, drafts save themselves, Send can wait until Monday 8:00 in the sender's zone, Undo gives the text back, variables never reach a buyer blank or raw, and a follow-up task closes itself when the buyer answers. Then Rela joins the reader: a summary line, approvable cards on the proposal pipeline, a facts card that needs no AI key, and "Draft with Rela", which never sends.

**Spec:** `docs/superpowers/specs/2026-10-31-email-conversations-design.md`: "Composer (D15 to D21)" (lines 545 to 573), "The drawer (D3)" item 3 (lines 474 to 480), "Agents (Rela and MCP)", "Migrations and deploy", "Testing", phases rows 12 and 14 (lines 753, 755). Reasoning: `docs/email/decisions/c-composer.md` (D15 to D21), `docs/email/decisions/e-speed-ai-and-later.md` (D30, D31). Master plan: `docs/superpowers/plans/2026-10-31-email-conversations.md`. The spec wins any disagreement; "Plan notes" lists each place this plan had to choose.

**Architecture:** PR 12 stays inside `packages/EmailIntegration` plus one `users` and one `emails` column: a light autosave guarded by `emails.draft_version`, presets as an enum over the sender's zone, an undo window from `users.email_undo_seconds` that Undo honours to the second, one variable catalogue every send path reads, and a follow-up task linked to the sent email. PR 14 puts every AI call in `packages/Chat`. The drawer and the composer mount two Chat-owned Livewire components by alias, so `Relaticle\EmailIntegration` keeps importing nothing from `Relaticle\Chat` (true today; Chat already uses EmailIntegration in 16 files). Cards run as a normal chat turn on a chat conversation anchored to the email conversation, in a review mode with no send tool.

**Tech stack:** Laravel 12, PHP 8.5, Filament v5 RichEditor (Tiptap 3), Livewire v4, Alpine, Pest 5, PostgreSQL 17, Horizon on redis, Reverb, laravel/ai.

## Global constraints

The master plan's constraints apply. This part adds:

- `Relaticle\EmailIntegration` never uses `Relaticle\Chat`. PR 14 adds the arch test.
- Every new enum case is swept in `packages/SystemAdmin` (outside PHPStan), result in the PR body.
- Replayed proposal tool results are never rewritten. A stale card is superseded, never edited.
- Chat work is verified on Horizon with `QUEUE_CONNECTION=redis` and Reverb up, in a real browser.
- No browser-side copy of a draft (no `localStorage`, no `sessionStorage`), so erasure stays complete (D16).
- No em-dash anywhere: code, copy, lang files, commits, PR bodies.

## Plan notes

1. **Undo after the toast still cancels today.** `CancelQueuedEmailAction` accepts any QUEUED row (`packages/EmailIntegration/src/Actions/CancelQueuedEmailAction.php:31-33`); the dispatcher claims due rows once a minute (`bootstrap/app.php:402-406`, claim at `DispatchOutboxCommand.php:136-157`); the toast hides after the config's 5 s (`QueuedSendNotifier.php:24-37`, `config/email-integration.php:92`). So Undo works anywhere from 5 to about 65 s. This plan makes it exact: Undo succeeds only while QUEUED and `now() < scheduled_for`, and the toast lasts the seconds left until `scheduled_for`. Outbox Cancel keeps today's rule.
2. **Undo must give the text back, but the queued row is not a draft.** `send()` deletes the draft before the toast (`EmailComposer.php:489-495`) and appends the reply quote (`EmailComposer.php:703-718`, `Email::quotedBelow()` at `Email.php:355-366`). `UndoQueuedSend` flips the queued row back to DRAFT in place and strips the reply quote, which `quotedBelow()` now marks with `data-relaticle-quote`. Merge values and the signature are already rendered, so the reopened draft shows resolved text, as Gmail does. A forward's quoted section stays in the body.
3. **`undo_send_window_seconds` goes away.** The window belongs to `users.email_undo_seconds` (spec line 558); a config fallback would be a dual path (core.md, Minimal Change). Readers to move: `SendEmailAction.php:263`, `QueuedSendNotifier.php:26`, `OutboxTable.php:230`, `tests/Feature/Chat/EmailToolsTest.php:984, 1721`. The Outbox Queued/Scheduled split uses the longest window (30 s). `EMAIL_UNDO_SEND_WINDOW` is not in `.env.example`; the release note says it is ignored.
4. **The MCP hold is safe by construction; a test pins it.** `HoldAgentEmail` always passes `scheduled_for` from `PickAgentSendTime` (`HoldAgentEmail.php:46-48`, `PickAgentSendTime.php:13-21`: 300 s, 60 s floor), and `resolveScheduledFor()` returns an explicit time before reading any window (`SendEmailAction.php:256-269`). Approved chat sends follow the user's window (`PrepareAgentEmail.php:98`, `SendAssistantEmail.php:33-44`), as D18 decided.
5. **Orphan lang string.** The whole `fields.scheduled_for` block is unused (`lang/en/filament/concerns/email-compose.php:55-58`; nothing reads `email-compose.fields`). PR 12 deletes the block, not only line 57.
6. **Stale docblock.** `persistDraft()` claims to run on minimize (`EmailComposer.php:1899`); `minimize()` saves nothing (`:720-723`). Rewritten in PR 12.
7. **Erasure covers drafts, with one older gap.** User deletion cascades every email with that `user_id`, drafts included (`database/migrations/2026_03_21_050430_create_emails_table.php:19`), after `PurgeStoredMailboxData` gathers their files (`PurgeStoredMailboxData.php:24-60`); mailbox removal cascades on `connected_account_id` (`create_emails_table.php:20`). Composer inline images are deleted only with the workspace (`PurgeStoredMailboxData.php:42-44`); autosave does not change that. Follow-up.
8. **Toolbar: spec versus decision file.** The spec removes alignment (line 569); `c-composer.md:867` moved it under More. Spec wins. Headings, quote, code and strike go under More, since the spec keeps them.
9. **`{` versus Filament's `{{`.** Filament's merge-tag node opens on `{{` and stores only an id (`vendor/filament/forms/resources/js/components/rich-editor/extension-merge-tag.js:15, 61-80`), so it cannot hold a fallback. PR 12 adds an `emailVariable` node in Tiptap JS and PHP, following `app/Filament/RichEditor/SlashMenuPlugin.php` (asset at `AppServiceProvider.php:851-858`).
10. **`CustomFieldType::inputFormat()` is a write format.** The spec routes variables "through" it (line 562), but it describes what an agent writes (`app/Enums/CustomFieldType.php:41-46`). The catalogue reads the type from `CustomFieldType` to decide what is insertable and renders values with the existing `customFieldMergeValue()` (`EmailTemplateRenderService.php:243-262`).
11. **Unknown tags reach agents too.** `substitute()` returns an unknown tag unchanged (`EmailTemplateRenderService.php:303`). Chat and MCP bodies skip the composer, so the guard also lands in `SendEmailAction` and `SendEmailBatchAction`.
12. **People have no time zone.** Presets use `effectiveTimezone()` (`app/Models/User.php:193-196`, falls back to UTC). The recipient-time hint is dropped (`c-composer.md:377`).
13. **Tasks cannot link to email.** A task links to companies, people and opportunities only (`app/Models/Task.php:97-113`). PR 12 adds the package table `email_follow_ups` and its master contract row. Closing reuses `CompleteTask` (`app/Actions/Task/CompleteTask.php`) as the follow-up's owner inside `CurrentWorkspace::within()`, since `StoreEmailJob` binds no workspace.
14. **Follow-ups need PR 2 to key app-sent rows.** Closing joins through `emails.conversation_key`. Part B plans exactly that (`2026-10-31-email-conversations-b-data.md` lines 11 and 43: the resolver runs in `SendEmailAction` too). Confirm it in PR 2's merged code before Task 9.
15. **Business days use Carbon's weekday arithmetic in the viewer's zone, as PR 5 does** (part C line 450: `subWeekdays(ConversationView::AWAITING_REPLY_BUSINESS_DAYS)` in the viewer's zone). Follow-up due dates use `addWeekdays()` and the facts card uses `diffInWeekdays()` the same way. No helper class.
16. **Shortcut cases come with the features.** PR 7 owns `EmailShortcut` and leaves composer keys and Undo-send's `z` to PR 12, and `s` to PR 14 (part D note 9). PR 12 adds `Send` (`Mod+Enter`) and `SendLater` (`Mod+Shift+Enter`) and lets the send-undo toast answer PR 7's `Undo` (`z`); PR 14 adds `Summarize` (`s`) and `DraftWithRela` (`Mod+J`). Each `match` on the enum gets the case, no `default`. If PR 7 has not merged, these steps wait for it; nothing else does.
17. **The summary service has four gaps and its model call moves to Chat.** No credit reservation (only `ChatController.php:194` and `TurnContinuationService.php:86` reserve), pinned `openai`/`gpt-4o-mini` (`config/services.php:65-68`, `.env.example:193-194`), `Filament::getTenant()` (`EmailThreadSummaryService.php:166-167`), and a delete-then-create cache that evicts other viewers (`:169-180`, unique key in `2026_09_09_015446_recreate_ai_summaries_table.php`). Credits and `AiModelResolver` live in Chat, which EmailIntegration may not import, so `Relaticle\Chat\Actions\SummarizeEmailConversation` makes the call; `EmailThreadSummaryService` keeps prompt and per-viewer cache.
18. **Summarize renders in the Rela slot, not the drawer header** (spec line 471). The drawer cannot ask Chat whether a model exists. The slot header sits right under the drawer header, and `s` dispatches the same event.
19. **Cards cost a full turn.** D31 auto-generates the summary on first open for three or more messages (`e-speed-ai-and-later.md:431`). Decision: auto only in the Emails page drawer; cards only on "Suggest next steps"; never automatic on record tabs.
20. **New credit type `AiCreditType::Draft`** (spec line 566 over `c-composer.md:766`). Summaries use the existing `Summary` case, which nothing writes today.
21. **Provenance lives in the credit ledger.** `c-composer.md:715, 780` proposed a draft column; the spec does not. The Draft transaction carries `metadata.email_id`, which also drives the "Drafted by Rela" chip. `X-Relaticle-AI-Draft` (`e-speed-ai-and-later.md:448`) is out of scope.
22. **New contracts.** The master table gains `email_follow_ups` (PR 12), `ai_summaries.user_id` with a widened unique key and `agent_conversations.email_conversation_key` (PR 14), each in its PR.
23. **Name collision.** `Relaticle\Chat\Queries\ConversationsQuery` already exists (`packages/Chat/src/Queries/ConversationsQuery.php:15`). PR 14 imports PR 5's email one as `EmailConversationsQuery`.
24. **No send later on mass send.** `SendEmailBatchAction` takes no `scheduled_for`, and the Outbox reschedules. Later PR.

---

## PR 12: Composer

**Branch:** `feat/email-composer`, from PR 6's branch until it merges, then rebased on `main`.

**Goal:** D15 to D19, D21, and D30's self-closing follow-up task.

### File map

Paths under `packages/EmailIntegration/` unless they start with `app/`, `lang/`, `docs/` or `tests/`.

| File | Change | Owns |
|---|---|---|
| `database/migrations/2026_11_xx_000001_add_draft_version_to_emails_table.php` | create | `emails.draft_version` |
| `database/migrations/2026_11_xx_000002_add_email_undo_seconds_to_users_table.php` | create | `users.email_undo_seconds` |
| `database/migrations/2026_11_xx_000003_create_email_follow_ups_table.php` | create | `email_follow_ups` |
| `app/Models/User.php` | modify | docblock, cast |
| `src/Models/Email.php` | modify | `draft_version`; `quotedBelow()` marker |
| `src/Models/EmailFollowUp.php` | create | follow-up link |
| `src/Enums/UndoSendWindow.php` | create | 5, 10, 20, 30, `MAX` |
| `src/Enums/SendLaterPreset.php` | create | Tomorrow, Monday in a zone |
| `src/Data/AutosaveResult.php`, `src/Data/VariableCheck.php` | create | results |
| `src/Exceptions/StaleDraft.php`, `src/Exceptions/UndoWindowClosed.php` | create | refusals |
| `src/Actions/AutosaveEmailDraft.php` | create | light, version-checked save |
| `src/Actions/SaveEmailDraftAction.php` | modify | `expectedVersion`, version bump |
| `src/Actions/SaveAgentEmailDraft.php` | modify | caller migration |
| `src/Actions/UndoQueuedSend.php` | create | window check, flip to DRAFT |
| `src/Actions/UpdateEmailPreferences.php` | create | writes the undo window |
| `src/Actions/SendEmailAction.php`, `src/Actions/SendEmailBatchAction.php` | modify | user window, unknown-tag guard |
| `src/Actions/CreateEmailFollowUp.php`, `src/Actions/CloseAnsweredFollowUps.php` | create | follow-ups |
| `src/Services/EmailVariableCatalogue.php` | create | the one owner of variables |
| `src/Services/EmailTemplateRenderService.php` | modify | reads the catalogue |
| `src/Filament/RichContent/EmailVariableNode.php` | create | Tiptap PHP node |
| `src/Filament/RichContent/ComposerCommandsPlugin.php`, `resources/js/composer-commands.js` | create | `/`, `{`, "Insert image" |
| `src/EmailIntegrationServiceProvider.php` | modify | JS asset |
| `src/Livewire/EmailComposer.php`, `resources/views/livewire/email-composer.blade.php` | modify | everything the composer shows |
| `resources/views/components/composer-header.blade.php` | modify | saved state |
| `resources/views/components/composer-send-button.blade.php` | create | split Send |
| `src/Support/QueuedSendNotifier.php` | modify | toast length |
| `src/Livewire/EmailAccessNotificationHandler.php` | modify | Undo reopens |
| `src/Livewire/OutboxTable.php` | modify | cutoff, picker zone |
| `src/Filament/Pages/EmailAccountsPage.php` | modify | "Undo send" select |
| `src/Filament/Resources/EmailTemplateResource/Schemas/EmailTemplateForm.php` | modify | `{` in templates |
| `src/Actions/StoreEmailAction.php` (or PR 2's summary caller) | modify | closes follow-ups |
| `config/email-integration.php` | modify | drop `undo_send_window_seconds` |
| `lang/en/filament/emails/composer.php`, `lang/en/filament/concerns/email-compose.php` | modify | copy |
| `docs/superpowers/plans/2026-10-31-email-conversations.md` | modify | contract row |
| `tests/Feature/EmailIntegration/{EmailComposerTest,UndoSendFlowTest,SendEmailActionTest,EmailTemplateRenderTest,StoreEmailActionTest,MailboxDataPurgeTest,EmailOutboxPageTest,MassSendEmailTest}.php` | modify | behaviour |
| `tests/Feature/Mcp/EmailToolsTest.php`, `tests/Feature/Chat/EmailToolsTest.php` | modify | hold, chat window |
| `tests/Browser/EmailIntegration/ComposerRecipientsBrowserTest.php` | modify | browser |

### Task 1: New mail opens in the corner window (D15)

**Files:** `EmailComposer.php` (`open()`, lines 225 to 281), blade (opening frame line 50, window classes lines 149 to 160), `EmailComposerTest.php`.

- [ ] Failing tests next to line 69: `it('opens new mail in the corner window')` (`assertSet('isExpanded', false)`); `it('opens a mass send fitted')`; `it('opens a saved reply draft fitted and a plain draft in the corner')`.
- [ ] Replace the unconditional `$this->isExpanded = true` (line 280) with: true for a mass send or a loaded draft with a source email, else false. Delete the comment at lines 276 to 279.
- [ ] The opening frame (line 50) draws the corner skeleton unless `$event.detail.payload?.massSend`.
- [ ] Below 640px the corner window fills the screen: `max-sm:inset-0 max-sm:h-full max-sm:w-full max-sm:rounded-none` on the corner state (line 154).

### Task 2: Server autosave with a stale-write check (D16)

**Files:** draft-version migration, `Email.php`, `AutosaveEmailDraft.php`, `SaveEmailDraftAction.php`, `SaveAgentEmailDraft.php`, `EmailComposer.php` (`persistDraft()` lines 1895 to 1962, `loadDraft()` line 2027), blade, `composer-header.blade.php`, tests.

**Interfaces:**
- `AutosaveEmailDraft::execute(User $user, ?string $draftId, int $expectedVersion, array $data): AutosaveResult` with `status` `created`, `saved`, `stale` or `gone`, plus `draftId` and `version`. `$data`: account, subject, body, to, cc, bcc, source, creation source. Never attachments.
- `SaveEmailDraftAction::execute(User $user, array $data, ?string $draftId = null, ?int $expectedVersion = null)`: every update increments `draft_version`; a mismatch throws `StaleDraft`.

- [ ] Write the failing test in `EmailComposerTest.php`, after line 737:

```php
it('refuses an autosave from a stale tab and keeps the newer text', function (): void {
    $tabOne = Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['ana@acme.test'])
        ->set('subject', 'Pricing')
        ->set('bodyHtml', '<p>first words</p>')
        ->call('autosave');

    $draftId = $tabOne->get('draftId');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', draftId: $draftId)
        ->set('bodyHtml', '<p>written in tab two</p>')
        ->call('autosave')
        ->assertSet('autosaveState', 'saved');

    $tabOne->set('bodyHtml', '<p>stale tab one</p>')
        ->call('autosave')
        ->assertSet('autosaveState', 'stale')
        ->call('close');

    $draft = Email::query()->with('body')->findOrFail($draftId);

    expect($draft->draft_version)->toBe(1)
        ->and($draft->body->body_html)->toContain('written in tab two')
        ->and($draft->body->body_html)->not->toContain('stale tab one')
        ->and(Email::query()->where('status', EmailStatus::DRAFT)->count())->toBe(1);
});
```

  The `close()` call matters: `persistDraft()` writes with no check today (`SaveEmailDraftAction.php:62-79`), so the old tab's close would overwrite tab two even if autosave refused.
- [ ] Also: `it('fires drafts:changed only when autosave creates the draft')`; `it('skips autosave for a blank compose')` (the guard at `EmailComposer.php:1988-1998`); `it('tells the tab the draft was sent elsewhere')` (delete via `DeleteEmailDraftAction`, then `autosaveState` is `gone`); `it('saves a stale tab as a new draft without touching the newer row')`. In `MailboxDataPurgeTest.php`: `it('erases an autosaved draft and its files with the member who wrote it')`.
- [ ] Migration: additive `integer default 0`, `up()` only; rehearse the lock time on `emails`.
- [ ] `AutosaveEmailDraft`: authorize the mailbox with `ConnectedAccount::ownedBy()` as `SaveEmailDraftAction.php:60-64` does. No id and a non-empty payload: delegate to `SaveEmailDraftAction` without attachments, return `created`. With an id: one conditional update (`user_id`, `workspace_id`, DRAFT, id, `draft_version = expected`) setting text columns and `draft_version + 1`, then body and participants only if a row matched. Zero rows: `gone` when the draft no longer exists, else `stale`.
- [ ] `SaveEmailDraftAction`: `lockForUpdate` inside its transaction, version check, bump. Migrate both callers in the same change: `persistDraft()` passes `$this->draftVersion`; `SaveAgentEmailDraft` passes null, so an agent rewrite wins and the open composer turns stale.
- [ ] Composer: `#[Locked] public int $draftVersion`, `public string $autosaveState` (`idle`, `saving`, `saved`, `stale`, `gone`), methods `autosave()`, `saveAsNewDraft()`, `reloadDraft()`. `persistDraft()` turns `StaleDraft` into the `stale` state instead of "Draft saved". Rewrite its docblock (Plan note 6).
- [ ] Alpine: 2000 ms debounce on editor input, subject and recipient changes; flush on window blur and `visibilitychange`; Send and `Cmd/Ctrl Enter` clear the timer first. Skip while `stale` or `gone`.
- [ ] Header line "Saving" / "Saved". Banner for `stale`: "This draft changed in another tab." with Reload and Save as a new draft. For `gone`: "This draft was sent or discarded in another tab." with Save as a new draft.

### Task 3: Per-user undo window (D18, part one)

**Files:** users migration, `User.php`, `UndoSendWindow.php`, `UpdateEmailPreferences.php`, `SendEmailAction.php` (lines 256 to 269), `QueuedSendNotifier.php`, `OutboxTable.php` (lines 226 to 231), `EmailAccountsPage.php`, config line 92, lang, tests.

**Interfaces:** `UndoSendWindow: int` (`Five`, `Ten`, `Twenty`, `Thirty`), `getLabel()`, `const int MAX = 30`, `static forUser(User $user): self` (falls back to `Ten`). `UpdateEmailPreferences::execute(User $user, UndoSendWindow $undo): void`.

- [ ] Failing test in `tests/Feature/Mcp/EmailToolsTest.php`, after line 1139:

```php
it('holds an agent email for the whole hold when the user picked a five second undo', function (): void {
    $this->viewer->forceFill(['email_undo_seconds' => 5])->save();
    $this->travelTo(now()->startOfSecond());

    $data = emailToolData($this->viewer, SendEmailTool::class, sendArguments($this->viewerAccount));

    expect(Email::query()->findOrFail($data['id'])->scheduled_for->equalTo(now()->addSeconds(300)))->toBeTrue();
});
```

- [ ] Also: `SendEmailActionTest.php`, `it('holds a composer send for the window the sender chose')` over `[5, 10, 20, 30]`. `EmailComposerTest.php` line 489: the default toast is now `10_000`; add a 30 s case. `tests/Feature/Chat/EmailToolsTest.php` line 976 reads the user's window; line 1716 broke the toast through a bad config value that no longer exists, so bind a `QueuedSendNotifier` whose `send()` throws and keep the "no email, card still pending" assertion. `EmailOutboxPageTest.php`: `it('lists a send inside a thirty second undo window under Queued')`. The accounts page test: 20 saves, 15 fails validation.
- [ ] Migration `smallInteger('email_undo_seconds')->default(10)`; `User` docblock and `integer` cast.
- [ ] `resolveScheduledFor()` takes the user; its PRIORITY branch reads `UndoSendWindow::forUser($user)`. The explicit `scheduled_for` branch stays first (Plan note 4).
- [ ] `QueuedSendNotifier::send()`: seconds left until `scheduled_for`, at least 1, at most `UndoSendWindow::MAX`. Beyond the window the toast reads "Scheduled for Mon, Nov 2, 8:00 (your time)".
- [ ] `OutboxTable::queuedDueCutoff()` uses `UndoSendWindow::MAX`. Delete the config key. Delete `fields.scheduled_for` (Plan note 5).
- [ ] `EmailAccountsPage`: a "Sending" section with "Undo send", saved through `UpdateEmailPreferences` (or PR 7's personal preferences section if it exists).
- [ ] `grep -rn "undo_send_window_seconds" app packages tests config` is empty.

### Task 4: Undo reopens the draft, inside the real window (D18, part two)

**Files:** `UndoQueuedSend.php`, `Email.php`, `EmailAccessNotificationHandler.php` (lines 149 to 177), `UndoSendFlowTest.php`.

**Interfaces:** `UndoQueuedSend::execute(User $user, string $emailId): Email` returns the DRAFT; throws `UndoWindowClosed` unless the row is the user's, QUEUED, and `now() < scheduled_for`.

- [ ] Failing tests in `UndoSendFlowTest.php` (add a user and mailbox `beforeEach` like `EmailComposerTest.php:59-68`, and `UndoQueuedSend::class` to `mutates`):

```php
it('ignores undo once the window has passed and the email still goes out once', function (): void {
    Bus::fake([SendEmailJob::class]);
    $this->travelTo(now()->startOfSecond());

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Late undo')
        ->set('bodyHtml', '<p>Hello</p>')
        ->call('send');

    $email = Email::query()->where('subject', 'Late undo')->sole();

    $this->travel(11)->seconds();

    Livewire::test(EmailAccessNotificationHandler::class)
        ->dispatch('undo-queued-send', emailId: $email->getKey())
        ->assertNotified(__('filament/concerns/email-compose.notifications.too_late.title'));

    $this->artisan('email:dispatch-outbox')->assertSuccessful();
    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    Bus::assertDispatchedTimes(SendEmailJob::class, 1);
    expect(Email::query()->where('status', EmailStatus::DRAFT)->exists())->toBeFalse();
});
```

  Today this fails: `CancelQueuedEmailAction` cancels a QUEUED row at 11 s.
- [ ] Also `it('reopens a reply as a draft with its text, recipients and file when undone in time')`: reply with one upload, send, travel 9 s, undo; the same row is DRAFT, folder Drafts, PRIVATE, `scheduled_for` null; the body has the typed text and no `[data-relaticle-quote]`; participants and the attachment remain; `composer:open` was dispatched with that id.
- [ ] `quotedBelow()` adds `data-relaticle-quote` to the reply `<blockquote>` (line 365).
- [ ] `UndoQueuedSend`: transaction, `lockForUpdate`, checks; set DRAFT, Drafts, PRIVATE, `scheduled_for` null, `draft_version` 0; strip the marked quote and the `<br>` before it with `DOMDocument`; delete this email's follow-ups and their tasks through `DeleteTask` (Task 9); call `SyncEmailBatchCountersAction`. The dispatcher claims with `where status = QUEUED` (`DispatchOutboxCommand.php:147-150`), so a racing claim updates nothing.
- [ ] Handler: call `UndoQueuedSend`; on success dispatch `composer:open` with `draftId` and `outbox:changed`, notify "Send undone. Your draft is open."; on `UndoWindowClosed`, `notifyTooLate()`. Outbox Cancel keeps `CancelQueuedEmailAction`. A reply draft reopens fitted (Task 1, D15).

### Task 5: Split Send with presets in the sender's zone (D17)

**Files:** `SendLaterPreset.php`, `composer-send-button.blade.php`, `EmailComposer.php` (`queueEmail()` lines 512 to 553), `OutboxTable.php` (picker lines 93 to 98), tests.

**Interfaces:** `SendLaterPreset: string` (`Tomorrow`, `Monday`), `resolve(User $sender): CarbonImmutable` in UTC, `label()`. Composer `sendLater(string $preset)` and `sendAt(string $localDateTime)` both call `send()` with a `scheduledFor`.

- [ ] Failing test in `EmailComposerTest.php`:

```php
it('schedules a preset at 8:00 in the sender zone across the autumn clock change', function (string $preset, string $expectedUtc): void {
    $this->user->forceFill(['timezone' => 'Europe/Berlin'])->save();
    $this->travelTo(Date::parse('2026-10-24 10:00:00', 'UTC'));

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Next week')
        ->set('bodyHtml', '<p>Hello</p>')
        ->call('sendLater', $preset);

    expect(Email::query()->where('subject', 'Next week')->sole()->scheduled_for->toIso8601String())
        ->toBe($expectedUtc);
})->with([
    'tomorrow' => ['tomorrow', '2026-10-25T07:00:00+00:00'],
    'monday' => ['monday', '2026-10-26T07:00:00+00:00'],
]);
```

  Saturday 24 October is CEST (UTC+2); Berlin falls back on Sunday 25 October. A preset built from today's offset lands at 06:00 UTC and fails.
- [ ] Also: `it('reads presets in UTC and says so when the sender has no time zone')`; `it('refuses a picked time in the past')`; `it('shows one preset on a Sunday when tomorrow is Monday')`.
- [ ] `resolve()`: `now($sender->effectiveTimezone())`, `addDay()` or `next(CarbonInterface::MONDAY)`, `setTime(8, 0)`, `utc()`. `sendAt()` parses in the sender's zone, as `packages/Chat/src/Tools/Concerns/ReadsSendTime.php:20` does, and needs a time at least a minute ahead. Priority stays PRIORITY.
- [ ] The caret menu lists each preset with its resolved date, then "Pick a date and time" (a `DateTimePicker` action with `->timezone($user->effectiveTimezone())`), and the UTC notice linking to the profile when `timezone` is null. Hidden on mass send. The Outbox reschedule picker gets the same `->timezone()`.
- [ ] `EmailShortcut` (PR 7): add `Send` (`Mod+Enter`) and `SendLater` (`Mod+Shift+Enter`, opens the caret menu), and let the send-undo toast answer `Undo` (`z`) (Plan note 16).

### Task 6: One variable catalogue and `{` chips (D19, part one)

**Files:** `EmailVariableCatalogue.php`, `EmailTemplateRenderService.php`, `EmailVariableNode.php`, `ComposerCommandsPlugin.php`, `composer-commands.js`, service provider, `EmailComposer.php` (`bodySchema()`, `applyTemplate()` lines 1426 to 1445), `EmailTemplateForm.php`, tests.

**Interfaces:**
- Token text inside the node's span: `{{ person.job_title }}` or `{{ person.job_title | there }}`. Codes: `person.<code>`, `company.<code>`, `opportunity.<code>`, plus `person.name`, `person.first_name`, `company.name`, `opportunity.name`, `sender.name`, `today`. Codes, never labels.
- `EmailVariableCatalogue::variables(User $viewer)`, `values(?Model $record, User $sender): array<string, string>`, `check(string $subject, string $bodyHtml, list<?Model> $recipients, User $sender): VariableCheck` (`unknownTags`, `emptyFor`, `isClean()`).

- [ ] Failing tests in `EmailTemplateRenderTest.php`: `it('renders any active custom field of a person by its code')`; `it('uses the fallback when the value is empty')`; `it('keeps resolving the legacy name, label and brace forms')` (`{name}`, `{{ name }}`, `{{ Full name }}`, `{{ job_title }}` through `mergeTagKeyAliases()`, lines 335 to 359, kept as a read shim); `it('leaves an inactive field out of the catalogue')`.
- [ ] The catalogue queries fields like `CustomFieldSchema::fields()` (`app/Mcp/Schema/CustomFieldSchema.php:51-68`), skips types that do not read as text (`RICH_EDITOR`, `FILE_UPLOAD`, `RECORD`, `CHECKBOX`, `TOGGLE`, `COLOR_PICKER`), and absorbs `customFieldMergeValue()` and the per-person value reading (lines 206 to 262) from the render service.
- [ ] `substitute()` resolves through the catalogue and applies fallbacks; escaping stays (lines 293 to 295) and covers fallbacks.
- [ ] `EmailVariableNode` (PHP): `emailVariable`, attributes `code` and `fallback`, parsed from `span[data-type="emailVariable"]`. Without it `setBodyHtml()`/`bodyHtmlValue()` drop the node on every round trip.
- [ ] JS: the same node, a `{` suggestion fed base64 through a data attribute (as `SlashMenuPlugin::attributes()` does), a fallback popover; yellow without a fallback, blue with one, red when unknown or inactive, from `resources/css/theme.css` tokens.
- [ ] `bodySchema()` drops `mergeTags` (line 1067 and its toolbar group) and adds the plugin. `applyTemplate()` stops rendering against `null` (line 1441) and inserts template tokens as chips. `EmailTemplateForm` uses the plugin too.

### Task 7: Pre-send check, warning and per-recipient preview (D19, part two)

**Files:** `EmailComposer.php` (`send()` lines 433 to 505, `sendMass()` lines 562 to 650), blade, `SendEmailAction.php`, `SendEmailBatchAction.php`, tests.

- [ ] Failing tests in `EmailComposerTest.php`:

```php
it('blocks a send whose message holds a tag no variable resolves', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Hello')
        ->set('bodyHtml', '<p>Hi {{ person.shoe_size }}</p>')
        ->call('send')
        ->assertHasErrors(['bodyHtml']);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->exists())->toBeFalse();
});

it('warns before sending when a recipient has no value and no fallback', function (): void {
    // a person whose primary email is lead@example.com and who has no job title,
    // built the way the test at line 320 builds one
    $composer = Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Hello')
        ->set('bodyHtml', '<p>Hi {{ person.job_title }}</p>')
        ->call('send')
        ->assertSet('variableWarning', ['person.job_title' => ['lead@example.com']]);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->exists())->toBeFalse();

    $composer->call('send', confirmEmptyVariables: true);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(1);
});
```

- [ ] Also: `it('sends without a warning when the empty variable has a fallback')`; `SendEmailActionTest.php`, `it('refuses a body that still holds an unknown tag from any caller')`; `MassSendEmailTest.php`, `it('previews each recipient with their own values')` and `it('blocks a mass send when one recipient would get an unknown tag')`.
- [ ] `send()` and `sendMass()` run `check()` before copying attachments. Unknown tags: `addError('bodyHtml')` naming them. Empty values without fallback: set `variableWarning` and stop unless `confirmEmptyVariables`. The modal reads "2 recipients have no Job title: Ana, Ben. Send anyway?".
- [ ] `SendEmailAction` and `SendEmailBatchAction` throw `ValidationException` naming any unknown tag left after rendering (Plan note 11).
- [ ] Mass send: "Review emails" steps through each person's rendered copy ("3 of 40").

### Task 8: `/` for templates and signatures, and the short toolbar (D19, D21)

**Files:** plugin, JS, `EmailComposer.php` (`bodySchema()` lines 1054 to 1086), blade footer (lines 302 to 326), tests.

- [ ] Rewrite `puts merge tags last on the message toolbar instead of the footer` (line 109), whose subject this task replaces, as `it('shows the short toolbar with link and a distinct insert image tool')`: groups are bold, italic, link; lists; `attachFiles`; More. No `alignStart`, `undo`, `redo`, `mergeTags`. `attachFiles` is labelled "Insert image" and its icon is not `Heroicon::PaperClip`.
- [ ] `toolbarButtons`: `['bold', 'italic', 'link']`, lists group, `['attachFiles']`, More group (`paragraph`, `h2`, `h3`, `strike`, `underline`, `blockquote`, `codeBlock`). Keep `floatingToolbars` (lines 1079 to 1081).
- [ ] The plugin's `getEditorTools()` overrides `attachFiles` with "Insert image" and `Heroicon::OutlinedPhoto` (Filament ships a paper clip, `RichEditor.php:353-358`). The footer clip becomes "Attach file".
- [ ] `/` opens the user's templates and signatures (`templateOptions()`, `signatureOptions()`), calling `applyTemplate()` or setting `signatureId`. Footer pickers stay for mouse users. Check the row fits the 38rem window at 1280px.

### Task 9: Follow-up task that closes itself (D30)

**Files:** follow-ups migration, `EmailFollowUp.php`, `CreateEmailFollowUp.php`, `CloseAnsweredFollowUps.php`, composer, PR 6 drawer header, store path, `UndoQueuedSend.php`, master contract row, tests.

**Interfaces:**
- `email_follow_ups`: `id` ULID, `workspace_id`, `user_id`, `task_id` (unique), `email_id` (the outbound message it waits on), all FK cascade, `created_at` from PHP `now()`.
- `CreateEmailFollowUp::execute(User $user, Email $email, int $businessDays = 3): Task`: authorizes `view` on the email; creates the task through `CreateTask` titled "Follow up: {subject}" (or "Follow up with {name}" below `viewSubject`), due `addWeekdays($businessDays)` from today in the user's zone (Plan note 15), assigned to the user, linked to the email's visible linked people and companies.
- `CloseAnsweredFollowUps::execute(string $workspaceId, string $conversationKey): int`: completes open follow-ups in that conversation created before `email_conversations.last_external_inbound_at`, inside `CurrentWorkspace::within()`, via `CompleteTask` as the follow-up's user. Done tasks are skipped, so reruns are harmless.

- [ ] Failing test in `StoreEmailActionTest.php`:

```php
it('closes a follow-up task on an external reply but not on a teammate reply', function (): void {
    // the seller sends to buyer@client.test from the composer with the follow-up toggle on;
    // the queued email carries a conversation_key (Plan note 14)
    // store a teammate's reply in the same conversation through StoreEmailAction
    //   -> the task's status option is not Done
    // store buyer@client.test's reply in the same conversation
    //   -> the task's status option is Done; storing the same reply again changes nothing
});
```

  Build both replies with the file's `FetchedEmailData` fixture (line 66) and `inReplyTo` set to the sent `rfc_message_id`; read status through `TaskField::STATUS` and `TaskField::DONE_STATUS`.
- [ ] Also in `EmailComposerTest.php`: `it('creates a follow-up task due in three business days when the toggle is on')` with `travelTo` on a Thursday (due Tuesday); `it('removes the follow-up and its task when the send is undone')`.
- [ ] Composer: `public bool $followUp = false`, a "Follow up if no reply" toggle beside Send, applied after `queueEmail()` succeeds. Drawer header (PR 6): "Follow up in 3 business days" on the viewer's latest outbound copy.
- [ ] Call `CloseAnsweredFollowUps` right after `RefreshConversationSummary` on store. A member's reply only moves `last_member_outbound_at`, which is the rule the test pins.

### Task 10: Browser coverage

**Files:** `tests/Browser/EmailIntegration/ComposerRecipientsBrowserTest.php`.

- [ ] Add, light and dark: `it('opens new mail in the corner window and fills the screen on a phone')`; `it('keeps typed text after a reload because autosave ran')` (type, wait 2500 ms, reload, Drafts); `it('schedules from the send caret and lists the email under Scheduled')`; `it('inserts a variable chip with braces and turns it blue once a fallback is set')`; `it('undoes a send and reopens the draft')`.
- [ ] Run the file, then again with `--browser safari` (`pnpm exec playwright install webkit` once).

### PR 12 UI verification

`amp orb services ensure`, then agent-browser at the portal URL as `owner@relaticle.test`, at `set viewport 1440 900 2` and `390 844 2`, light and dark, screenshots into `.amp/in/artifacts/` and the PR body: corner window, minimised bar and expanded; inline reply in the drawer and the same draft reopened fitted; "Saving", "Saved" and the stale banner (second agent-browser session on the same draft); send caret with and without a profile zone; 30 s undo toast, reopened draft, "Too late"; `{` picker, yellow and blue chips, the warning modal, "Review emails"; toolbar and More at 1280; empty state as `empty@relaticle.test`.

### PR 12 chat-stack verification

Approved chat sends now follow the user's window. With Horizon on redis and Reverb up: set "Undo send" to 20 s, ask Rela to email a seeded person, watch the card stream, approve, confirm a 20 s toast, press Undo and see the draft open. Repeat without Undo: Horizon shows one `SendEmailJob`. Through the MCP server with the user at 5 s, the Outbox shows the 300 s hold.

### PR 12 checklist

- [ ] Migrations rehearsed on anonymised production data; notes in the PR body.
- [ ] Contract rows match; `email_follow_ups` added to the master table.
- [ ] `grep -rn "undo_send_window_seconds\|email-compose.fields" app packages lang tests config` is empty.
- [ ] SystemAdmin sweep for `UndoSendWindow`, `SendLaterPreset` (no `match` there).
- [ ] Rector, PHPStan, `composer test:lint`, `composer test:arch`; browser tests in Chromium and WebKit.
- [ ] Release note: `EMAIL_UNDO_SEND_WINDOW` is ignored; users pick 5, 10, 20 or 30 s, default 10.

---

## PR 14: Rela in the reader

**Branch:** `feat/email-rela-reader`, from `feat/email-composer` until PR 12 merges.

**Goal:** D31 and D20: a metered, per-viewer summary that works from a job; cards on the proposal pipeline on an anchored chat conversation; a facts card with no AI key; "Draft with Rela" that only writes a draft.

### File map

| File | Change | Owns |
|---|---|---|
| `database/migrations/2026_11_xx_000010_add_user_id_to_ai_summaries_table.php` | create | `ai_summaries.user_id`, wider unique |
| `database/migrations/2026_11_xx_000011_add_email_conversation_key_to_agent_conversations_table.php` | create | anchor column, partial unique |
| `packages/EmailIntegration/src/Services/EmailThreadSummaryService.php` | modify | conversation prompt, per-viewer cache, no model call |
| `packages/EmailIntegration/src/Models/AiSummary.php` | modify | `user_id` |
| `packages/EmailIntegration/src/Actions/PurgeStoredMailboxData.php` | modify | summaries purge |
| `packages/EmailIntegration/src/Filament/Concerns/HasEmailReaderActions.php` | modify | delete lines 400 to 421 and the sparkle action |
| `packages/EmailIntegration/src/Queries/ConversationFactsQuery.php`, `src/Data/ConversationFacts.php` | create | facts and the reply-draft rule |
| `packages/EmailIntegration/resources/views/components/conversation-facts.blade.php` | create | facts card |
| PR 6 `reader-drawer` view, composer blade, `EmailComposer.php` | modify | mount Chat components by alias, reload on event |
| `packages/Chat/src/Enums/AiCreditType.php`, `MessageOrigin.php` | modify | `Draft`, `EmailReview` |
| `packages/Chat/src/Models/AgentConversation.php` | modify | `PURPOSE_EMAIL`, `listable()`, `forEmailConversation()` scopes |
| `packages/Chat/src/Queries/ConversationsQuery.php` | modify | sidebar uses `listable()` |
| `packages/Chat/src/Actions/StartChatTurn.php`, `ChatController.php` | create, modify | turn start lifted from `send()` |
| `packages/Chat/src/Actions/OpenEmailRelaConversation.php` | create | find or create the anchor |
| `packages/Chat/src/Actions/SummarizeEmailConversation.php`, `Jobs/SummarizeEmailConversationJob.php` | create | metered summary |
| `packages/Chat/src/Actions/DraftEmailWithRela.php`, `Jobs/WriteRelaEmailDraft.php`, `Agents/EmailDrafter.php` | create | composer drafting |
| `packages/Chat/src/Agents/CrmAssistant.php`, `Jobs/ProcessChatMessage.php` | modify | review mode |
| `packages/Chat/src/Livewire/Email/EmailRelaSlot.php`, `EmailRelaDraft.php` and views | create | drawer slot, composer prompt |
| `packages/Chat/src/Livewire/Chat/ProposalCard.php` | modify | no resume in `email-reader` |
| `packages/Chat/src/ChatServiceProvider.php` | modify | component aliases |
| `config/services.php`, `.env.example` | modify | drop `email_summary` |
| `docs/superpowers/plans/2026-10-31-email-conversations.md` | modify | two contract rows |
| `tests/Arch/ArchTest.php` | modify | package direction |
| `tests/Feature/EmailIntegration/{EmailThreadSummaryServiceTest,MailboxDataPurgeTest,EmailComposerTest}.php` | modify | summary, purge, reload |
| `tests/Feature/Chat/{EmailToolsTest,AiCreditTypeTest,ChatSidePanelConversationsTest}.php` | modify | drafting, review mode, slot, sidebar |
| PR 6's drawer browser test, `ComposerRecipientsBrowserTest.php` | modify | browser |

### Task 1: Summary from a job, metered, per viewer

**Interfaces:**
- `EmailThreadSummaryService::prompt(User $viewer, string $conversationKey): string` from `ConversationsQuery::messages()` with today's tier rules (`effectiveTier()`, body only at FULL). `cached(User, EmailConversation, string $inputHash): ?AiSummary`. `store(User, EmailConversation, string $text, string $inputHash, string $model, TextUsage $usage): AiSummary`, an upsert per `(summarizable, workspace_id, user_id)`.
- `SummarizeEmailConversation::execute(User $viewer, string $workspaceId, string $conversationKey, bool $regenerate = false): ?AiSummary` inside `CurrentWorkspace::within()`. Cache hit: no charge. Else `reserveCredit($workspace, "reserve-summary-{$ulid}", userId: ...)` (null when refused), `AiModelResolver::resolve($viewer)`, prompt `ThreadSummarizer`, store, `settleReservation(type: AiCreditType::Summary, resolutionKey: "resolve-summary-{$ulid}")`. Provider failure: `refundReservation()` with that key, rethrow. The keys match the orphan sweeper (`ReleaseOrphanedReservationsCommand.php:33-40`).
- `SummarizeEmailConversationJob`: `redis-chat`, `chat` queue, `#[Tries(1)]`, `#[UniqueFor(60)]` per user and key. The slot polls while pending (`wire:poll.2s`, 30 s cap).

- [ ] Failing tests in `EmailThreadSummaryServiceTest.php`:

```php
it('summarizes a conversation from a queued job with no panel tenant and reserves a credit', function (): void {
    ThreadSummarizer::fake([fakeSummary('The buyer asked for pricing for 40 seats.', 120, 60)]);
    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $this->workspace->getKey()], [
        'credits_remaining' => 10,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
    $key = makeConversationWithEmail();

    Filament::setTenant(null);
    auth()->logout();

    dispatch_sync(new SummarizeEmailConversationJob($this->owner->id, $this->workspace->id, $key, regenerate: false));

    expect(AiSummary::query()->sole())
        ->user_id->toBe($this->owner->id)
        ->workspace_id->toBe($this->workspace->id)
        ->and(AiCreditTransaction::query()->where('idempotency_key', 'like', 'reserve-summary-%')->count())->toBe(1)
        ->and(AiCreditTransaction::query()->where('type', AiCreditType::Summary)->count())->toBe(1)
        ->and(AiCreditBalance::query()->sole()->credits_remaining)->toBeLessThan(10);
});
```

- [ ] Also: `it('keeps one cached summary per viewer instead of overwriting the other')` (two rows, the owner's reopen adds no reservation); `it('charges nothing and stores nothing when the workspace is out of credits')`. `makeConversationWithEmail()` replaces `makeThreadWithEmail()` (line 55) and returns the key. Move the existing tests onto the action keeping their intent: no body for a subject-tier viewer (line 204), share revoke (line 146), provider failure notice (line 310). `fakeSummary()` stops reading `services.email_summary`.
- [ ] Migration: nullable `user_id` FK cascade; unique becomes `(summarizable_type, summarizable_id, workspace_id, user_id)`. The two legacy rows are never read, since reads filter by user.
- [ ] Drop `services.email_summary` and `.env.example:193-194`.
- [ ] `PurgeStoredMailboxData` deletes the user's summaries and summaries of conversations holding a copy in their mailboxes, replacing `deleteThreadSummaries()` (lines 62 to 72); update `MailboxDataPurgeTest.php` lines 65 and 83.
- [ ] Delete `buildThreadSummaryView()` and its action.

### Task 2: Credit type and message origin

- [ ] Failing test in `AiCreditTypeTest.php`: `it('exposes a draft case for Rela drafts')`, value `'draft'`; the dataset at line 17 covers colour and label.
- [ ] `AiCreditType::Draft`, colour `primary`. `MessageOrigin::EmailReview = 'email_review'`, label "Email review", an opener, and a directive: read the conversation with the email tools, propose record updates the thread supports and at most one reply draft when allowed, call no send tool. `isTyped()` stays false, so `AgentConversationMessage::typed()` never counts it.
- [ ] SystemAdmin sweep: `AiCreditTransactionsTable.php:73-74` maps `cases()`; `ProblemsStats.php:76` and `TrialFarmer.php:46` count `Chat` only (drafts are not abuse signals); `AgentConversationMessageResource.php:56, 90` and `MessagesRelationManager.php:44` render `getLabel()`. No `match` to extend.

### Task 3: The anchored conversation and review mode

**Interfaces:**
- `agent_conversations.email_conversation_key` `string(64)` nullable, unique on `(workspace_id, participant_id, email_conversation_key) where purpose = 'email'`. `PURPOSE_EMAIL = 'email'` fits the `string(32)` column (`2026_09_13_100100_add_purpose_to_agent_conversations_table.php:17`).
- `StartChatTurn::execute(User $user, Workspace $workspace, string $conversationId, string $message, array $resolved, MessageOrigin $origin = MessageOrigin::Typed, ...): ?string` holds today's reserve, workspace bind, `TurnPresence::begin()` and dispatch from `ChatController::send()` (lines 192 to 253) unchanged; null when the reservation fails.
- `OpenEmailRelaConversation::execute(User $user, string $conversationKey): AgentConversation`, 403 unless the viewer sees at least one message.

- [ ] Run `ChatControllerSendTest.php`, `ChatControllerTest.php`, `ConversationFirstSendTest.php`, `OutOfCreditsResponseTest.php` before and after the extraction.
- [ ] Failing tests: `ChatSidePanelConversationsTest.php`, `it('keeps conversations anchored to an email out of the sidebar and its search')`; `tests/Feature/Chat/EmailToolsTest.php`:

```php
it('runs an email review turn with no tool that sends, cancels or deletes', function (): void {
    CrmAssistant::fake(['Here is what I suggest.']);
    $conversation = resolve(OpenEmailRelaConversation::class)->execute($this->viewer, $this->conversationKey);

    runEmailReviewTurn($this->viewer, $conversation);

    CrmAssistant::assertPrompted(function ($prompt): bool {
        $tools = collect($prompt->agent->tools())->map(fn (object $tool): string => $tool::class);

        return $prompt->agent->emailReviewMode
            && $tools->doesntContain(SendEmailTool::class)
            && $tools->doesntContain(SendEmailDraftTool::class)
            && $tools->contains(CreateEmailDraftTool::class);
    });
});
```

  `runEmailReviewTurn()` mirrors `SetupModeTurnTest.php:36-48` with `origin: MessageOrigin::EmailReview`.
- [ ] `CrmAssistant`: `emailReviewMode`, `withEmailReviewMode()`, `EMAIL_REVIEW_EXCLUDED_TOOLS` (send, send draft, cancel, reschedule, retry, delete draft, every delete tool, invite member, remove sample data), applied beside the setup-mode filter (around line 993). `ProcessChatMessage` sets it from the conversation's purpose, so later turns keep it.

### Task 4: The Rela slot in the drawer

**Interfaces:**
- `ConversationFactsQuery::facts(User $viewer, string $conversationKey): ConversationFacts`: last from them, last from us, business days waiting, open opportunity with `OpportunityField::STAGE`, next timed meeting with a linked person or company (default `VisibleMeetingScope`). `replyDraftBlocker(User $viewer, string $conversationKey): ?string`: `not_addressed`, `cc_only`, `teammate_answered`, `no_inbound`, or null.
- `<livewire:chat.email-rela-slot :conversation-key="..." />`: slot header with Summarize; no model available: facts card only; with a model: "Summary by Rela" with its source line, "Suggest next steps", and a `<livewire:chat.proposal-card context="email-reader">` per pending plan. Collapsed state via Alpine `$persist` (UI state only).

- [ ] Failing tests in `tests/Feature/Chat/EmailToolsTest.php`: `it('shows the facts card and no AI controls when no model is available')`; `it('starts one metered turn when the user asks for next steps')` (one `reserve-` row, one queued `ProcessChatMessage` with EmailReview); `it('supersedes pending cards when a newer message reached the conversation')` via `PendingActionService::supersedePendingForConversation()` (line 584), `action_data` untouched; `it('does not resume the assistant when a card is approved in the reader')`; `it('auto-generates the summary only on the Emails page and only from three messages')`.
- [ ] `ProposalCard::resumeAssistant()` (line 1503) returns early for `email-reader`; decided status still reaches the next turn through `<resolved_actions>`.
- [ ] The drawer mounts the slot as item 3. Add `EmailShortcut::Summarize` (`s`, reader scope) and `DraftWithRela` (`Mod+J`, composer scope); `s` dispatches `rela:summarize`. Facts card actions: "Create task" (PR 12 follow-up), "Link" only when PR 9 shipped.

### Task 5: The reply-draft card opens in the composer, never sends

- [ ] Failing tests in `tests/Feature/Chat/EmailToolsTest.php`: `it('opens the draft in the reader composer once its card is approved and queues nothing')` (one DRAFT with `creation_source` CHAT, no QUEUED, SENDING or SENT row, `composer:resume-draft` dispatched); `it('offers no reply draft for a cc-only email or one a teammate already answered')` (the turn message carries the blocker, and the slot rejects any `EmailDraft` card whose source fails the rule).
- [ ] No `ProposalEntity` case: `EmailDraft` already writes only a draft and does not need its own approval (`ProposalEntity.php:92-98`). The card shows "Draft, not sent"; after approval `resumeDraftFor()` (`EmailComposer.php:360`) opens it inline.

### Task 6: Draft with Rela in the composer (D20)

**Interfaces:**
- `DraftEmailWithRela::execute(User $user, string $draftId, int $draftVersion, string $instruction): void`: loads through `ComposedEmailsQuery::draft()` (no foreign drafts, no mass sends), checks `replyDraftBlocker()` for a reply, reserves `reserve-draft-{$ulid}`, dispatches the job.
- `WriteRelaEmailDraft` (`redis-chat`, `chat`): inside `CurrentWorkspace::within()`, context from `EmailThreadSummaryService::prompt()` plus the user's last five emails to the same recipients through `VisibleEmailsQuery` (voice at request time, nothing stored), `EmailDrafter` on the resolved model, then `SaveAgentEmailDraft::execute(..., EmailCreationSource::CHAT, $draftId)` keeping account, recipients and a set subject. If `draft_version` moved, it writes nothing and refunds. Settles `AiCreditType::Draft` with `metadata.email_id`. Never calls `SendEmailAction`, `HoldAgentEmail` or `PendingActionService`.
- Composer: `<livewire:chat.email-rela-draft :draft-id="$draftId" :draft-version="$draftVersion" />` above the body, hidden without a model; `Cmd/Ctrl J` focuses it. It asks the composer to autosave (`composer:flush-autosave`, answered by `composer:autosaved` with id and version), calls the action, dispatches `composer:rela-started`. The composer goes read-only, polls `checkRelaDraft` every 1500 ms (60 s cap) until the version moves, reloads, and shows "Drafted by Rela, from this thread" (also on reopen, from the ledger).

- [ ] Failing test in `tests/Feature/Chat/EmailToolsTest.php`:

```php
it('writes a Rela draft into the open draft and never proposes or queues a send', function (): void {
    EmailDrafter::fake([['subject' => null, 'body_markdown' => 'Tuesday at 10 works. The pilot price stands.']]);
    $draft = resolve(SaveEmailDraftAction::class)->execute($this->viewer, [
        'connected_account_id' => $this->viewerAccount->getKey(),
        'subject' => 'Re: Pilot',
        'body_html' => '<p></p>',
        'to' => ['client@acme.test'],
        'cc' => [],
        'bcc' => [],
    ]);

    resolve(DraftEmailWithRela::class)->execute($this->viewer, $draft->getKey(), $draft->draft_version, 'Accept Tuesday');

    $draft->refresh()->load('body');

    expect(PendingAction::query()->count())->toBe(0)
        ->and(Email::query()->whereIn('status', [EmailStatus::QUEUED, EmailStatus::SENDING, EmailStatus::SENT])->count())->toBe(0)
        ->and($draft->status)->toBe(EmailStatus::DRAFT)
        ->and($draft->body->body_html)->toContain('Tuesday at 10 works')
        ->and($draft->draft_version)->toBe(1)
        ->and(AiCreditTransaction::query()->where('type', AiCreditType::Draft)->sole()->metadata)
        ->toMatchArray(['email_id' => $draft->getKey()]);
});
```

  Tests run the queue `sync`; the chat-stack pass covers redis.
- [ ] Also: `it('leaves a draft alone that the user changed while Rela was writing')` (refund row, body unchanged); `it('refuses to draft a reply the viewer was only copied on')`; in `EmailComposerTest.php`, `it('reloads the body when Rela finished the draft')` and `it('does not autosave while Rela is writing')`.

### Task 7: Arch guard and shimmer check

- [ ] In `tests/Arch/ArchTest.php` near lines 317 to 345:

```php
arch('the email package never reaches into chat')
    ->expect('Relaticle\EmailIntegration')
    ->not
    ->toUse('Relaticle\Chat');
```

  Plant a `CreditService` import in an EmailIntegration class, see it fail, remove it (testing.md).
- [ ] No chat tool is added, so `toolLabels` (`chat-interface.blade.php:120`) needs no entry. Run `php artisan test tests/Browser/Chat/LoadingShimmerTest.php` anyway, since review mode changes a turn's tool list. `SurfaceParityTest` passes unchanged.

### Task 8: Browser coverage

- [ ] In PR 6's drawer browser file and `ComposerRecipientsBrowserTest.php`, light and dark: `it('shows the facts card in the drawer when no model is configured')`; `it('shows the summary line and a draft card in the drawer')` with faked agents; `it('drafts with Rela into the composer and leaves Send to the user')`. Run in Chromium, then `--browser safari`.

### PR 14 UI verification

agent-browser at 1440 and 390, light and dark: with both AI keys unset in `.env` (restart `app`, `horizon`, `reverb`), the facts card and no AI controls; with a key, the summary generating then shown, the collapsed slot surviving a reload, "Suggest next steps" streaming into a plan card with a record update and a "Draft, not sent" reply, the approved draft opening inline; the composer prompt, read-only editor and chip; the out-of-credits line on a zero-balance workspace; a single automated message showing only facts.

### PR 14 chat-stack verification

Horizon on redis, Reverb up, real browser:

1. Open a three-message conversation from the Emails page: one summary job on `chat`, one `reserve-summary-` and one Summary ledger row. Reopen: no new job.
2. "Suggest next steps": the turn streams over Reverb, cards dock in order, a second press while running does nothing.
3. Approve a record update: the record changes, Horizon shows no resume turn. Reject the draft card: nothing saved.
4. Store a new inbound reply, reopen: the old pending card is superseded, not edited.
5. Draft with Rela: `WriteRelaEmailDraft` runs, the body reloads, the Outbox stays empty, no `pending_actions` row.
6. Stop Horizon mid-draft, restart after the reservation age: the sweeper refunds `reserve-draft-`.

List every defect seen as its own checklist item in the PR body.

### PR 14 checklist

- [ ] Both migrations rehearsed; row counts noted. Two contract rows added.
- [ ] SystemAdmin sweep recorded for `AiCreditType::Draft` and `MessageOrigin::EmailReview`.
- [ ] `grep -rn "email_summary\|Filament::getTenant" packages/EmailIntegration/src/Services` is empty.
- [ ] Arch guard seen failing, then passing. `LoadingShimmerTest`, `SurfaceParityTest`, `CrmAssistantInstructionsTest` pass.
- [ ] Browser tests in Chromium and WebKit; screenshots in the PR body.
- [ ] Release note: summaries use the workspace's model and spend credits; `EMAIL_SUMMARY_PROVIDER` and `EMAIL_SUMMARY_MODEL` are gone.

---

## Decisions made for the caller

| Decision | If overruled |
|---|---|
| Undo flips the queued row back to DRAFT in place | A cached composer snapshot restores chips and the signature block; add it to `UndoQueuedSend` with the flip as fallback |
| Undo only before `scheduled_for` | Accepting any QUEUED row restores today's 5 to 65 s; the "after the window" test inverts |
| Close-save is version-checked; a stale tab never overwrites | A "Keep mine" button is one more `SaveEmailDraftAction` call with the current version |
| Monday means the next Monday after today | Use `nextOrSame` and hide it after 8:00 |
| Follow-ups in `email_follow_ups` keyed by `email_id` | A morph on `tasks` needs an app migration and a boundary exception |
| Summary auto on the Emails page from three messages; cards on click | Auto cards cost a full turn per open; change `EmailRelaSlot::mount()` |
| AI calls live in Chat, mounted by alias | Importing Chat into EmailIntegration creates a cycle and removes two components |
| Reader approvals do not resume the assistant | Remove the guard in `resumeAssistant()` |
| Provenance in the credit ledger | Add a draft column with the AI Act decision |

## Risks and unknowns

| Risk | Cheapest early check |
|---|---|
| The RichEditor does not send its latest document with `$wire.autosave()` | Task 2, first step: type, call it from the console, read `email_bodies` |
| Tiptap PHP drops `emailVariable` on a round trip | Task 6: `setBodyHtml()` then `bodyHtmlValue()` on a chip before any JS |
| PR 2 does not key app-sent rows | Read PR 2's `SendEmailAction` diff before Task 9 |
| PR 6's drawer has no overflow slot | Read the merged drawer before Task 9 |
| A review turn calls a removed tool and errors | The review-mode test plus one live turn on Horizon |

## Follow-ups (not in these PRs)

- Delete a deleted member's composer inline images (Plan note 7).
- "Clean up old drafts" after 90 days (`c-composer.md`, GDPR under 16).
- Send later for mass send; the `X-Relaticle-AI-Draft` header and a provenance column.
