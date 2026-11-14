# Decisions 22 to 27: linking, privacy and reach

This file covers how mail reaches records and who sees it. It covers linking,
the sharing default, access requests, opportunity email, sync health and the
connect prompt. Every section follows [method.md](../method.md). Code is cited
as of 2026-10-31 by path and line. Production numbers come from
[README.md](../README.md) and the brief for this file. "Inference" marks a
reading of a source, not a claim the source makes.

Paths start at the repository root. `EI/` stands for `packages/EmailIntegration/`.

Four code facts change the starting point for these decisions:

- **There is no way to link or unlink an email by hand.** `LinkEmailAction` only
  writes `link_source = 'auto'` (`EI/src/Actions/LinkEmailAction.php:272-281`).
  The 3 "manual" links come from sending an email from a record
  (`EI/src/Actions/SendEmailAction.php:183-194`). Meetings have link and unlink
  actions; email has neither.
- **The sharing question already exists, in one place.** Since 2026-10-07
  (`47ec3099a`), workspace setup asks after connect, offering Participants only
  (preselected) or Subject (`app/Actions/Onboarding/SaveOnboardingSharing.php:17`,
  `app/Filament/Pages/Concerns/RunsMailboxSteps.php:170-179`). A teammate who
  connects later from the accounts page is never asked.
- **"Most permissive copy wins" already ships.** When two mailboxes hold the same
  message, the list shows the viewer the most open copy they may see
  (`EI/src/Services/PreferredEmailCopyService.php:40-46`).
- **A stale-mailbox check already exists, for staff only.** `ConnectedAccount`
  has `stale()` (60 minutes) and `needingAttention()` scopes
  (`EI/src/Models/ConnectedAccount.php:105,209-230`). Only the SystemAdmin panel
  reads them (`packages/SystemAdmin/src/Filament/Widgets/Overview/ProblemsStats.php:163-164`).

## Summary

| # | Decision | Pick | Runner-up | Score (pick / runner-up, of 60) | Confidence |
|---|---|---|---|---|---|
| 22 | Linking emails to records | Stored links, plus link and unlink chips in the reader, a message-level suppression table, and an Unlinked senders view | Hybrid: identity links derived at read time, manual links stored | 54 / 44 | Medium-high |
| 23 | Default sharing tier | Ask at every connect with Participants only preselected and a live preview; teammates see a mailbox's mail only where it is linked to a record; raising the workspace default never widens past mail | Ask at every connect with Subject preselected | 55 / 44 | Medium |
| 24 | Access requests | Labelled request in the locked reader and on locked rows, plus a per-record batch request the owner can answer with "share all my mail on this record" | Labelled per-email request only | 56 / 51 | Medium |
| 25 | Email on opportunities | Read-time: the company's and point of contact's mail in the opportunity's time window, plus stored pins and removals | Read-time since creation only | 53 / 48 | Medium-high |
| 26 | Sync health | A scheduled watchdog that stores one health state and reason per mailbox; banner, nav dot and owner then admin notices read it | Banner and Reconnect, computed on each page view | 56 / 47 | High |
| 27 | Connect prompt and empty states | Connect card above usable tabs, and the sharing step after every connect, with a no-provider state for self-hosting | Sharing step after every connect, page unchanged | 55 / 50 | High |

---

## 22. Linking emails to records

### The question

Should an email belong to a record because we stored a link, or because its
participants match the record when someone looks?

### Today

- **Linking runs once per email, inside a row lock.** It walks each participant,
  matches a person by address and a company by host, and attaches with
  `link_source = 'auto'` (`EI/src/Actions/LinkEmailAction.php:70-96,116-199`).
  Links live in `emailables`, unique per email and record
  (`database/migrations/2026_03_21_054333_create_emailables.php:13-22`).
- **Record creation is gated.** A new person needs an external, non-automated,
  non-suppressed address and a creation mode that allows it
  (`LinkEmailAction.php:129-135`). The column default is `selective`: create only
  for addresses the workspace has emailed
  (`database/migrations/2026_03_21_055334_add_default_email_sharing_tier_to_teams.php:20-22`,
  `LinkEmailAction.php:216-224`). A company is created only when a person is
  (`AutoCreateCompanyAction.php:34-50`, `LinkEmailAction.php:233-236`).
- **Matching skips 23 public domains and 16 automated local parts**
  (`EI/config/email-integration.php:11-60`, `EI/src/Support/CompanyDomainMatcher.php:73-83`).
- **Opportunities link only through their point of contact**
  (`LinkEmailAction.php:188-197`).
- **Adding an address or domain relinks history.** Saving a person's `emails` or
  a company's `domains` queues `RelinkRecordHistoryJob`
  (`EI/src/EmailIntegrationServiceProvider.php:107-108`,
  `EI/src/Support/QueueRecordHistoryRelink.php:26-41`). It only fills
  participants whose `contact_id` or `company_id` is still null
  (`EI/src/Jobs/RelinkRecordHistoryJob.php:92-115`).
- **Nothing ever unlinks.** Removing an address leaves its links. An address that
  moves from person A to person B stays on A, because its participants already
  carry A's id. Record counters only increment
  (`EI/src/Services/RecordCommunicationMetrics.php:22,67`).

**Production:** 474,004 automatic links, 3 manual, 135 to opportunities. 46.1% of
372,714 emails have a link, so about 171,800 linked emails carry 2.76 links each.

**Why 54% is unlinked, as far as the code explains it.** No production breakdown
by cause exists. The code paths that leave mail unlinked are:

1. **Marketing.** 37% of mail is labelled Marketing and 24,007 of those are linked.
   That leaves about 113,900 unlinked Marketing emails: roughly 57% of the
   unlinked 200,900 (derived from rounded shares, so plus or minus a few points).
   Since 2026-10-02 bulk mail is dropped at sync (`EI/src/Jobs/StoreEmailJob.php:145-149`),
   so this is mostly older rows.
2. **Unknown inbound senders under `selective`.** A stranger you never replied to
   creates no person, and no company unless their domain is already on one.
3. **Public domains.** A Gmail sender never matches a company, only an existing person.
4. **Automated senders** (`notice`, `mailer`, `bounce`) link only to existing records.
5. **Internal mail and blocked entries** never create records
   (`EI/src/Services/EmailVisibilityService.php:335-351`).

Inference: most of the 54% is mail that should stay off records. The README
target of "100% visible" is the wrong goal for linking.

### What competitors chose and why

- **Attio matches at read time.** Record tabs pull mail by "linked company domains"
  and "email addresses of linked people"
  ([changelog](https://attio.com/changelog/2026/emails-on-deals-and-custom-objects)).
  Why (inference): Attio's model is attributes everywhere. Deriving mail from an
  attribute means one edit retargets all history, with no backfill. The cost they
  accepted: no manual "link this email" exists in their docs, and a community
  thread reports "wrong allocation of emails to deals"
  ([community](https://www.attio.community/c/general)).
- **HubSpot stores associations** and shows an "Associated with" picker on every
  email ([docs](https://knowledge.hubspot.com/one-to-one-email/send-and-reply-to-one-to-one-emails)).
  Why: HubSpot reports on associations, and reports need stored, auditable rows.
- **Streak offers "Just this thread" or "All emails with".** The second turns on a
  rule for past and future mail "across your team's inboxes"
  ([Streak](https://support.streak.com/en/articles/4300253-manually-adding-an-email-from-the-gmail-inbox)).
  Why: Streak lives inside Gmail, so a manual add is the main way mail enters a box.
- **Pipedrive** lets owners and admins link and unlink conversations, and
  visibility follows the linked item
  ([Pipedrive](https://support.pipedrive.com/en/article/how-can-i-view-or-adjust-email-visibility)).
- **Close** lists "Potential Contacts" from senders not yet in Close
  ([changelog](https://close.com/changelog?fe6f4718_page=4)). Why: Close sells
  action, and a new sender is a lead to qualify.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Automatic stored links only (today) | Link once at sync; relink fills gaps on identity change | | Fast reads, counters, audit of `link_source` | No correction path; stale links after address changes |
| B. Pure read-time matching | Record tab queries participants by the record's addresses and hosts | Attio | Always consistent with identity fields; erasure is a field edit | No manual link or unlink; heavier query per tab at 1.5M; no history of why mail showed |
| C. Stored links plus reader chips, suppression and an Unlinked view | Chips add and remove links; removal writes a suppression row keyed by message and record; Unlinked view groups senders with Create person or company; "All emails with" means adding the address to the person | HubSpot, Close, Streak | One owner of links for UI, Rela and MCP; auditable; reversible per message | Needs an unlink reconcile job for removed addresses; counters must recompute |
| D. Hybrid | Person and company mail derived at read time; only manual links and suppressions stored | | Identity edits are instant; manual intent kept | Two read paths to keep in parity; every reader must merge them |
| E. Rule table | Separate "always link address or domain to record X" rules, applied at sync and backfill | Streak | Expresses intent beyond identity fields | Duplicates the identity fields; a second source of truth |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 3 | 3 | 3 | 5 | 35 |
| B | 4 | 4 | 3 | 3 | 2 | 40 |
| C | 5 | 4 | 5 | 4 | 4 | **54** |
| D | 4 | 3 | 4 | 4 | 2 | 44 |
| E | 3 | 3 | 4 | 3 | 3 | 39 |

- **User value.** C fixes the daily irritant (wrong or missing link) in the reader.
  B removes stale links but leaves no way to fix a wrong match.
- **Clarity.** A chip you can remove is obvious. D and E hide why a mail is there.
- **Fit.** C keeps one owner of the link fact, as `architecture.md` asks. E creates
  a second owner beside the identity fields.
- **Cost.** C reuses `LinkEmailAction`, the meeting link actions as a template, and
  the existing relink job.

### Future-proof check

| Test | C (pick) | D (runner-up) |
|---|---|---|
| Agents | Pass: `VisibleEmailsQuery` reads the same `emailables` | Pass if both paths sit behind one query; risk of drift |
| No AI key | Pass | Pass |
| Scale 10x | Pass: indexed pivot lookups | Partial: participant and host joins per tab view (indexes exist, `2026_09_29_222721_index_email_participant_and_attendee_addresses_by_host`) |
| Providers | Pass: works on participants, not provider labels | Pass |
| Trust | Pass: each link has a source and actor; suppression is a record of intent | Partial: derived links have no history |
| Reversibility | Pass: dropping chips leaves data intact | Partial: removing stored identity links is a migration |

### Recommendation

**Pick C.** It beats D because the link stays one stored fact. Counters, Rela,
the MCP server and the record tabs already read that fact. D only buys instant
consistency on identity edits, and a reconcile job buys the same thing.

Decisions made:

- **Unlinking needs a suppression table.** `reapply()` re-attaches on every
  history import (`LinkEmailAction.php:60-63`). Key it by `(workspace_id,
  rfc_message_id, record)`, so every mailbox's copy of the message stays unlinked.
- **Keep suppressions out of `emailables`.** A status column there would make every
  reader filter it, and one missed filter leaks a removed link into counts.
- **Removing an address detaches auto links** that no longer match, never manual
  ones. Moving an address to another person relinks to the new person.
- **Unlink recomputes the record's counters** instead of decrementing them.
- **"All emails with this address" adds the address to the person.** The existing
  relink job then does the backfill. No rule table.

**What would change our mind:** if manual link and unlink stay under 10 per month
across all workspaces after 60 days, D's lower upkeep wins.

**Smallest first step:** `LinkEmailToRecord` and `UnlinkEmailFromRecord` actions,
cloned from the meeting pair, with chips in the reader.

### Risks and mitigations

- **Reconcile job detaches a link a user relied on.** Only touch `auto` links, and
  log each detach to the activity log.
- **Counter recompute is slow on a 156,632-email company.** Recompute per record
  in the queue, not in the request.
- **Unlinked view becomes a spam list.** Group by sender, hide automated and
  public-domain senders by default, and sort by messages you sent them.

---

## 23. Default sharing tier

### The question

What should a teammate see of your mail before you choose, and who may widen it later?

### Today

- **Tiers and openness.** Private 0, Participants only 1, Subject 2, Full 3
  (`EI/src/Enums/EmailPrivacyTier.php:29-37`). The UI labels `metadata_only`
  "Participants only" (`lang/en/filament/pages/email-privacy-settings.php:118-121`).
- **Full can never be a workspace default** (`EmailPrivacyTier.php:48-51`,
  enforced in `EI/src/Actions/UpdateWorkspaceEmailPrivacySettingsAction.php:23`).
  Only admins hold `EmailManage` (`app/Enums/WorkspaceRole.php:43-56`).
- **A null mailbox tier means "follow the workspace default", live.**
  `tierForMailbox()` falls back to the workspace column, then to Participants
  only (`EI/src/Services/PrivacyService.php:68-76`). The UI calls it "Use workspace
  default" (`EI/src/Filament/Pages/EmailAccountSettingsPage.php:242-251`).
- **The tier is stamped on each email at sync** (`EI/src/Observers/EmailObserver.php:20-39`).
  Changing a default rewrites every non-customized email. For the workspace,
  that covers all mailboxes with a null tier, past mail included
  (`EI/src/Actions/ApplyDefaultSharingTierToExistingEmailsAction.php:15-28`).
  An admin can therefore widen 13 members' past mail to Subject with one
  confirmation the members never see.
- **Only the owner sets a mailbox tier** (`EI/src/Actions/SaveMailboxSharingTier.php:22`).
- **Most open visible copy wins** (`PreferredEmailCopyService.php:40-46`). Each
  copy keeps its owner's tier and mailbox blocklist. A viewer who has their own
  copy sees it in full (`PrivacyService.php:40-42`).

**Production:** 95.4% of emails at Participants only. Mailbox tiers: null 13,
Participants only 15, Subject 2, Private 1, Full 1. Inference: most of the 15 come
from the setup step, where Participants only is preselected. 0 shares exist.

### What competitors chose and why

- **Attio defaults to Subject line and metadata**, per mailbox, and applies a
  change to past mail ([Attio](https://attio.com/help/reference/email-calendar/sharing-emails)).
  Its product lead confirms the most permissive mailbox wins on shared threads
  ([community](https://www.attio.community/c/general/privacy-and-email)).
  Why: Attio sells team context, and subjects carry most of it. The cost: "email
  sharing" is a top G2 con with 19 mentions. One reviewer writes "my teammates are
  sensitive to sharing" ([G2](https://www.g2.com/products/attio/reviews?qs=pros-and-cons)).
- **Clarify defaults to Participants only, meaning only people on the thread.**
  An admin can switch new email to Everyone, but "email already in Clarify keeps
  the access it had". Admins have no per-email override
  ([Clarify](https://docs.clarify.ai/en/articles/16774485-sharing-and-permissions)).
  Why: Clarify's whole model is "access only widens, through sharing". Note the
  name clash: their "Participants only" is close to our Private, not our metadata tier.
- **Lightfield defaults to Full access** and the most permissive account wins
  ([Lightfield](https://docs.lightfield.app/help/settings/mail-and-calendar/)).
  Why: its AI needs content to fill records. The cost: a teammate's choice can
  expose your copy's content.
- **Day.ai offers Share All or Share None**, with exclusions by domain or address.
  Admins cannot see private mail, and agents inherit their person's permissions
  ([Day.ai](https://www.day.ai/resources/data-privacy-sharing)). Why: two choices
  are easy to explain to an agent and to a buyer.
- **Pipedrive: Shared or Private, and shared mail shows only on linked items the
  viewer can access** ([Pipedrive](https://support.pipedrive.com/en/article/how-can-i-view-or-adjust-email-visibility)).
  Why: email is evidence for a deal, so visibility follows the deal.
- **Salesforce EAC sets automated mail to Don't Share** until the owner reviews it
  ([Salesforce](https://help.salesforce.com/s/articleView?id=000392958&language=ja&type=1)).
  Why: password resets and alerts leak secrets.
- **Folk and Twenty.** Folk offers Subject only or Full per source
  ([Folk](https://help.folk.app/en/articles/6416668)). Twenty asks in its connect
  wizard ([Twenty](https://docs.twenty.com/user-guide/calendar-emails/overview)).

**Regulation.** Teammates seeing your metadata see who you talk to and when.
That can show a job search, a clinic or a union contact. The ICO says monitoring
must pass a necessity test and should not be "just in case"
([ICO](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/employment/monitoring-workers/data-protection-and-monitoring-workers/)).
GDPR Article 25(2) requires that data is not, by default, made accessible to an
indefinite number of people without the person's intervention. In Germany, software
able to monitor behaviour needs works council co-determination
([§87(1) no. 6 BetrVG](https://www.gesetze-im-internet.de/betrvg/__87.html)).
Inference: the CRM purpose covers mail about customers, not a colleague's whole mailbox.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Today | Participants only by default; setup asks once; later connects are not asked; admins can widen null mailboxes retroactively | | Safe by default | Silent for later members; admin can widen past mail |
| B. Subject, set silently | Workspace default becomes Subject | Attio | More team context | Subjects leak personal and HR matters; consent by default |
| C. Ask at every connect, Subject preselected | Setup sharing step after every connect | Twenty wizard, decisions.md | Explicit choice | Preselection is the choice for most; widens Rela's reach over colleagues |
| D. Ask at every connect, Participants only preselected, with a live preview; linked-only for teammates; raising never widens past mail | Preview shows three of your own threads as a teammate sees them; per-record "share my mail on Airbnb" is the widening path | Pipedrive, Clarify, Attio record share | Least exposure by default; widening is deliberate and scoped | Less ambient context for teams that want it |
| E. Private by default | Teammates see nothing until shared | Clarify, Folk | Strongest privacy | Record tabs look empty; sharing load moves to every owner |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 2 | 4 | 3 | 5 | 36 |
| B | 4 | 3 | 2 | 2 | 5 | 35 |
| C | 4 | 5 | 3 | 3 | 4 | 44 |
| D | 4 | 5 | 5 | 5 | 3 | **55** |
| E | 1 | 4 | 4 | 4 | 5 | 40 |

- **User value.** D gives content where it matters, on records the owner shares.
  B gives subjects everywhere, including mail no customer is on.
- **Clarity.** A preview of your own threads explains a tier better than a label.
- **Fit.** D maps visibility to the CRM purpose. B and C fail Article 25 in spirit.
- **Future-proof.** Agents widen the reach of whatever teammates can see. D keeps
  that reach small.

### Future-proof check

| Test | D (pick) | C (runner-up) |
|---|---|---|
| Agents | Pass: Rela reads through `VisibleEmailScope`, so it sees linked metadata only | Partial: every teammate's Rela can mine colleagues' subjects |
| No AI key | Pass | Pass |
| Scale 10x | Pass: one extra `exists` on `emailables` | Pass |
| Providers | Pass | Pass |
| Trust | Pass: "teammates see who and when, only on records" is one sentence | Partial: preselected Subject is weak consent |
| Reversibility | Pass: tiers are per email and can be raised later | Partial: lowering after exposure cannot unshare what was read |

### Recommendation

**Pick D.** It beats C because the default decides the outcome for most people.
15 of 19 explicit mailbox tiers equal the setup step's preselection (inference).
With Rela and the MCP server reading as the viewer, that default sets what agents
see. Teams that want context get it per record, where it serves the deal.

Decisions made:

- **Teammates see another mailbox's mail only where it is linked to a record.**
  This keeps today's effective behaviour, since record tabs are the only surface.
  It must hold when the Conversations list ships (decision 1).
- **Raising the workspace default applies to future mail only.** Lowering applies
  to past mail too. Null mailboxes get an inbox notice when the default rises.
  An owner changing their own mailbox still re-tiers its past mail, as today.
- **Keep "most open visible copy wins", on purpose.** Each owner controls only
  their own copy. A participant holding the same message could forward it anyway.
  The reader states the source: "Visible through Ben's mailbox". This reverses
  decisions.md, which said never copy it.
- **Rename the metadata tier** to "Who and when" to avoid Clarify's meaning.
- **Automated mail starts Private for teammates**, as Salesforce does, using the
  existing `AutomatedSenderMatcher`.

**What would change our mind:** if, 60 days after per-record sharing ships, fewer
than 5% of multi-mailbox workspaces share anything, the default is too tight.
Then test C.

**Smallest first step:** make `executeForWorkspace` skip widening for past mail,
and add the linked-only condition to the teammate branch of `VisibleEmailScope`.

### Risks and mitigations

- **Linked-only hides useful mail on not-yet-created records.** The owner still
  sees it, and the Unlinked view (decision 22) creates the record.
- **Admins expect the default to apply to everyone.** The settings page says
  "applies to mail synced from now on" and lists null mailboxes.
- **The rename confuses current users.** Keep the description line unchanged for a release.

---

## 24. Access requests

### The question

When you can see that a teammate emailed a customer but not what was said, how do
you ask, and how do we keep asking cheap for both sides?

### Today

- **The request is a per-email icon button** labelled only by tooltip
  (`EI/src/Filament/Concerns/HasEmailReaderActions.php:218-246`). The requester
  picks Subject or Full.
- **It goes to the owner of the copy shown**, never another holder
  (`EI/src/Actions/RequestEmailAccessAction.php:52-63`). It expires after 30 days
  (`EI/src/Models/EmailAccessRequest.php:46`).
- **The owner gets an email** (`EI/src/Notifications/EmailAccessRequestedNotification.php:70-72`)
  and a pending row in the notification inbox with Approve and Deny
  (`EI/src/Inbox/EmailAccessRequestSource.php:34-45`).
- **Decisions are audited, requests and shares are not.** `DecideEmailAccessRequest`
  logs each outcome (`EI/src/Actions/DecideEmailAccessRequest.php:116-127`).
  Creating a request and sharing by hand write no activity.
- **Record-level requests were planned and never built.** `emailable_type` and
  `emailable_id` exist on the table and are fillable, but nothing writes them
  (`EmailAccessRequest.php:30-31`).
- **Locked rows are not clickable** (findings.md #17), so the reader with the
  button is often unreachable.

**Production:** 0 requests ever. 32 mailboxes across 29 workspaces means at least
26 workspaces have one mailbox. There, nobody but the owner holds mail to request.

### What competitors chose and why

- **Attio** lets you request access and now shows "which users you are requesting
  access from" ([changelog](https://attio.com/changelog/2025/changelog-december-17-2025)).
  Owners can also share all their emails for a record from "Manage access"
  ([Attio](https://attio.com/help/reference/email-calendar/sharing-emails)). Why
  (inference): with a Subject default, people see what they are missing and ask.
- **Clarify** requests carry a note and go to the mailbox owner. "You're notified
  either way", and "No one can share this" shows when no holder remains. Members
  can also grant a teammate all their email
  ([Clarify](https://docs.clarify.ai/en/articles/16774485-sharing-and-permissions)).
  Why: Clarify defaults closed, so the request path carries its whole model.
- **Salesforce EAC** pairs its Don't Share default with a request path
  ([Salesforce](https://help.salesforce.com/s/articleView?id=000392958&language=ja&type=1)).
- **Pipedrive, HubSpot, Close have no requests.** Visibility follows records and
  teams. Why: they assume a sales team shares by default.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Icon in reader (today) | Per email, to the copy owner | | Precise | Invisible; 0 uses |
| B. Labelled button in the locked reader and on locked rows | "Request from Ana" with a note | Attio, Clarify | Discoverable; tiny build | One request per email floods the owner |
| C. B plus a per-record batch | "Request Ana's 12 emails on Airbnb"; one notice; owner approves these, or all past and future mail on that record | Attio Manage access | Matches how people think (the customer, not the message); one decision | Needs record-scoped shares; more states |
| D. Owner standing rule only | Owner shares all mail on a record, unprompted | Attio record share | No request noise | Owners rarely think to do it |
| E. Remove requests | Rely on defaults | Pipedrive | Simplest | Pushes teams to wider defaults |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 1 | 1 | 4 | 3 | 5 | 31 |
| B | 4 | 4 | 5 | 4 | 4 | 51 |
| C | 5 | 4 | 5 | 5 | 3 | **56** |
| D | 3 | 3 | 4 | 4 | 2 | 41 |
| E | 2 | 4 | 2 | 2 | 5 | 31 |

- **User value.** C answers the real question, "what has Ana said to Airbnb".
- **Fit.** C makes D the owner's one-click answer, so a narrow default stays livable.
- **Future-proof.** A record-scoped share is what an agent needs to explain access.

### Future-proof check

| Test | C (pick) | B (runner-up) |
|---|---|---|
| Agents | Pass: Rela can say "Ana's mail on Airbnb is locked; request it?" and file one request | Partial: Rela would file one request per email |
| No AI key | Pass | Pass |
| Scale 10x | Pass: one row per record request | Fail at 50 mailboxes: request floods |
| Providers | Pass | Pass |
| Trust | Pass once creation and shares are logged | Same |
| Reversibility | Pass: shares revoke per record | Pass |

### Recommendation

**Pick C, shipping B first.** Zero requests is mostly low need: most workspaces have
one mailbox. Discoverability explains the rest. The signal to watch is requests per
workspace with two or more mailboxes, not the global count.

Decisions made:

- **Route each request to every holder of a copy**, and let the requester see them,
  as Attio does. The first approval closes the rest.
- **Batch notices:** at most one email per owner per requester per day. The inbox row updates in place.
- **Log request creation, approval and every manual share** to `EMAIL_ACCESS_LOG`.
- **Use the existing `emailable_*` columns** for record requests. No new table.
- **Add an owner setting "always share with this teammate"** later, if the same
  pair repeats.

**What would change our mind:** if multi-mailbox workspaces still file under one
request per month after B ships, cancel C and invest in per-record sharing (D) instead.

**Smallest first step:** label the reader button, make locked rows open the gated
reader (findings #17), and log request creation.

### Risks and mitigations

- **Requests feel like surveillance.** Show the note and the record, never a list
  of what else the requester viewed.
- **Approving a record share widens future mail silently.** The approval names it:
  "Ana's past and future mail on Airbnb", with Revoke on the record.
- **Owner left the workspace.** Show "No one can share this", as Clarify does.

---

## 25. Email on opportunities

### The question

Which mail belongs on an opportunity, and should we store that or derive it?

### Today

- **An email links to an opportunity only when its point of contact is a
  participant** (`EI/src/Actions/LinkEmailAction.php:188-197`).
- **Nothing relinks history for opportunities.** `RelinkRecordHistoryJob` takes
  only People or Company (`EI/src/Jobs/RelinkRecordHistoryJob.php:39-41`). An
  opportunity created after the emails shows none of them.
- **The tab reads stored links** through `->emails()`
  (`EI/src/Filament/RelationManagers/BaseEmailsRelationManager.php:101`).

**Production:** 135 opportunity links against 268,357 company links. Opportunity
tabs are empty in practice.

### What competitors chose and why

- **Attio** shows mail on deals from "linked company domains" and "email addresses
  of linked people", with several tabs per object
  ([changelog](https://attio.com/changelog/2026/emails-on-deals-and-custom-objects)).
  Why: a deal has no address of its own, so deriving from its links is the only
  rule that needs no upkeep.
- **HubSpot** asks the sender which records an email logs to. Why: deal reporting
  needs stored associations.
- **Pipedrive** shows a conversation on every item it is linked to
  ([Pipedrive](https://support.pipedrive.com/en/article/how-can-i-view-or-adjust-email-visibility)).
- **Clarify:** "Sharing a deal shares the deal. It does not share the company, the
  contacts, the tasks or the emails attached to it"
  ([Clarify](https://docs.clarify.ai/en/articles/16774485-sharing-and-permissions)).
  Why: derived views must not widen access. Ours must not either.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Stored, through point of contact (today) | Link at sync | | Precise | Empty tabs; no history |
| B. Read-time since creation | Company and contact mail sent after `created_at` | Attio, decisions.md | Instant, full | Misses the mail that started the deal; two open deals on one company show the same mail |
| C. Auto-link new mail to the company's open opportunities | Stored at sync | | Counters work | Guesses wrong with several open deals; no history |
| D. B with a window plus stored pins and removals | Window from 30 days before creation to 30 days after close; pins and removals reuse decision 22 | Attio plus HubSpot | Full and correctable | Two sources merged in one query |
| E. AI match by content | Model reads threads | | Handles several deals | Needs a key; opaque |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 1 | 2 | 3 | 3 | 5 | 30 |
| B | 4 | 4 | 4 | 4 | 4 | 48 |
| C | 3 | 3 | 3 | 2 | 3 | 33 |
| D | 5 | 4 | 5 | 4 | 3 | **53** |
| E | 3 | 2 | 3 | 2 | 1 | 29 |

- **User value.** D includes the first emails and lets a seller fix overlaps.
- **Fit.** D derives from the opportunity's own company and contact, which the
  record model already owns.
- **Future-proof.** E fails the no-AI test.

### Future-proof check

| Test | D (pick) | B (runner-up) |
|---|---|---|
| Agents | Pass if the rule lives in `VisibleEmailsQuery`, the read owner MCP already uses | Same |
| No AI key | Pass | Pass |
| Scale 10x | Pass: company links are indexed; the window bounds the scan | Pass |
| Providers | Pass | Pass |
| Trust | Pass: derived rows still pass `VisibleEmailScope` | Pass |
| Reversibility | Pass: no stored backfill | Pass |

### Recommendation

**Pick D.** It beats B on the mail that matters most: the emails before the
record existed. Pins and removals reuse decision 22's tables, so it adds no new concept.

Decisions made:

- **Derived, never stored, for the company and point-of-contact rule.** A
  backfill would be a data migration to rehearse and to undo.
- **Stop linking opportunities at sync.** The stored path is replaced by the
  derived rule plus pins. The 135 existing links stay and read as pins.
- **The window is fixed at 30 days before creation**, until the opportunity closes
  plus 30 days. A tab setting can come later, as in Attio.
- **Visibility is never inherited.** Seeing the opportunity grants nothing.

**What would change our mind:** if users remove more than 20% of derived threads
on opportunities, the rule is too loose. Narrow it to the point of contact.

**Smallest first step:** a query that unions the company's and contact's linked
mail in the window, behind the existing tab.

### Risks and mitigations

- **Several open deals on one company look identical.** Show a hint "Also on
  Renewal 2027" and let a pin decide.
- **Counters on opportunities stay zero.** Compute them from the same query, cached.

---

## 26. Sync health

### The question

How do we find a mailbox that stopped syncing, and who do we tell, before a
seller trusts a record that is two weeks out of date?

### Today

- **Polling every five minutes**, only for `syncable()` mailboxes: `active` and in
  a workspace with hosted access (`bootstrap/app.php:384-388`,
  `EI/src/Console/Commands/IncrementalEmailSyncCommand.php:19-24`,
  `EI/src/Models/ConnectedAccount.php:154-168`). Mail has no push; calendar does.
- **Failures set a status.** An auth error retries once, then sets
  `reauth_required`; other failures set `error`
  (`EI/src/Jobs/Middleware/HandlesProviderFailures.php:64-73`, `EI/src/Jobs/IncrementalEmailSyncJob.php:209-217`).
  `SyncErrorReason` has one case, Google mail disabled (`EI/src/Enums/SyncErrorReason.php`).
- **Silent stalls stay `active`.** Inference, the likely causes of the 375 hours:
  1. A workspace that lost hosted access is skipped by `syncable()`, and its
     mailbox still reads `active`.
  2. A store batch with failures sets `last_synced_at` without advancing the
     cursor (`IncrementalEmailSyncJob.php:174-183`). Freshness can look fine while
     nothing moves.
  3. Rate-limit releases or a held unique lock (`#[UniqueFor(3600)]`) delay runs.
- **Progress lives only in cache** for 30 minutes (`EI/src/Services/MailboxSyncTracker.php:12`).
- **Customers see status on the accounts page only.** Staff see stale and
  failing counts in SystemAdmin.

**Production:** 3 of 32 mailboxes in `error` or `reauth_required`. One `active`
mailbox has gone 375 hours without a sync.

### What competitors chose and why

- **Attio** shows a progress tracker, "In sync", and emails on completion
  ([Attio](https://attio.com/help/reference/email-calendar/email-and-calendar-syncing)).
  Why: a CRM's value is trust in the timeline, so it reports state plainly.
- **Twenty** syncs "every 5 minutes" and documents import speed
  ([Twenty](https://docs.twenty.com/user-guide/calendar-emails/overview)). Why: an
  open-source product runs on many installs and cannot assume push infrastructure.
- **Lightfield** shows "Connected" again after reconnect and notifies when backsync
  finishes ([Lightfield](https://docs.lightfield.app/help/settings/mail-and-calendar/)).

**Provider facts.** Gmail push needs a Pub/Sub topic and a `watch` renewed at least
every 7 days ([Google](https://developers.google.com/workspace/gmail/api/guides/push)).
Graph subscriptions on Outlook messages last under 7 days
([Microsoft](https://learn.microsoft.com/en-us/graph/api/resources/subscription?view=graph-rest-1.0)).
Google refresh tokens die on revocation, a password change with Gmail scopes, six
months unused, or after 7 days for apps in Testing status
([Google](https://developers.google.com/identity/protocols/oauth2)). The last one
breaks self-hosted installs with an unverified OAuth app.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Accounts page only (today) | Status badge | | Nothing to build | 375 silent hours |
| B. Banner and Reconnect, computed per view | Emails page and record tabs check status and `last_synced_at` | Attio | Visible | Misses stalls whose clock still moves; no notice to an absent owner |
| C. Watchdog with stored health | `email:check-mailbox-health` every 15 minutes stores `health` and `health_reason`; banner, nav dot and notices read it | | One owner of the fact; catches every cause; works for agents | A new column and a migration to rehearse |
| D. Gmail push and Graph subscriptions | Replace polling | Superhuman (inference) | Lower latency | Self-hosted needs Pub/Sub and a public URL; stalls still happen; renewals add a failure mode |
| E. Email the owner only | On status change | Attio | Reaches absent owners | Teammates trust stale records |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 1 | 2 | 2 | 1 | 5 | 21 |
| B | 4 | 5 | 4 | 3 | 4 | 47 |
| C | 5 | 4 | 5 | 5 | 3 | **56** |
| D | 3 | 3 | 4 | 2 | 1 | 34 |
| E | 3 | 4 | 4 | 3 | 4 | 42 |

- **User value.** C is the only option that finds cause 2 above.
- **Future-proof.** D ties the design to two push systems with weekly renewals.
  Polling at five minutes already matches Twenty.

### Future-proof check

| Test | C (pick) | B (runner-up) |
|---|---|---|
| Agents | Pass: Rela can say "Ana's mailbox last synced Oct 12" from one column | Partial: logic sits in views |
| No AI key | Pass | Pass |
| Scale 10x | Pass: one indexed scan of 50 mailboxes per run | Pass |
| Providers | Pass: health is judged on cursor progress, not on a provider signal | Pass |
| Trust | Pass: state changes are logged | Partial |
| Reversibility | Pass: a column and a command | Pass |

### Recommendation

**Pick C, with B's banner and a nav dot as its surfaces.**

Decisions made:

- **Schedule it in `bootstrap/app.php`** inside the email feature block:
  `everyFifteenMinutes()->withoutOverlapping()->onOneServer()`.
- **States:** Healthy, Delayed (no progress for 1 hour, shown quietly), Stalled (24
  hours), Needs reconnect, Paused by billing, Error. Judge progress by
  `last_cursor_advanced_at`, a new timestamp, not `last_synced_at`.
- **Who is told.** The owner first, in the inbox and by email, on the change to
  Stalled or Needs reconnect. Only the owner can grant OAuth consent. Admins are
  told after 72 hours, and at once for Paused by billing, which only they can fix.
- **Teammates see a freshness note** on record tabs: "Ana's mail synced up to Oct 12".
- **One notice per state change**, never per run.
- **Push stays out of scope.** Revisit only if users ask for under-a-minute freshness.

**What would change our mind:** if Stalled fires more than once a month per 30
mailboxes without a real cause, the thresholds are wrong.

**Smallest first step:** the command plus the owner notice, reusing
`needingAttention()` and adding the billing case.

### Risks and mitigations

- **False alarms on quiet mailboxes.** Progress means a successful delta call, even
  an empty one, so a quiet inbox stays Healthy.
- **Paused-by-billing conflated with Stalled**, as `stale()` does today. Check hosted
  access first.
- **Self-hosted Testing-mode tokens.** Detect the 7-day pattern and link the OAuth
  publishing docs.

---

## 27. Connect prompt and empty states

### The question

What does someone see before any mailbox is connected, and what must they decide
before their first sync?

### Today

- **With no mailbox, the page is one card**, and Drafts and Templates disappear
  (`EI/resources/views/filament/pages/email-inbox.blade.php:2-23`). The card reads
  "Send emails in Relaticle" (`lang/en/filament/pages/email-accounts.php:132-136`).
- **Each provider button shows only when configured**
  (`EI/src/Filament/Actions/ConnectMailboxAction.php:39`). On a self-hosted install
  with neither configured, the card has a heading and no button (inference from
  `EI/resources/views/components/not-connected.blade.php:18-24`).
- **Connecting starts the import at once** (`EI/src/Actions/ConnectAccountAction.php`).
  The callback returns with a toast (`EI/src/Controllers/CallbackController.php:116-127`).
- **Setup asks about sharing after connect**, and only in workspace setup.
  New mail is stamped with the default until the answer re-tiers it
  (`SaveOnboardingSharing.php:24-40`).

### What competitors chose and why

- **Twenty** runs a wizard: visibility and contact creation before the first sync
  ([Twenty](https://docs.twenty.com/user-guide/calendar-emails/overview)). Why: it
  syncs everything and cannot take visibility back once teammates have read.
- **Lightfield** shows settings at their defaults before granting permissions
  ([Lightfield](https://docs.lightfield.app/help/settings/mail-and-calendar/)).
- **Attio** syncs first and lets you change the default later, applied to past mail
  ([Attio](https://attio.com/help/reference/email-calendar/sharing-emails)). Why:
  less friction at connect, paid for in G2 complaints.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Full-page card (today) | Card replaces tabs | | Simple | Hides working tabs; dead card when unconfigured |
| B. Card above usable tabs | Tabs stay | | Drafts and Templates reachable | No sharing choice for later members |
| C. Sample conversations behind the card | Seeded preview | | Shows the value | Fake data reads as real; cost |
| D. Sharing step after every connect | Reuse setup's Sharing step for any connect | Twenty | Choice where it matters | Page empty state unchanged |
| E. B plus D, plus a no-provider state | Card names the admin and the docs when nothing is configured | | Complete | Two surfaces to keep consistent |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 2 | 3 | 2 | 5 | 30 |
| B | 4 | 4 | 4 | 4 | 5 | 49 |
| C | 3 | 4 | 3 | 3 | 2 | 37 |
| D | 4 | 4 | 5 | 4 | 3 | 50 |
| E | 4 | 5 | 5 | 5 | 3 | **55** |

### Future-proof check

| Test | E (pick) | D (runner-up) |
|---|---|---|
| Agents | Pass: an agent can tell the user to connect and link the page | Pass |
| No AI key | Pass | Pass |
| Scale 10x | Pass | Pass |
| Providers | Pass: buttons come from `EmailProvider::isConfigured()`, so IMAP slots in | Pass |
| Trust | Pass: the choice is made before teammates can read | Pass |
| Reversibility | Pass | Pass |

### Recommendation

**Pick E.** It beats D because Drafts and Templates work without a mailbox, and
self-hosted admins need a way forward.

Decisions made:

- **Do not hold the sync for the answer.** Until the owner answers, the new
  mailbox's mail is stamped at the less open of the workspace default and
  Participants only. The answer re-tiers it, as it does today.
- **Ask on every connect path**, not only in setup. The callback returns to the
  sharing step until the owner has answered once.
- **Store that the owner answered.** A null tier is a valid answer ("use the
  workspace default"), so it cannot also mean "never asked". A nullable
  `sharing_confirmed_at` on the mailbox tells the two apart.
- **Existing mailboxes get the same card once**, on their next visit. Their tier
  does not change until the owner answers.
- **Copy says what syncs and who sees it**, not "Send emails in Relaticle".

**What would change our mind:** if more than 30% of new connects skip the sharing
step, fold it into the OAuth return page instead.

**Smallest first step:** move the connect card above the tabs in
`email-inbox.blade.php` and add the no-provider message.

### Risks and mitigations

- **Extra step lowers connect rates.** Measure connect completion before and after.
- **Stamping rule diverges between setup and later connects.** Both call
  `SaveOnboardingSharing`'s underlying `SaveMailboxSharingTier`.

---

## Corrections to the earlier documents

- **decisions.md 22** says `LinkEmailAction` "already exists" as a manual path. It
  only auto-links. Manual linking for email does not exist.
- **decisions.md 23** says "never copy" the most permissive rule. The code already
  applies it per copy, and Attio does too. This file keeps it on purpose.
- **decisions.md 23** calls Clarify's default the same as ours. Clarify's
  "Participants only" hides mail from non-participants. Ours shows them who and when.
- **decisions.md 23 and 27** treat asking at connect as new. Setup already asks,
  with Participants only preselected. Later connects are not asked.
- **benchmark.md** says Attio lets you pick which teammate gets a request. Its
  changelog says you "see which users you are requesting access from".
- **findings.md #2** proposes a watchdog as if no stale check existed. `stale()` and
  `needingAttention()` exist; only SystemAdmin reads them, and `stale()` counts
  billing-paused mailboxes as stalled.
- **decisions.md 25** filters opportunity mail to "since the opportunity was
  created". That drops the emails that started the deal.
