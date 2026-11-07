# Day-one data in: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: execute with the `sdd-lean` skill (the owner's rule), task by task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A new owner says where their clients live today, lands on the tool that brings that data in, and leaves day one with dated follow-ups Rela proposed, so the next morning's task digest brings them back.

**Architecture:** A new first setup step stores the answer on the workspace (`onboarding_data_source`). The setup flow, sample seeding and the landing URL branch on it. Rela's setup conversation reads the answer and the arrived-record counts from its `<onboarding>` block, opens a new "data arrived" turn when an import or mailbox history import finishes, and carries server-picked path buttons in place of the cheap-model chips. A click on a prompt button records its id on the user message.

**Tech Stack:** Laravel 13, Filament 5, Livewire 4, Alpine, Pest 4, laravel/ai, PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-11-07-day-one-data-in-design.md`

## Global Constraints

- Follow `CLAUDE.md` and every `.ai/rules/` file whose glob covers a touched path (onboarding, chat, models, panel-links, file-uploads, notifications, boost/*).
- Migrations: `up()` only. Prove each one by the rehearsal in the Database section of `.ai/guidelines/relaticle/core.md`, never with a test.
- User-facing strings through `__()` with keys in `lang/en/` (and `lang/fr/` where the file exists). No em-dashes. Business words: workspace, company, person, opportunity, task, note; never "contacts" in copy.
- No competitor or CRM product name anywhere in code, copy, commits or the PR.
- Every write Rela makes stays a proposal the user approves. Setup mode keeps excluding update and delete tools.
- Rela never reads an uploaded file's rows. No email-activity sort or filter is added anywhere.
- Actions are `final readonly`, verb-first, single `execute()`, with owner checks inside.
- No new PHPStan ignores. Methods stay under 60 lines (`MethodLengthRule`).
- Chat changes are verified on the production-shaped stack (Horizon, Redis queue, Reverb) in a real browser; view changes in Chromium and WebKit (`--browser safari`), light and dark, desktop and mobile.

## Review Focus

1. **A workspace mid-setup at deploy** (step `email`, no data source) must finish exactly as today: seeding, landing and greeting unchanged. Test in Task 2.
2. **The inbox answer with the mailbox step skipped** behaves like "in my head": samples seeded, chat landing, memory buttons. Test in Task 2.
3. **Email integration off** hides the inbox answer, and no button ever links to `email_accounts`. Tests in Tasks 1 and 5.
4. **A second import, or a re-delivered completion notification,** must not open a second "data arrived" turn for the same import or batch. Test in Task 4.
5. **An owner without `data.import`** never sees an import button. Test in Task 5.

---

### Task 0: Branch and documents

The workspace branch `ManukMinasyan/relaticle-growth` is unborn (case-folded packed ref); its tree equals `b61319d4e`.

- [ ] **Step 1:** `git update-ref refs/heads/ManukMinasyan/relaticle-growth b61319d4e`, then `git status --short | wc -l` prints the untracked doc files only.
- [ ] **Step 2:** `git switch -c feat/day-one-data-in origin/main --no-track`. Run `composer install` and `pnpm install` (the lock files moved since `b61319d4e`) and `php artisan migrate`.
- [ ] **Step 3:** Commit the spec and this plan: `git add docs/superpowers && git commit -m "docs: add the day-one data-in design and plan"`.

### Task 1: The data-source step

**Files:**
- Create: `app/Enums/OnboardingDataSource.php`, `database/migrations/<timestamp>_add_onboarding_data_source_to_workspaces_table.php`, `app/Actions/Onboarding/SaveOnboardingDataSource.php`, `app/Filament/Pages/Concerns/RunsSourceStep.php`, `resources/views/filament/pages/setup-workspace/source.blade.php`
- Modify: `app/Enums/WorkspaceSetupStep.php`, `app/Models/Workspace.php` (`@property`, fillable, `casts()`), `app/Actions/Jetstream/CreateWorkspace.php:58`, `app/Filament/Pages/SetupWorkspace.php` (`form()` at 98-103, use the trait), `lang/en/filament/pages/workspaces.php` (`setup_workspace.source.*`)
- Test: `tests/Feature/Onboarding/SetupWorkspaceTest.php`, `tests/Feature/Onboarding/CreateWorkspaceWizardTest.php`

**Interfaces:**
- Produces: `enum OnboardingDataSource: string { Spreadsheet = 'spreadsheet'; OtherCrm = 'other_crm'; Inbox = 'inbox'; Memory = 'memory'; Assistant = 'assistant'; }` with `getLabel(): string` (lang keys), `getDescription(): ?string`, `isFile(): bool` (Spreadsheet, OtherCrm).
- Produces: `Workspace::$onboarding_data_source` (`?OnboardingDataSource`), `Workspace::$onboarding_data_source_detail` (`?string`, max 120).
- Produces: `WorkspaceSetupStep::Source = 'source'`, view `source`.
- Produces: `SaveOnboardingDataSource::execute(User $user, Workspace $workspace, array $input): bool`. It moves `Source` to `Email` when the answer is Inbox and the mailbox step can run (flag on, a provider configured), otherwise to `UseCase`, through `MoveWorkspaceSetup`.

- [ ] **Step 1: Write the failing tests** in `SetupWorkspaceTest.php`:
  - `it('starts a new workspace on the data source step')`: after `workspaceInSetup($user)`, `onboarding_step` is `WorkspaceSetupStep::Source`.
  - `it('stores the data source and moves on to the use case step')`: `fillForm(['onboarding_data_source' => 'spreadsheet'])->call('saveSource')`; the workspace holds `OnboardingDataSource::Spreadsheet` and step `UseCase`.
  - `it('sends the inbox answer to the mailbox step when email integration is on')`: flag on, a provider configured; step becomes `Email`.
  - `it('hides the inbox answer when email integration is off')`: the `onboarding_data_source` options exclude `inbox`.
  - `it('stores which crm the user names, trimmed to 120 characters')`: answer `other_crm` with detail of 130 characters fails validation; 40 characters is stored.
  - `it('refuses a data source from a member who does not own the workspace')`: `joinAsMember`, call `saveSource`, step unchanged.
- [ ] **Step 2:** Run `php artisan test --compact tests/Feature/Onboarding/SetupWorkspaceTest.php --filter='data source|inbox answer|crm the user'`. Expected: FAIL.
- [ ] **Step 3:** Add the migration (two nullable columns, `string('onboarding_data_source', 20)` and `string('onboarding_data_source_detail', 120)`), the enum, the casts, `WorkspaceSetupStep::Source` and its view, `SaveOnboardingDataSource` (compare-and-set through `MoveWorkspaceSetup`, owner check, validation: required enum value, detail nullable string max 120, detail kept only for `other_crm`), and `RunsSourceStep` (a `ToggleButtons` of the five answers with descriptions, a `TextInput` for the detail visible for `other_crm`). The `other_crm` description is the generic line: export people and companies as CSV from your current CRM, and upload the file in the next step. `CreateWorkspace` starts every new workspace at `Source`.
- [ ] **Step 4:** Run the same command, then all of `tests/Feature/Onboarding`. Expected: PASS. Fix existing tests that assumed the first step is `Email` or `UseCase` by advancing through `saveSource` in the helpers (`workspaceInSetup` gains an optional `OnboardingDataSource $source = OnboardingDataSource::Memory`).
- [ ] **Step 5: Rehearse the migration** on anonymized production data (core.md, Database), then commit: `feat(onboarding): ask where the user's clients live as the first setup step`.

### Task 2: Seeding, landing and "do this later" by answer

**Files:**
- Create: `app/Actions/Onboarding/StartSetupLater.php`
- Modify: `app/Actions/Onboarding/SaveOnboardingUseCase.php:86-91` (`seedsSamples()`), `app/Filament/Pages/SetupWorkspace.php:167-174` (`landingUrl()`), `app/Filament/Pages/Concerns/RunsInviteStep.php` (`finish()` and a `finishLater()`), `app/Filament/Pages/Concerns/RunsMailboxSteps.php:59-68` (`canGoBackToMailbox` requires the inbox answer), the invite view, `lang/en/filament/pages/workspaces.php`
- Test: `tests/Feature/Onboarding/SetupWorkspaceTest.php`, `tests/Feature/Onboarding/CreateWorkspaceSeedTest.php`

**Interfaces:**
- Consumes: `OnboardingDataSource`, `Workspace::$onboarding_data_source` (Task 1).
- Produces: `StartSetupLater::execute(User $user, Workspace $workspace): void`. Owner only. Seeds the use case's fixture set through `OnboardSeeder::run()` when the workspace holds no sample and no own record (`WorkspaceActivationFacts`), then forgets the facts.
- Produces: the seeding rule. Samples are seeded when the source is `Memory`, or `Inbox` without a connected mailbox, or `null` (a workspace that started setup before this change). Never for `Spreadsheet`, `OtherCrm` or `Assistant`.

- [ ] **Step 1: Write the failing tests:**
  - `it('seeds samples only for the in my head answer', fn (OnboardingDataSource $source, bool $seeded) => ...)->with([...])`: Memory true, Spreadsheet false, OtherCrm false, Assistant false.
  - `it('seeds samples when the inbox answer skipped the mailbox step')`.
  - `it('seeds as before for a workspace that started setup before the data source step')`: `onboarding_data_source` null, step `UseCase`, samples seeded.
  - `it('sends a file answer to the people import when setup finishes')`: `finish()` redirects to `ImportPeople::getUrl(panel: 'app', tenant: $workspace)`.
  - `it('sends a file answer to the setup chat with samples when the owner chooses later')`: `finishLater()` redirects to the setup conversation, and the workspace now holds samples.
  - `it('sends every other answer to the setup chat when setup finishes')`.
  - `it('offers the way back to the mailbox step only for the inbox answer')`.
- [ ] **Step 2:** Run `php artisan test --compact tests/Feature/Onboarding --filter='seeds|setup finishes|chooses later|way back'`. Expected: FAIL.
- [ ] **Step 3:** Implement `seedsSamples()` with the rule above, `StartSetupLater`, `landingUrl()` returning the people import URL for `isFile()` answers (when the owner holds `data.import`, otherwise the setup chat), and on the invite step for file answers two buttons: "Upload my file" (`finish`) and "Do this later" (`finishLater`, which runs `StartSetupLater` then redirects to the setup chat).
- [ ] **Step 4:** Run the Step 2 command, then all of `tests/Feature/Onboarding`. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(onboarding): seed samples and land the user by where their data lives`.

### Task 3: What Rela knows and says in setup

**Files:**
- Modify: `packages/Chat/src/Agents/CrmAssistant.php` (`onboardingBlock()` 603-641, Rule 19 at 383, Field Truth at 397, `## Setup mode` at 422-430), `packages/Chat/src/Enums/MessageOrigin.php` (Greeting directive at 46, new case), `lang/en/chat.php` or the file holding Rela's fixed copy for the assistant prompt text
- Test: `tests/Feature/Chat/CrmAssistantInstructionsTest.php`, `tests/Feature/Onboarding/SetupConversationTest.php`

**Interfaces:**
- Produces: `MessageOrigin::DataArrived = 'data_arrived'`: `opener()` "Records just arrived in the workspace."; `directive()` one line on what arrived (from the `arrived:` line), then the offer to pick who to work on first, no tools in that turn; label for the transcript.
- Produces: in setup mode, `onboardingBlock()` adds `data_source: <value or none>`, `crm_named: <detail>` when set, `members: <count>`, and `arrived: <N> people and <M> companies from <import|mailbox> today` when records with `creation_source` `import` or `mailbox` were created today in the owner's timezone (counted with model queries scoped to the workspace; sample, chat and web records never count as arrived).

- [ ] **Step 1: Write the failing tests** (instructions are runtime output; assert on the built instructions, not on source):
  - `it('tells rela where the user said their data lives in setup mode')`: Spreadsheet workspace, instructions contain `data_source: spreadsheet`.
  - `it('tells rela how many records arrived today and from where')`: 12 people and 5 companies with `creation_source` import today; instructions contain `arrived: 12 people and 5 companies from import today`.
  - `it('lets rela assign follow-ups to the owner when nobody else is a member')`: setup mode, one member; the Setup mode section states the exception; with `joinAsMember` the line reads `members: 2` and the exception does not apply.
  - `it('greets an inbox workspace with what its mailbox brought in')` in `SetupConversationTest`: assistant fake, the greeting prompt carries the `arrived:` line.
- [ ] **Step 2:** Run `php artisan test --compact tests/Feature/Chat/CrmAssistantInstructionsTest.php tests/Feature/Onboarding/SetupConversationTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement the block lines and rewrite the prompt text:
  - Greeting directive: greet by first name, the stages line as today, then the next step for `data_source` (spreadsheet or another CRM with nothing arrived: their file is the next step; inbox: what arrived; in my head: name the people and deals they work on now; assistant: connect steps); with records arrived, say how many and offer to pick who to work on first. Three short paragraphs at most, no tools.
  - Setup mode rules: "Pick who to work on first" lists today's arrived companies with the companies list tool (filter `creation_source` and `created_at` today), unordered, and asks which to start with. When the user names companies, propose in ONE turn an opportunity per company and a follow-up task per opportunity (`$ref` link), the task's `due_date` the next business day at 09:00 in the user's timezone unless they gave a date, asked once at most. With `members: 1`, `assignee_ids` is the current user; otherwise leave it unassigned as today.
  - Field Truth line 397 gains the setup exception above and nothing else.
  - The assistant answer: give `GuideToPageTool` `connect_assistant` and the fixed copyable prompt from the lang file.
- [ ] **Step 4:** Run the Step 2 command. Expected: PASS. Then run `php artisan test --compact tests/Feature/Chat/CrmAssistantInstructionsTest.php tests/Feature/Chat/NextStepSuggestionTest.php` for regressions.
- [ ] **Step 5:** Commit: `feat(chat): open the setup chat on where the user's data lives`.

### Task 4: The "data arrived" turn and the way back from the import

**Files:**
- Create: `app/Actions/Onboarding/StartDataArrivedTurn.php`, `app/Listeners/StartDataArrivedTurnListener.php`
- Modify: `app/Providers/AppServiceProvider.php` or the event map in use (register the listener on `Illuminate\Notifications\Events\NotificationSent`), `packages/ImportWizard/src/Livewire/ImportWizard.php` (a static `continueUrlUsing(Closure $resolver)` and a `continueUrl()` read by the completed preview), `packages/ImportWizard/resources/views/...` (the completed state of the preview step), `app/Providers/AppServiceProvider.php` (register the resolver)
- Test: `tests/Feature/Onboarding/SetupConversationTest.php`, `tests/Feature/ImportWizard/<existing completion test file>`

**Interfaces:**
- Consumes: `MessageOrigin::DataArrived` (Task 3), `StartSetupGreeting`'s guards as the pattern.
- Produces: `StartDataArrivedTurn::execute(Workspace $workspace, string $sourceKey): bool`. Starts a `ProcessChatMessage` with origin `DataArrived` in the owner's setup conversation when: the setup conversation exists and already holds messages (otherwise the greeting covers it), the workspace is personal, and `Cache::add("chat:data-arrived:{conversationId}:{$sourceKey}", true, now()->addDays(7))` succeeds. Reserves a credit like `StartSetupGreeting`.
- Produces: the listener reacts to the `database` channel of `ImportFinishedNotification` (source key `import:<import id>`, only `ImportOutcome` completed with created rows) and `MailboxHistoryImportCompletedNotification` (source key `mailbox:<batchId>`).
- Produces: `ImportWizard::continueUrlUsing(fn (Import $import): ?string)`. The app resolver returns the setup conversation URL when the import's workspace has one and its data source `isFile()`; the completed state shows a "Continue with Rela" button for a non-null URL.

- [ ] **Step 1: Write the failing tests:**
  - `it('opens a data arrived turn in the setup chat when an onboarding import finishes')`: `Queue::fake()`, a setup conversation with a greeting row, send `ImportFinishedNotification` for a completed import with created rows; `ProcessChatMessage` pushed with origin `DataArrived`.
  - `it('opens one data arrived turn per import')`: the notification sent twice; pushed once.
  - `it('leaves the turn to the greeting when the setup chat was never opened')`: no messages; nothing pushed.
  - `it('opens a data arrived turn when the mailbox history import completes')`.
  - `it('shows continue with rela after an onboarding import completes')` in the ImportWizard tests: completed state renders the setup chat URL; for a workspace past onboarding with no file answer it renders nothing.
- [ ] **Step 2:** Run the new tests by file. Expected: FAIL.
- [ ] **Step 3:** Implement the action, the listener and the resolver hook as above.
- [ ] **Step 4:** Run the Step 2 tests, then `tests/Feature/ImportWizard` and `tests/Feature/Onboarding`. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(onboarding): bring the user back to Rela when their data arrives`.

### Task 5: Server-picked path buttons

**Files:**
- Create: `packages/Chat/src/Services/SetupOptions.php`, `packages/Chat/src/Data/SetupOption.php`
- Modify: `packages/Chat/src/Jobs/ProcessChatMessage.php` (`suggestNextSteps()` 1077-1100), `packages/Chat/src/Support/NextSteps.php`, `packages/Chat/src/Queries/ConversationMessagesQuery.php:122`, `lang/en/chat.php` (button labels and prompts)
- Test: `tests/Feature/Chat/SetupOptionsTest.php` (new; no existing file covers setup buttons), `tests/Feature/Chat/NextStepSuggestionTest.php`

**Interfaces:**
- Produces: `final readonly class SetupOption { id: string, kind: 'prompt'|'link', label: string, ?prompt, ?url, icon: string }`, `toArray(): array`.
- Produces: `SetupOptions::for(User $user, Workspace $workspace): list<SetupOption>`, at most 3, by effective source (Inbox without a connected mailbox counts as Memory) and whether records arrived today:
  - Memory: `add_client` "Add a client" (prompt), `add_deal` "Add a deal" (prompt).
  - Inbox: `show_synced` "Show my synced companies" (prompt), `add_outside_email` "Add someone not in my email" (prompt).
  - Assistant: `copy_prompt` "Copy the prompt" (prompt that asks Rela for the copyable text), `connect_steps` "Open connection steps" (link, `connect_assistant`).
  - Spreadsheet or OtherCrm with nothing arrived: `upload_file` "Upload my file" (link, `import_people`, only with `data.import`).
  - Any source with records arrived today: `pick_first` "Pick who to work on first" (prompt), `later` "Later" (prompt).
  - A link whose `DestinationResolver::resolve()` is null, or whose capability the owner lacks, is dropped.
- Produces: `NextSteps::fromMeta()` returns `list<array{id: ?string, kind: string, label: string, prompt: ?string, url: ?string, icon: ?string}>`; stored rows without `kind` read as `prompt` with null id, url and icon.
- Produces: in `ProcessChatMessage::suggestNextSteps()`, a setup conversation writes `SetupOptions::for()` to the assistant row's `meta['next_steps']` and broadcasts `NextStepsSuggested`; `SuggestNextSteps` is not dispatched for it. Other conversations are unchanged.

- [ ] **Step 1: Write the failing tests** (`SetupOptionsTest.php`, with `mutates(SetupOptions::class)`):
  - `it('offers the memory buttons to an in my head workspace')`.
  - `it('offers the memory buttons to an inbox workspace whose mailbox step was skipped')`.
  - `it('offers pick who to work on first once records arrived today')`.
  - `it('never offers an import button to an owner without data import')` (Review Focus 5).
  - `it('drops a link whose page is unavailable')`: email integration off, no option links to `email_accounts` (Review Focus 3).
  - `it('writes setup buttons instead of suggested next steps in the setup chat')`: a setup conversation turn with no proposal; the assistant row's `meta.next_steps` equals `SetupOptions::for()`; `SuggestNextSteps` not pushed.
  - `it('reads next steps stored before buttons had a kind')`: `NextSteps::fromMeta(['next_steps' => [['label' => 'A', 'prompt' => 'B']]])` returns kind `prompt`, id null.
- [ ] **Step 2:** Run `php artisan test --compact tests/Feature/Chat/SetupOptionsTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement `SetupOption`, `SetupOptions`, the `NextSteps` shape and the branch in `suggestNextSteps()`.
- [ ] **Step 4:** Run the file, then `tests/Feature/Chat/NextStepSuggestionTest.php`. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(chat): give the setup chat buttons picked from the workspace`.

### Task 6: Buttons in the setup chat, and counting clicks

**Files:**
- Modify: `packages/Chat/src/Livewire/Chat/ChatInterface.php` (expose `isSetupConversation` from `AgentConversation::isSetup()`), `packages/Chat/resources/views/livewire/chat/partials/_transcript.blade.php:626-650`, `packages/Chat/resources/js/chat/transcript.js:243-248` (`sendNextStep` passes `step.id`), `packages/Chat/resources/js/chat/send.js` (include `next_step` in the payload), `packages/Chat/src/Http/Controllers/ChatController.php:78` (validate `next_step`), `packages/Chat/src/Jobs/ProcessChatMessage.php` (persist `meta['next_step']` on the user row, beside `attachment` at 882-888)
- Test: `tests/Feature/Chat/<controller send test file>`, `tests/Browser/Chat/SetupButtonsTest.php` (new)

**Interfaces:**
- Consumes: `SetupOption` ids and kinds (Task 5).
- Produces: `next_step` request field, `nullable|string|max:40|regex:/^[a-z_]+$/`, stored as `agent_conversation_messages.meta.next_step` on the user row.

- [ ] **Step 1: Write the failing tests:**
  - `it('records which setup button sent a message')`: post to the send route with `next_step: 'pick_first'`, run the job; the user row's `meta->next_step` is `pick_first`.
  - `it('rejects a malformed next step id')`: `next_step: 'Pick First!'` returns 422.
  - Browser, `SetupButtonsTest`: in a setup conversation the buttons render bordered under the latest reply, a link button is an anchor with the resolved URL, a prompt button sends its prompt; in a normal conversation the strip renders as today.
- [ ] **Step 2:** Run the feature tests and `php artisan test --compact tests/Browser/Chat/SetupButtonsTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement the setup variant of the strip (bordered buttons with the option's icon, placed under the latest assistant message, `<a>` for `link`, existing button behavior for `prompt`), the payload field, validation and persistence. Build assets with `pnpm run build`.
- [ ] **Step 4:** Run the Step 2 tests in Chromium, then `php artisan test --compact tests/Browser/Chat/SetupButtonsTest.php --browser safari`. Expected: PASS in both.
- [ ] **Step 5:** Commit: `feat(chat): show setup buttons as buttons and count their clicks`.

### Task 7: End-to-end proof

- [ ] **Step 1:** Repro harness from the investigation (`repro.php` in the session scratch directory: real `CreateWorkspace`, the setup actions, a synchronous greeting, Sonnet 5), one run per answer, with and without mailbox-sourced records. Expected per spec: the greeting names arrived counts when present; every no-proposal setup reply carries `SetupOptions` buttons; "Pick who to work on first" followed by two company names yields one plan card with two opportunities and two tasks due the next business day, assigned to the owner.
- [ ] **Step 2:** The collision check from the spec: in a Memory workspace with samples, "add Jane at Notion" must not link Jane to the sample company. If it does, add the setup rule that lookups skip `creation_source = sample` once the user has an own record, with a test in Task 3's file.
- [ ] **Step 3:** Browser walk on the production-shaped stack (Horizon on the `chat` queue, Reverb, Redis): signup, each answer to its landing, a 50-row people CSV with a company column through the wizard, "Continue with Rela", the follow-up card approved, then `php artisan notifications:send-task-digest` at the owner's local 08:00 (`travelTo`) and the digest mail rendered with the tasks. Chromium and WebKit, light and dark, desktop and mobile; screenshots in `.context/`.
- [ ] **Step 4:** Gates: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:lint`, `composer test:arch`, `composer test:affected`. Push, open the PR with a complete body (owner's PR rules), and watch the `Tests` workflow in the background.
- [ ] **Step 5:** Delete the local "Rela FirstReply QA" users and workspaces created during the investigation.
