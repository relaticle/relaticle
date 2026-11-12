# Day one: get the user's real data in, and give them a reason to come back

Date: 2026-11-07. Status: design, all nine decisions made 2026-11-07, waiting for approval of this document.

## Why

A new workspace that holds the user's real data on day one is the one that comes back. A workspace
with one token record is not.

Measured on the analytics clone (stamp 2026-11-06T16:11Z), personal workspaces created Aug 1 to
Oct 22, excluding the trial-farming signature (Tehran and Moscow time zones) and our own four
workspaces: 446 workspaces. "Came back" means wrote an own record again within 14 days (the clone
has no session data, so reading without writing is invisible). Own records exclude creation
sources `sample` and `mailbox`, as the activation metric already does.

| Day-one signal | Workspaces | Came back |
|---|---|---|
| Any own record ("activated" today) | 66 | 13 (20%) |
| A Rela proposal approved by end of day 1 | 36 | 9 (25%) |
| 5+ own records by end of day 1 | 25 | 9 (36%) |
| 10+ own records by end of day 1 | 18 | 8 (44%) |
| Everyone | 446 | 13 (3%) |

- The 382 workspaces with no own record by end of day 1 produced 0 later writers and 0 payers.
  Both outside payers in the window had 20+ records on day one.
- Among workspaces that wrote anything on day one, 10 of 34 whose records included a task or an
  opportunity came back, against 1 of 30 with only people and companies.
- The October funnel (133 real workspaces created Oct 1 to 22): 117 finished onboarding, 116 saw
  Rela's greeting, 47 typed, 17 got a proposal, 29 wrote an own record, 2 wrote again later.

The doors that worked differ by person. Of the 18 workspaces with 10+ records by end of day 1:
chat 11 workspaces (182 records), web forms 6 (16), import 5 (4,464), Claude or ChatGPT over MCP
4 (670), API 1 (1,226). Most used two.

Today each door has a leak:

- The onboarding mailbox step loses people: of 236 personal workspaces since Oct 1, 28 connected
  a mailbox and 30 stopped at that step for good. Synced records do not count as the user's own,
  and synced-only users came back 0 of 9.
- The import wizard finishes what it starts (53 of 54 imports completed, 19,758 records) but few
  find it: 6 of 591 new workspaces ran one. A file dropped in chat is handed off to the import
  page (`StoreImportHandoff`): 15 workspaces attached one since Sep 16 and 4 ever imported.
- The setup chat answers every opener without data with the same request for a paste. In a live
  repro on the current prompt (Sonnet 5, the real signup actions, the setup greeting), "hi",
  "I want to import my contacts", "help me set up my 5 deals", a description of the business and
  "what can you do?" got 0 proposals in 10 runs; "add Acme Corp as a company" got 3 of 3. With 30
  people and 12 companies synced from a mailbox, Rela never mentioned them (4 of 4 replies).
- Nothing brings a user back on day two. The task digest (`SendTaskDigestCommand`,
  `DigestService`) lists only tasks assigned to the user with a due date, and Rela's prompt says
  never to assign a task to the current user unless asked. The setup nudge goes only to empty
  workspaces (1 record from 108 mails in September).

The figures come from ad-hoc queries over aggregates; no chat or email text was read.

## Goal and target

- **Goal:** the share of new personal workspaces that hold 10 or more own records by the end of
  the day after signup (created on the signup date or the next day).
- **Outcome check:** the share that write an own record again within 14 days.
- **Target:** 15% on the goal and 6% on the outcome, for the first full calendar month after this
  ships. Baseline: 3.7%, 3.9%, 3.9% for August, September and October; 3% came back. 6% is what
  15% yields if the measured 44% return rate holds; if the goal rises and the outcome does not,
  record volume was marking motivation rather than causing return.
- Read monthly by signup cohort, the goal at day 2 and the outcome at day 14, with counts beside
  the shares. The November cohort is the pre-ship baseline. Launch traffic (Reddit Nov 10 to 14,
  Show HN Nov 16) may lower the share while raising the count.

## Non-goals

- No import inside the chat. Files go to the import wizard.
- No sort or filter on email activity for Rela, the API or MCP. `email_count` and `last_email_at`
  are unscoped and would leak private mail (`EmailVisibilityService::visibleEmailCount()`).
- No new day-two email. The existing task digest carries day two.
- No change to chips outside onboarding, though their defects are known (sample-data suggestions,
  a chip that sends "[paste your list]", "contacts" for people).
- No use-case-specific labels.

## Anti-goals (a failure even if it technically works)

- A path that ends on a request for data the user already said they do not have.
- An option or link that points at something the workspace cannot do (email integration off, a
  missing capability).
- Rela creating anything without the user's approval.
- Any member seeing another member's private mail activity.
- Counting sample or mailbox-synced records toward the goal.

## What changed (2026-11-12)

Decisions 2, 3, 6 and 8 below are replaced. The rest stands.

- **No routing question.** The wizard keeps the steps main already has: mailbox, sharing, use
  case, invite. Nothing about where clients live is asked or stored.
- **Buttons follow one fact: is the owner's mailbox connected.** Connected: "Show my synced
  companies", "Add someone not in my email". Not connected: "Upload my file", "Add a client",
  "Connect Claude or ChatGPT". Once companies arrived today from an import or a mailbox: "Pick who
  to work on first", "Later". Buttons are written on the greeting and the data arrived turn only.
- **Rela reads the same fact.** The onboarding block carries a `mailbox:` line in place of the
  data source line, and the greeting gives the next step for each state.
- **No samples when the setup chat is the landing.** An owner who connected a mailbox at the
  wizard step gets none either, as on main. An install without the setup chat keeps main's rule.
- **A 14-day window.** The data arrived turn and "Continue with Rela" stay on for the first 14
  days after the workspace is created, the window the goal checks return in.
- **Data that arrives behind a pending proposal** is announced after the owner approves or
  discards it. The turn is dropped at the time, remembered for 24 hours, and opened again when
  Rela's reply to the decision ends.
- **An expired proposal** opens the dropped turn too, from the command that expires proposals.
- **Data that arrives while the first greeting streams** is remembered and announced when the
  greeting ends. A chat that was never opened stays with the greeting, which reads what arrived.

## What was decided (2026-11-07)

1. **Goal:** 10+ own records by end of day 1, with return within 14 days as the check.
2. **One routing question replaces the mailbox step:** "Where do your clients live today?" with
   five answers: a spreadsheet, another CRM, my inbox, in my head, Claude or ChatGPT. The answer
   is stored on the workspace so cohorts can be split by it.
3. **Where each answer lands:**

   | Answer | Lands on | Then |
   |---|---|---|
   | A spreadsheet | The import wizard (people import, which links or creates companies from a company column) | After the import, the setup chat (see "After data arrives") |
   | Another CRM | The import wizard, with one generic line: export people and companies as CSV from your current CRM. An optional "which one?" text field is stored on the workspace so we learn which CRMs matter. No CRM is named in the app | Same |
   | My inbox | The mailbox connect step that exists today (`RunsMailboxSteps`), then the setup chat | Rela states what synced, with counts, and offers to list the synced companies so the user can pick who to work on first |
   | In my head | The setup chat | Rela asks for the people and deals they are working on now and proposes them as they are named |
   | Claude or ChatGPT | The setup chat | Connection steps (`connect_assistant`) and a copyable prompt that asks their assistant to move their clients in |

   Both file answers offer a "do this later" way into the setup chat.
4. **After data arrives, Rela proposes the next actions in the same session:** an opportunity for
   each company the user picks to work on first, and a dated follow-up task for each, assigned to the
   owner when they are the workspace's only member. Rela builds these from what came in and what
   the user said. When nothing says when to follow up, it asks once, offering the next business
   day as the default, so at least one approved follow-up is due before the end of day two. Every
   write stays a proposal the user approves. The existing task digest (assigned tasks due before
   the end of the user's local day) then reaches them on day two at 08:00.
5. **Target:** 15% and 6% as above.
6. **Path buttons in the setup chat.** Each landing message carries one to three buttons for the
   next steps of the user's path, picked by the server from the routing answer and the
   workspace's state, with fixed, tested prompts. They render as bordered buttons with an icon,
   directly under Rela's message, and a button either sends a prompt or opens a page. Chips
   elsewhere stay as they are. Examples (final copy is reviewed in the PR):
   - In my head: "Add a client", "Add a deal".
   - Inbox: "Show my synced companies", "Add someone not in my email".
   - Claude or ChatGPT: "Copy the prompt", "Open connection steps".
   - After an import or a mailbox sync: "Pick who to work on first", "Later".
7. **How the user picks after an import: in conversation.** "Pick who to work on first" makes
   Rela list the companies that arrived today (by creation source `import`, or `mailbox` for the
   inbox path), unordered. The user names some, and Rela proposes decision 4's opportunity and
   follow-up for each. Rela never reads the uploaded file: the importer keeps only mapped columns
   and deletes the file two hours after completion, and real uploads are lead lists (company name,
   city, website, industry, prospect tier, ICP) where a stage column appears 10 times in 54
   imports.
8. **Sample data only where nothing is about to arrive.** Samples are seeded for "in my head", and
   for a file answer when the user takes "do this later". Spreadsheet, another CRM, Claude or
   ChatGPT, and inbox start without samples. Nothing removes samples automatically; removal stays
   the user's choice, as today. Why: the sample companies are real brands (Airbnb, Apple, Figma,
   Notion), and the people import links a person to an existing company by name, so a prospect
   list would attach real people to fake companies.
9. **Kept by hand alongside:** a founder email to each workspace that reaches 10 records, drafted
   in the growth replies lane.

## The setup chat's greeting

`MessageOrigin::Greeting` today tells Rela to ask for a paste, a CSV or a description, whatever
the workspace holds. It changes to open on the user's routing answer and state: what arrived and
how much (an import's counts, a mailbox's people and companies), and the next step for their
path. It keeps first name, the stages line, three short paragraphs at most and no tools in that
turn.

## Calls made while reviewing (builder questions settled within the decisions)

- *My call*: no mailbox recency. Rela gets no email-activity read, in line with the non-goal; the
  mailbox list stays unordered.
- *My call*: the greeting reports what has synced so far. When a mailbox sync or an import
  finishes after the greeting, the setup chat opens an "after data arrives" turn with the final
  counts, by the same mechanism for both.
- *My call*: the follow-up question offers the next business day as its default, so the digest has
  something on day two.
- *My call*: the inbox answer reuses today's mailbox step unchanged, both providers included. The
  Gmail cap and unverified screen are the same as today and are not handled here.

## Constraints

- Project rules bind the work: `CLAUDE.md`, `.ai/rules/` (notably onboarding, chat, queries,
  architecture, panel links), the business-language table, i18n via `lang/`, no em-dashes in
  copy, no `down()` in migrations, rehearsal on anonymized production data for any migration.
- Chat changes are verified on the production-shaped stack (Horizon, Redis queue, Reverb) in a
  real browser; view changes in Chromium and WebKit, light and dark, desktop and mobile.
- Gmail connect shows Google's unverified-app screen and is capped at 100 users until CASA
  passes; Microsoft is not capped.
- Every user-facing string is product copy, reviewed in the PR.

## Left to the builder

- Seeding moves from the use-case step (`SaveOnboardingUseCase::seedsSamples()`) to after the
  routing answer, and the use-case step's place in the new order (it also sets the stage names
  the greeting reads).
- Where the routing answer is stored and how the setup steps (`SetupWorkspace`,
  `WorkspaceSetupStep`) change, including what happens to the sharing step for non-inbox answers.
- How the setup chat learns that an import finished and opens the "after data arrives" turn.
- The mechanics of the follow-up proposals (prompt rules, setup-mode tool behavior), within the
  existing proposal pipeline and its batch rules.
- Where the goal metric is defined for reading (the growth analytics metrics file) and how option
  clicks are counted.
- Test layout, following `tests/` conventions.

## Verification

- One repro during the build: in an "in my head" workspace with samples, "add Jane at Notion"
  must not link Jane to the sample Notion. If it does, Rela's lookups skip sample records once the
  user starts adding their own.
- Feature tests for each routing answer's landing, sample seeding per answer, the owner-assignment rule (sole member versus
  several), the greeting per state, and the digest picking up an approved follow-up.
- The live repro harness from this investigation (real signup actions, setup greeting, Sonnet 5)
  re-run per routing answer, with and without synced mailbox records, plus the openers "hi",
  "add Acme Corp as a company" and "what can you do?".
- A browser walk on the production-shaped stack: signup, each routing answer to its landing, an
  import of a 50-row people file, the follow-up proposals approved, and the next morning's digest
  rendered, in Chromium and WebKit.
