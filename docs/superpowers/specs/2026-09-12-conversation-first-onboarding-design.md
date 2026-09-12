# Conversation-first onboarding

Date: 2026-09-12
Branch: `ManukMinasyan/automate-trial-onboarding`
Status: draft for review

## 1. Goal

Replace the proposed day-0 human setup call with a product flow that does the same five jobs without a person: real data in on day 0, a pipeline shaped to the use case, the AI connected, the offer made, and a record of why people leave.

The measurable target is the day-0 activation rate: the share of new personal workspaces that hold at least one own (non-sample) record within one day of creation. It has been flat at 15 to 20% per cohort for 16 months. The first paying customer converted three hours after signup. Nobody who went cold ever came back.

## 2. Evidence this design rests on

Analytics clone, personal workspaces created in the last 90 days (n=402), unless stated.

| Fact | Value |
|---|---|
| Own record within the window | 15 to 20%, flat across cohorts |
| Chat users who send exactly one message | 40% |
| Own record given day-0 chat vs no chat | 29.6% vs 8.9% |
| Use case answered Sales / Other / Customer Success / Marketing | 48% / 26% / 12% / 7% |
| Activation by use case | 12 to 20%, no answer predicts activation |
| Recruiting, investing, fundraising workspaces | 10 / 10 / 5 (too small to read) |
| Attribution skipped | 28% |
| Activation by referral: friends / AI / Google / Reddit | 27% / 19% / 16% / 6% |
| Workspaces that invited anyone in the wizard | 9 of 402 |
| Workspaces that imported anything | 0.5% |
| Workspaces seeded with 21 sample rows | 97% |
| Trials since billing went live on 2026-08-09 | 155 |

Code facts verified on 2026-09-12:

- The use-case answer picks the sample fixture set and a Mailcoach tag. Nothing else reads it. The stage list is one hard-coded sales list for every workspace (`app/Enums/CustomFields/OpportunityField.php`).
- The sub-options (`onboarding_context`) are stored and read by nothing. Their stale-state bug already needs a workaround in the wizard.
- The chat agent is never told the use case. Its `<workspace_state>` block only says whether the workspace holds sample data.
- The dashboard composer starts a new conversation on every send (`sessionStorage 'chat:bootstrap'` then navigate to `chats/{conversationId?}` without an id).
- A plan card's Approve all approves a multi-record step in one click. Limits: 25 records per step (`chat.max_batch_size`), 6 steps per turn (`chat.max_plan_steps`).
- Pending proposals expire after 24 hours (`chat.pending_action_expiry_minutes`). The setup nudge fires at 48 to 72 hours and links to the dashboard, not a thread.
- The auto failover chain is Sonnet 5 then GPT 5.5, both tool-capable. Gemini is not in the chain.
- Trial workspaces are Pro: 2,000 credits, 30 requests a minute. A setup session has not been priced.
- Entity labels are static lang strings. "Opportunities" cannot be renamed per workspace.
- No welcome-conversation code exists on main or on the unmerged `onboarding-workspace-setup-chat` branch (65 commits behind). The opener is a rebuild.
- The composer has no file attachment. It posts a TipTap JSON document by fetch to `POST /chat/{conversation}`; the message `attachments` column is always `[]`. The AI SDK can attach documents, and the Anthropic gateway accepts PDF or UTF-8 text.
- The import wizard takes CSV or TXT up to 10 MB through a Livewire upload, and its upload step can resume from a pre-created `Import` row (`storeId`), skipping the upload.
- PR 237 (`feat/email-calendar-integration`, Asmit, open, not draft, updated 2026-09-11) adds Gmail and Microsoft mailbox sync behind `RELATICLE_FEATURE_EMAIL_INTEGRATION`. Its initial sync backfills 90 days and creates contacts per the workspace's contact creation mode (all, selective, none). All CI checks pass. It still carries a changes-requested review from 2026-04-24 with eleven critical items, five of them cross-tenant or XSS; whether they were fixed was not verified on 2026-09-12. It is not a dependency of slices 1 to 3.
- The conversation store replays every non-superseded message row to the provider as-is (`SupersededAwareConversationStore::table()` scopes on `superseded_at`). Anthropic rejects a request whose first message is an assistant turn, so a stored opener must be excluded from replay.
- A user-facing Connect Claude or ChatGPT guide exists at `help/ai-assistant/connect-claude-or-chatgpt`, and the Access tokens page lists connected assistants. `DestinationResolver` has no key for either.
- The analytics clone rewrites team names matching `^.*'s Team$` to "Personal Workspace" and nothing else. A default workspace name that carries a first name under any other suffix would reach the clone unmasked.
- The copy-invite-link action, the precreation path (`COMPLETING_SESSION_KEY`, the "complete previous steps" notification) and `CreateTeamPrecreationTest` all live inside the invite step.

## 3. Decisions

1. Conversation-first. Rela speaks first, inline on the dashboard, in one seeded conversation the composer continues. No auto-opened panel, no redirect (the August research argued against both).
2. The opener is templated text rendered on page load. The model runs only after the user replies.
3. The first reply to pasted contacts is a create proposal. No clarifying question comes first.
4. Setup mode is create-only for the whole lifetime of the setup conversation. Update and delete tools are never offered there. Ending it at the first own record would reopen the hole on the second paste.
5. "How did you hear about us" stays optional and unchanged in the wizard. The AI answer now routes a Connect Claude offer into the opener.
6. "What will you be using Relaticle for" stays required. Its answer now picks the stage preset, the sample set, and the opener vocabulary. Other gets one free-text line.
7. The sub-options question stays, reduced to one axis per use case so answers cannot contradict, multi-select, still required, still stored in `onboarding_context`, validated against the chosen use case, and read by Rela's prompt block. The wizard asks no question about where the user's contacts are.
8. The opener offers every way in at once: paste, attach a file, describe a few people, or read the self-hosting guide. The user answers by acting. A wizard question that only varied one paragraph of copy did not earn a required step.
9. The invite step is removed from the wizard. Invites stay in team settings, on the checklist, and in Rela's flow after data lands.
10. Sample data stays. Once real data lands, a one-click Remove sample data action appears.
11. Credits are charged normally in v1. Per-conversation cost is recorded and reviewed after two weeks.
12. Everything in slices 2 and 3 ships behind one Pennant feature, off in production until verified in a real browser against Horizon, Redis, and Reverb.
13. The chat is the door for data. The composer accepts a CSV or TXT file. Twenty-five rows or fewer are handed to the model as text, the same as a paste. More rows open the import wizard with the file already loaded.
14. Once PR 237 lands, the opener gains a line for connecting a Google or Microsoft mailbox, and the sync creates the contacts. That is slice 4 and is not built here.

## 4. Scope

In scope: the signup wizard, workspace shaping at creation, the seeded setup conversation, the dashboard opener, setup mode in the agent, a CSV or TXT attachment in the composer with the import wizard handoff, proposal expiry for setup, the day-2 nudge, the exit question, and a SystemAdmin funnel view.

Out of scope (later work, not this spec): XLSX or other spreadsheet formats, Google contacts import, mailbox sync (slice 4, after PR 237), per-workspace entity renaming, credit exemption for setup, an undo-everything action, pricing or offer changes, quick-reply buttons inside the chat surface.

## 5. Design

### 5.1 Signup wizard

Steps after the change: Workspace, Attribution, Set up your workspace. Three steps, down from four.

Workspace step. Your name appears only on first-run signup, prefilled from the account; a user who already has a workspace does not see it. Workspace name and handle start empty: the user names their own workspace. A shared default ("My workspace") was tried and dropped, because it named every workspace the same thing and pushed every signup onto one handle family. The handle is still derived from the name as it is typed, and stays unique-validated. All three stay required.

Attribution step. Unchanged.

Set up your workspace step. "What will you be using Relaticle for?" Existing enum, required, single choice. When Other is chosen, one optional text input appears: "What will you track?", max 120 characters, stored in `teams.onboarding_other_use_case`.

Under any other use case, the sub-options appear: "Pick what applies to you.", multi-select, required, one axis per use case so the picks describe one thing:

| Use case | Options |
|---|---|
| Sales | Outbound, Inbound, Product-led, Partner-led |
| Customer Success | High-touch, Low-touch |
| Recruiting | Applications, Sourcing |
| Marketing | Content, Demand gen, Events, Partnerships |
| Fundraising, Investing | Early-stage, Growth-stage, Late-stage |

Stored as before in `teams.onboarding_context` (list of option values). Switching the use case clears the picks. The action rejects values that do not belong to the chosen use case. Historical rows keep whatever values they hold; the old segment values (SMB, Mid-market, Enterprise) are no longer offered.

Removed: the sub-options field and the invite step. `CreateTeam::getInviteStep()` goes with everything inside it: the invites repeater, the copy-invite-link action, the precreation path that created the team early so a link could be copied (`COMPLETING_SESSION_KEY` and its session handling), the "complete previous steps" notification strings, and the pending-invites submit label. The submit label is always Get started. `CreateTeamPrecreationTest` goes. The sub-options `afterStateUpdated` reset and its comment go with the field. Invite links stay available on the team members page.

The wizard is reused for additional workspaces and is never shown to invited members. That does not change.

### 5.2 Shaping at creation

`CreateTeamCustomFields` already runs synchronously on `TeamCreated` and reads the use case. It gains one behaviour: when creating the opportunity Stage field, it uses the preset for the team's use case instead of the enum's default options.

Presets live on `OnboardingUseCase::stagePreset()` returning `array<string, string>` of option name to colour. Four lists, shared where the vocabulary fits:

| Preset | Used by | Stages |
|---|---|---|
| Sales (current list, unchanged) | Sales, Marketing, Other, null | Prospecting, Qualification, Needs Analysis, Value Proposition, Id. Decision Makers, Perception Analysis, Proposal/Price Quote, Negotiation/Review, Closed Won, Closed Lost |
| Customer Success | Customer Success | Onboarding, Active, Renewal due, At risk, Renewed, Churned |
| Recruiting | Recruiting | Sourced, Applied, Screen, Interview, Offer, Hired, Declined |
| Fundraising | Fundraising, Investing | Target, Intro, First meeting, Partner meeting, Term sheet, Closed, Passed |

Colours reuse the existing Stage palette. Stage stays an ordinary editable select field, so a wrong answer on day 3 is fixed in the field settings like any other option list.

Sample fixtures must use the preset's stage names. Fixture sets after the change: `sales` (Sales, and the listener's fallback for a null use case), `marketing`, `general` (Other), `recruiting`, `fundraising` (Fundraising, Investing), and a new `customer_success` set with four accounts and four renewals. Marketing keeps its own directory with sales stage names, since it shares the Sales preset. `OnboardingUseCase::getFixtureSet()` maps Customer Success to the new set. The recruiting and fundraising opportunity fixtures change their `stage` values to preset names.

Entity labels do not change. "Candidates" and "Investors" live in the opener copy and the agent's vocabulary, not in the sidebar.

### 5.3 The setup conversation

On `TeamCreated`, for a personal team whose owner is a user, and when the feature is on, a listener creates one `AgentConversation` for the owner titled from the lang file ("Set up your workspace") with `purpose = 'setup'`. A partial unique index on `agent_conversations (team_id) where purpose = 'setup'` guarantees one per team. The dashboard, the nudge, and the agent find it by that column. No foreign key on `teams`.

The conversation holds a single assistant message: templated, built with no model call, stored with `meta.kind = 'setup_opener'`. The store's messages scope excludes that kind from provider replay, the same way it excludes superseded rows, so the model never receives a leading assistant turn. The chat page still renders the row from the database, so the opener reads as the first message of the thread. The `<onboarding>` block (section 5.5) carries the same facts to the model.

The message is composed from a use-case line, one fixed data paragraph, and optional lines. All copy lives in `lang/en/onboarding/setup.php` and passes the i18n rules.

Use-case line:

| Use case | Line |
|---|---|
| Sales, Marketing | Your pipeline is ready: Prospecting through Closed Won. |
| Customer Success | Your accounts board is ready: Onboarding through Renewed. |
| Recruiting | Your candidate pipeline is ready: Sourced through Hired. |
| Fundraising | Your investor pipeline is ready: Target through Closed. |
| Investing | Your deal flow is ready: Target through Closed. |
| Other with text | Your workspace is ready for tracking {text}. |
| Other without text, null | Your workspace is ready. |

Data paragraph, the same for everyone:

"Paste your contacts here or attach the file, any columns, any order. I'll map them and show you what I'll create before anything is saved. Large files go straight to the import wizard with the mapping ready. No list yet? Tell me about three people you're talking to right now. Names and companies are enough."

Closing line, the same for everyone: "Just evaluating, or self-hosting? The sample records show how a working pipeline looks, and the self-hosting guide is here: {docs link}."

Mailbox line, only when `RELATICLE_FEATURE_EMAIL_INTEGRATION` is on (slice 4), placed after the data paragraph: "Or connect your Google or Microsoft mailbox and I'll create contacts from the people you already write to: {accounts link}."

Optional line, when attribution is AI: "You can also work from Claude or ChatGPT directly: {connect link}." The link is the help page `help/ai-assistant/connect-claude-or-chatgpt`.

The {text} value is user input. It is rendered escaped in the message and quoted as data in the prompt (section 5.5). It is never concatenated into an instruction.

### 5.4 The dashboard opener

When the team has a setup conversation, the viewer is its owner, the conversation has no user message, and `onboarding_opener_dismissed_at` is null, the dashboard shows the opener above the composer in place of the time-of-day greeting. The composer is focused. One secondary link, Not now, sets `onboarding_opener_dismissed_at` and returns the dashboard to its current layout.

The composer's bootstrap payload gains `conversationId`. When set, the dashboard navigates to `chats/{conversationId}` and the chat page sends the bootstrapped message into that conversation. When absent, behaviour is unchanged.

After the first user message the dashboard shows its current layout. The existing recent-chat link on the dashboard points at the setup conversation, labelled Continue setup, until the workspace has an own record.

Invited members and additional workspaces never see the opener.

### 5.5 Setup mode in the agent

`CrmAssistant::dynamicInstructions()` gains an `<onboarding>` block when the team has a use case:

```
<onboarding>
use_case: Recruiting
context: Applications, Sourcing
stages: Sourced, Applied, Screen, Interview, Offer, Hired, Declined
other_use_case: "<escaped user text>"
setup_mode: true
</onboarding>
```

The `context` line lists the labels of the stored sub-options and is absent when none are stored.

`setup_mode` is true whenever the conversation is the team's setup conversation, for its whole lifetime. In setup mode:

- The tool list for the turn excludes every record Update and Delete tool, `CreateCustomFieldTool`, and `UpdateCustomFieldTool`. Read tools, record Create tools, `AddCustomFieldOptionsTool`, `GuideToPageTool`, and `SearchDocsTool` remain.
- The instructions say: when the user pastes contacts, the first reply proposes their creation. Do not ask a clarifying question first. When the paste implies stages the preset lacks, propose adding them with `AddCustomFieldOptionsTool` in the same turn, after the records. When the paste exceeds 25 rows, or the user mentions a file, give the import wizard link through `GuideToPageTool` and propose the first 25.
- The instructions say: when the user describes people in prose instead of pasting a list, propose them from the description, and ask for at most one missing detail per record only if a name is absent.
- The instructions say: when the user asks to change or delete a record here, link the record from the read tool's result and say that edits happen on the record page or in a new conversation. Never answer that it is unsupported.

`DestinationResolver` gains `access_tokens` (the Access tokens page) and `connect_assistant` (the Connect Claude or ChatGPT help page), so the model can guide to them in any conversation.

Nothing changes in the proposal machinery. Approve all on the plan card already approves a 25-record step in one click.

### 5.6 File attachment in the composer

The composer gains an attach button and drop target for one CSV or TXT file, 10 MB at most, the same limits as the import wizard. The file uploads first, through a new `POST /chat/attachments` route, to the team's private disk. `Relaticle\Chat\Actions\StoreChatAttachment` validates the mime type and size, parses the header row and counts rows, and returns an attachment id. The composer then sends the message with that id in the payload. A user can attach only to their own team's conversations, and an attachment id is single-use.

What happens next depends on the row count:

- Twenty-five rows or fewer: the rows are appended to the user message as a fenced plain-text block before the model runs. The model sees a paste. No provider document attachment is used, so no gateway-specific behaviour is involved.
- More than twenty-five rows: the model does not run. The chat stores a templated assistant reply: "That's {n} rows. The import wizard handles files this size, with your columns already mapped." followed by two links, Import as people and Import as companies. Each link opens that entity's import wizard with `?attachment={id}`. The wizard's mount creates the `Import` row and the import store from the stored file for that entity and resumes at the mapping step, the path the upload step already supports through `storeId`. The stored file is deleted once the import row exists or after 24 hours, whichever comes first.

The attach button exists in every conversation, not only the setup one. Outside setup mode the same two rules apply.

### 5.7 Sample data after real data

`App\Actions\Onboarding\RemoveSampleData` deletes every record on the team with creation source `system` across companies, people, opportunities, tasks, and notes, through the models so custom field values and pivots follow. The activation checklist shows a Remove sample data button when the team has sample data and an own record. Authorization: team owner only.

### 5.8 Resume

Setup proposals expire after 7 days instead of 24 hours. New config `chat.setup_pending_action_expiry_minutes` (10080). `PendingActionService` applies it when the action's conversation has `purpose = 'setup'`.

The day-2 nudge (`SendSetupNudgeCommand`) links to the setup conversation when the team has one, otherwise to the dashboard as today. Subject and first line come from the use-case line in section 5.3. Schedule and eligibility do not change.

Trial expiry and the paused state do not change. Data is preserved today.

### 5.9 The exit question

The nudge mail gains one link under "What stopped you?". It opens a signed, team-bound landing page with four buttons and no login. Clicking a button POSTs to a route that records the answer through `App\Actions\Onboarding\RecordExitReason`, which writes `teams.onboarding_exit_reason` and `onboarding_exit_answered_at`, then shows a one-line thank-you. Nothing is written on GET, so a mail scanner that follows the link records nothing. New enum `App\Enums\OnboardingExitReason`:

| Case | Label |
|---|---|
| `no_data_yet` | I have no data to add yet |
| `could_not_import` | I couldn't get my data in |
| `just_looking` | Just looking, or self-hosting |
| `not_a_fit` | Not what I need |

The signature is valid for 14 days. A second answer overwrites the first. No message content is ever read for this.

### 5.10 SystemAdmin funnel view

A new SystemAdmin page, Onboarding funnel, lists personal workspaces created in the last 90 days with: created at, use case, other text, referral, opener replied (setup conversation has a user message), first proposal approved (any approved pending action in the setup conversation), attached a file, own record (activation facts), imported, exit reason, plan, paying. Filters on use case, referral, and exit reason. One aggregate table above it: activation rate by use case and by referral.

SystemAdmin is excluded from PHPStan. Every `match` over `OnboardingExitReason` in that package is swept by hand.

### 5.11 Feature flag

`App\Features\SetupConversation`, a Pennant class like the existing ones, read from `RELATICLE_FEATURE_SETUP_CONVERSATION`. Default true locally, false in production. It gates sections 5.3, 5.4, 5.5 setup mode, 5.6, 5.7, 5.8 expiry and nudge link, and 5.9. The `<onboarding>` prompt block without `setup_mode` and the wizard changes are not gated.

## 6. Data model

Migration, up only, on `teams`:

| Column | Type | Purpose |
|---|---|---|
| `onboarding_other_use_case` | string 120, nullable | Section 5.1 |
| `onboarding_opener_dismissed_at` | timestamp, nullable | Section 5.4 |
| `onboarding_exit_reason` | string, nullable | Section 5.9 |
| `onboarding_exit_answered_at` | timestamp, nullable | Section 5.9 |

Migration, up only, on `agent_conversations`:

| Column | Type | Purpose |
|---|---|---|
| `purpose` | string, nullable, partial unique index on `team_id` where `purpose = 'setup'` | Section 5.3 |

New table `chat_attachments` (section 5.6): id, team_id, user_id, conversation_id nullable, disk path, original name, mime, size, row_count, header jsonb, consumed_at nullable, timestamps. Rows and files are pruned by the existing hourly import cleanup command's schedule slot, 24 hours after creation.

`onboarding_context` keeps its column and cast and is written as before. Timestamps are written from PHP `now()`.

`Team` casts `onboarding_exit_reason` to its enum. `onboardingSubscriberTags()` and `SubscriberProfileDeriver` are unchanged; they read use case and referral, both of which stay.

## 7. Failure handling

- Model down or slow: the opener is static, and the composer is a form. The user can still paste; the turn fails the way any chat turn fails today, with the existing retry.
- Paste over 25 rows: the model proposes 25 and links the import wizard. The import wizard's own mapping and recovery handle the rest.
- Attached file over 25 rows: no model call, templated reply with the two wizard links, file pre-loaded.
- Attached file wrong type, over 10 MB, not UTF-8, or with no header row: rejected at upload with the same messages the import wizard uses. Nothing is sent.
- Attachment id reused, expired, or from another team: the send is rejected with a validation error; the message is not stored.
- Injection in pasted text: the setup conversation never offers an update or delete tool, and creates still go through proposal approval.
- Wrong use case: Stage is an editable field. Sample data is one click to remove.
- Setup conversation deleted by the user: no row carries `purpose = 'setup'` any more, the dashboard shows its current layout, the nudge falls back to the dashboard link.
- Exit link forwarded, scanned, or answered twice: signed, team-bound, GET writes nothing, a second POST overwrites, no side effect beyond the two columns.
- Team deleted: all new columns go with the row. The conversation cascades as today.

## 8. Testing

Feature suite, extending existing files where they cover the scope:

- `tests/Feature/Onboarding/CreateTeamWizardTest.php`: three steps, Other text saved and capped, sub-options on one axis (stored, required for use cases that have them, cleared on switch, foreign values rejected), workspace name default. `CreateTeamInvitationTest.php` cases that exercised the removed step are removed, and `CreateTeamPrecreationTest.php` goes with the precreation path; team-settings invite tests already cover invites.
- `tests/Feature/Onboarding/CreateTeamSeedTest.php`: stage preset per use case, fixture stage names match the preset, customer_success set seeds.
- New `tests/Feature/Onboarding/SetupConversationTest.php`: conversation seeded for a personal team owner only, one per team, message composed per use case, with and without Other text, with and without the AI attribution line, mailbox line absent while the email flag is off, flag off seeds nothing, the opener row is absent from the messages the store replays to the provider.
- New `tests/Feature/Onboarding/DashboardOpenerTest.php`: opener shown and hidden by each condition, Not now dismisses, bootstrap payload carries the conversation id, member and second workspace never see it.
- `tests/Feature/Chat/`: `<onboarding>` block content and escaping of the Other text, setup mode tool list excludes update, delete, and custom-field create and update, setup mode persists after the first own record, setup expiry applied only to the setup conversation, the two new guide destinations resolve.
- New `tests/Feature/Chat/ChatAttachmentTest.php`: upload accepts CSV and TXT within limits and rejects the rest, a 25-row file is inlined as text in the stored user message, a 26-row file stores the templated reply and no model run, the wizard link creates the import for the chosen entity and lands on mapping, cross-team and reused ids are rejected, pruning removes file and row after 24 hours.
- `tests/Feature/Onboarding/` for the nudge and exit: link targets the setup conversation, subject per use case, the landing page renders without login, GET writes nothing, POST writes the reason, a bad signature is rejected, a second POST overwrites.
- `tests/Feature/SystemAdmin/`: funnel page renders for a sysadmin and is invisible otherwise, matching the existing SystemAdmin test depth.
- `tests/Browser/`: two critical paths. Sign up, answer Recruiting, see the opener, paste five rows, approve, see five own people and the checklist row complete. Then attach a 40-row CSV in the same conversation, follow Import as people, and land on the mapping step with the columns shown.

Chat rules apply before anything is reported done: Horizon on, `QUEUE_CONNECTION=redis`, Reverb up, the full loop walked in a real browser, light and dark, mobile viewport for the opener.

## 9. Metrics

All on the analytics clone, provenance is this section.

- Primary: own record within one day of creation, by use case and by referral. `analytics_entity_counts` with `creation_source <> 'system'` and `created_on <= created_at::date + 1`.
- Opener reply rate: setup conversations with a user message, over workspaces with a setup conversation.
- Proposal approval rate: setup conversations with an approved pending action, over those with a user message.
- Return on day 2 or later: existing definition.
- Exit reason distribution, by use case.
- Way in: share of setup conversations whose first user message was a paste, an attachment, or prose, from the stored message shape. No content is read.
- Paying: Cashier active including past due, by use case.
- Cost: credits consumed per setup conversation, median and p90, from the run telemetry. Review at two weeks. If the median exceeds 200 credits, a credit exemption becomes the next piece of work.

## 10. Delivery order

Four slices, each its own implementation plan and PR.

1. Wizard and shaping. Sections 5.1, 5.2, the `<onboarding>` prompt block without setup mode, the migration column for Other text. Not flagged. Visible change: three-step wizard, use-case stages, Rela knows the use case.
2. The setup conversation and the file door. Sections 5.3, 5.4, 5.5 setup mode and destinations, 5.6, 5.7, 5.8 expiry, the flag, the `purpose` column, `chat_attachments`, and `onboarding_opener_dismissed_at`. Flagged.
3. Resume and learning. Sections 5.8 nudge link and copy, 5.9, 5.10. Flagged.
4. The mailbox path. After PR 237 merges: the opener's mailbox line, a `connect_mailbox` guide destination, and the accounts link. Gated on both flags. Not planned until PR 237 is merged.

Slice 1 ships value on its own and does not depend on the flag.

## 11. Open points for review

These are embedded decisions, not blockers. Say so if any is wrong.

- The invite step leaves the wizard entirely, including for additional workspaces, and the copy-invite-link precreation path goes with it.
- The wizard asks nothing about where the contacts are. The opener offers every way in.
- The sub-options stay, one axis per use case, multi-select, required. The founder chose to keep them on 2026-09-12 once they do work.
- Your name is hidden for anyone who already has a workspace.
- The workspace name and handle have no default. The user types the name; the handle follows from it.
- Marketing shares the Sales preset and fixtures.
- Credits are charged during setup in v1.
- An undo-everything action is deferred; the proposal preview and ordinary delete cover v1.
- A large attached file asks the user to pick people or companies; the model does not guess the entity from the header.
- CSV and TXT only in the composer, matching the wizard. XLSX waits until the wizard takes it.
