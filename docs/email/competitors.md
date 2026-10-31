# Why competitors built email the way they did

Read on 2026-10-31. [benchmark.md](benchmark.md) and
[benchmark-modern.md](benchmark-modern.md) record what each product does. This file
records why: who pays, what bet the product makes, what reasoning its makers gave, and
what the bet cost them. The goal is to copy reasoning, not screens.

How to read it:

- Every factual claim carries a source URL. Quotes are copied from the source.
- **Inferred** marks our reading of a product's constraints. No source states it.
- The six future-proof tests come from [method.md](method.md). Each competitor's own
  design is scored, not ours.
- Relaticle numbers come from [README.md](README.md): 32 mailboxes (27 Gmail, 5
  Microsoft), 372,714 emails, 54% linked to no record, 95.4% at `metadata_only`, 0
  access requests, 1.12 messages per thread.
- Prices are list prices per user per month, billed annually, unless stated.

## Contents

1. [Attio](#1-attio)
2. [Superhuman](#2-superhuman)
3. [HubSpot](#3-hubspot)
4. [Close](#4-close)
5. [Pipedrive](#5-pipedrive)
6. [Front](#6-front)
7. [Missive](#7-missive)
8. [Gmail](#8-gmail)
9. [Outlook](#9-outlook)
10. [HEY](#10-hey)
11. [Salesforce Einstein Activity Capture](#11-salesforce-einstein-activity-capture)
12. [Streak](#12-streak)
13. [Folk](#13-folk)
14. [Twenty](#14-twenty)
15. [Notion Mail](#15-notion-mail)
16. [Shortwave](#16-shortwave)
17. [Spark](#17-spark)
18. [Zero](#18-zero)
19. [Inbox Zero](#19-inbox-zero)
20. [Cora](#20-cora)
21. [Day.ai](#21-dayai)
22. [Clarify](#22-clarify)
23. [Lightfield](#23-lightfield)
24. [Strategic map](#strategic-map)
25. [Five market shifts that shape our decisions](#five-market-shifts-that-shape-our-decisions)
26. [What would make our direction wrong](#what-would-make-our-direction-wrong)

---

## 1. Attio

**Who they serve and how they make money.**
- Startups and scale-ups that want a flexible CRM. Free (3 seats), Plus $35, Pro $79,
  Enterprise custom ([pricing](https://attio.com/pricing)).
- Email is gated by plan. Free and Plus sync 1 mailbox per user, Pro 2. Free can only
  manage subject and metadata visibility. Full-content sharing starts on Plus, and
  sharing with individual users starts on Pro. Sends are capped at 200 a month on Free
  and 1,000 on Plus ([pricing](https://attio.com/pricing)).

**Their email bet.** Email is raw material that builds and enriches records, and you
keep replying in Gmail.

**Why they made it.**
- Nicolas Sharp, CEO: Attio "automatically ingests and understands all the rich
  context of how a business operates". "You connect your data and your CRM builds
  itself in front of you. Customers love this because CRM has historically meant
  manual data entry" ([GV interview](https://www.gv.com/news/interview-with-nicholas-sharp-founder-of-attio)).
- The help center tells users to stay in their mail client: "Continue to use your
  Gmail or Microsoft 365 inbox to reply to emails and receive notifications". It adds
  "Attio is not an email service provider"
  ([send emails](https://attio.com/help/reference/email-calendar/send-emails-in-attio)).
- "Attio does not have a traditional inbox page or sent folder"
  ([view emails](https://attio.com/help/reference/email-calendar/view-emails-and-meetings)).
- Inferred: competing with Gmail as a client is a fight Attio chose not to have. Email
  earns its keep by filling records, which is what Attio sells.
- Inferred: read-time matching by address and domain avoids a link table that must be
  rewritten when a domain changes.
- Inferred: sharing granularity doubles as an upgrade lever, since it is plan-gated.

**The cost they accepted.**
- G2 lists email sharing among top cons, 19 mentions. One reviewer: "My teammates are
  sensitive to sharing and Attio doesn't have a whitelisting"
  ([G2](https://www.g2.com/products/attio/reviews?qs=pros-and-cons)).
- Another G2 reviewer: "email sync does not meet our needs"
  ([G2](https://www.g2.com/products/attio/reviews)).
- Reddit users dislike all-or-nothing sync with no date cutoff
  ([r/CRM](https://www.reddit.com/r/CRM/comments/1oq9jte/)).

**Trajectory 2024 to 2026.**
- 2025: compose anywhere, inline reply, auto summaries and labels
  ([compose](https://attio.com/changelog/2025/compose-emails-directly-in-attio),
  [summaries](https://attio.com/changelog/2025/auto-summaries-and-labels)).
- Dec 2025: request access lets you choose the owner
  ([changelog](https://attio.com/changelog/2025/changelog-december-17-2025)).
- Jan 2026: email tabs on deals and custom objects
  ([changelog](https://attio.com/changelog/2026/emails-on-deals-and-custom-objects)).
- Feb to Mar 2026: a hosted MCP server with `semantic-search-emails`, then the Ask
  Attio agent across Anthropic, Vertex AI and OpenAI
  ([MCP](https://docs.attio.com/mcp/overview),
  [Ask Attio](https://attio.com/engineering/blog/ask-attio-a-technical-look-at-our-new-agent)).
- Signal: email becomes agent context. A human inbox is still absent.

**Six future-proof tests.**
1. Agents: pass. MCP acts as the user and asks before every write ([help](https://attio.com/help/reference/how-to-guides/attio-mcp-prompts-and-best-practices)).
2. No AI key: pass. Sync, tabs and sharing need no model.
3. Scale 10x: partial. Read-time matching scales, but content search is absent.
4. Providers: partial. Gmail and Microsoft 365 only, no shared inboxes, Microsoft syncs Inbox and Sent only.
5. Trust: pass. Four tiers, protected lists and request access are explainable.
6. Reversibility: pass. Matching rules change without migrating stored links.

**Lesson for Relaticle: adapt.** Copy read-time matching for opportunity email and the
Subject-line default. Do not gate privacy granularity behind a plan: an open-source
install must get every tier. Build the one thing Attio refuses, a thread list, because
54% of our mail has no surface today.

---

## 2. Superhuman

**Who they serve and how they make money.**
- Individual professionals and executive teams on Gmail or Microsoft 365. Starter $25,
  Business $33 ([pricing](https://superhuman.com/plans/mail)).
- Business gates Auto Drafts, Ask AI, the Mail MCP server and HubSpot and Salesforce
  integrations ([pricing](https://superhuman.com/plans/mail)).
- Since 2025 the parent company is Superhuman, formerly Grammarly
  ([rebrand](https://www.grammarly.com/blog/company/announcing-company-rebrand-to-superhuman)).

**Their email bet.** Email is a personal performance workspace. Speed and the keyboard
turn triage into a habit worth paying for.

**Why they made it.**
- Rahul Vohra: customers "get through their email twice as fast as before, reply 1-2
  days sooner, and save 4 hours per person every single week"
  ([AI post](https://blog.superhuman.com/the-next-superhuman-ai/)).
- The 2025 auto labels copy executive assistants: "The categories include: things you
  need to respond to, things you're waiting on, meeting requests, marketing emails"
  ([AI post](https://blog.superhuman.com/the-next-superhuman-ai/)).
- On the acquisition: "Email is the main communication tool for billions of people
  worldwide and the number-one use case for Grammarly customers"
  ([Grammarly](https://www.grammarly.com/blog/company/grammarly-to-acquire-superhuman)).
- The speed target is under 100 ms per action
  ([speed](https://blog.superhuman.com/superhuman-is-built-for-speed)).
- Inferred: a premium price funds speed engineering and onboarding. Building on Gmail
  and Outlook avoids hosting mail.

**The cost they accepted.**
- PCMag: "An overpriced email tool that underdelivers"
  ([PCMag](https://www.pcmag.com/reviews/superhuman)).
- G2: "the hotkey-heavy workflow comes with a steep learning curve"
  ([G2](https://www.g2.com/products/superhuman-mail/reviews?qs=pros-and-cons)).
- Vohra admits mobile lagged: "our mobile apps have not yet reached that bar"
  ([mobile redesign](https://blog.superhuman.com/we-redesigned-superhuman-mail-for-ios-and-android/)).

**Trajectory 2024 to 2026.**
- Feb 2025: auto labels, auto archive, drafts for every email that needs a response.
- Jul 2025: acquired by Grammarly ([post](https://blog.superhuman.com/superhuman-is-being-acquired-by-grammarly/)).
- Dec 2025: agentic writing and Ask AI. "In 2024, we doubled the percentage of emails
  sent with AI. In 2025, we doubled again"
  ([post](https://blog.superhuman.com/new-in-superhuman-ai-agentic-writing-ask-ai-android)).
- 2026: Email Assistant works "directly inside your existing Gmail or Outlook inbox", no
  app switch ([help](https://help.superhuman.com/hc/en-us/articles/46005854346893-Email-Assistant-by-Superhuman-Mail-Gmail)).
- Signal: even the best client now sells its intelligence without its interface.

**Six future-proof tests.**
1. Agents: partial. Mail MCP exists, but only on Business and only for one person.
2. No AI key: pass. Speed, splits and shortcuts work without AI.
3. Scale 10x: pass. A local database and prefetching are built for big mailboxes.
4. Providers: partial. Gmail and Microsoft 365 only.
5. Trust: partial. No team privacy tiers; AI reads every mailbox it labels.
6. Reversibility: pass. Mail stays at the provider.

**Lesson for Relaticle: copy the frame, adapt the craft.** "Need to respond to" and
"waiting on" are an assistant's two piles, which validates Needs reply and Awaiting
reply. Copy the speed discipline (prefetch, optimistic actions), not the price or the
keyboard-only learning curve. The Email Assistant shows triage can live outside a
client, so Rela must reach the same states through tools.

---

## 3. HubSpot

**Who they serve and how they make money.**
- SMB to mid-market, sold per hub and per seat. Sales Hub Starter from $7 to $20,
  Professional $90 to $100 plus $1,500 onboarding, Enterprise $150
  ([pricing](https://www.hubspot.com/pricing/sales)).
- One-to-one email exists on every plan. Sequences start on Professional
  ([pricing](https://www.hubspot.com/pricing/sales)). The AI help desk inbox lives in
  Service Hub Professional and Enterprise
  ([help desk](https://knowledge.hubspot.com/help-desk/move-an-inbox-and-historical-conversations-to-help-desk)).

**Their email bet.** Email is an activity logged onto records. Team mailboxes are a
separate service product.

**Why they made it.**
- "A connected inbox is required to send emails through the CRM and make use of certain
  Sales Hub and Service Hub features"
  ([FAQ](https://knowledge.hubspot.com/connected-email/hubspot-crm-email-integration-faq)).
- A community expert explains the 2025 default: connecting "simply authorizes HubSpot
  to let you send emails from HubSpot"
  ([community](https://community.hubspot.com/t/emails-sent-from-gmail-not-logging-in-hubspot-without-extension-or-bcc/133054)).
- On two inboxes, a community answer says Help Desk is "built around tickets, SLAs",
  so the newer AI "lives there because it depends on ticketing data"
  ([community](https://community.hubspot.com/t/conversations-inbox-vs-help-desk-inbox-why-two-and-why-do-most-ai-features-only-live-in-one/140552)).
- Inferred: per-hub pricing rewards putting each inbox in the hub that sells it.

**The cost they accepted.**
- Email copied onto every associated deal: "email threads for 20-30 clients end up on
  every single deal -- making the email log an absolutely mess to use"
  ([community](https://community.hubspot.com/t/email-logging-filter-issue/140412)).
- Enterprise Sales users must buy Service seats to get the AI inbox
  ([community](https://community.hubspot.com/t/conversations-inbox-vs-help-desk-inbox-why-two-and-why-do-most-ai-features-only-live-in-one/140552)).
- Opens by colleagues still notify reps, which "is not allowed" in one company
  ([r/hubspot](https://www.reddit.com/r/hubspot/comments/1nu747s/hubspot_tracking_internal_emails/)).

**Trajectory 2024 to 2026.**
- Logging moved from replies-only to "Log all emails from known contacts", now "the
  default logging option for all accounts"
  ([logging rules](https://knowledge.hubspot.com/connected-email/manage-contact-email-logging-rules)).
- Apr 2026: the remote MCP server reached GA with read and write access to emails,
  "with your existing HubSpot permissions respected throughout"
  ([changelog](https://developers.hubspot.com/changelog/remote-hubspot-mcp-server-is-now-generally-available)).
- The prospecting agent sends from the contact owner's connected mailbox
  ([prospecting agent](https://knowledge.hubspot.com/prospecting/use-the-prospecting-agent)).
- Signal: capture more by default, then let agents act on it.

**Six future-proof tests.**
1. Agents: pass. One MCP server with the user's permissions.
2. No AI key: pass. Logging is rule-based.
3. Scale 10x: partial. Copying each email onto every association multiplies rows and noise.
4. Providers: pass. Gmail, Microsoft and IMAP.
5. Trust: partial. Logging all known-contact mail by default needs a never-log list to stay defensible.
6. Reversibility: fail. Logged emails become activity records, so a looser rule cannot be cleanly undone.

**Lesson for Relaticle: avoid the copy, copy the picker.** Never copy one email onto every
associated record; link it once and let views read the links. Copy the never-log list and
the visible "Associated with" picker. HubSpot keeps team mailboxes in another product; we
can merge them only if the list stays scoped to tracked records.

---

## 4. Close

**Who they serve and how they make money.**
- Founders, owners and small sales teams that sell by phone and email. Solo $9,
  Essentials $35, Growth $99, Scale $139
  ([pricing](https://www.close.com/pricing)).
- Two-way sync and the inbox are on every plan. Bulk email and workflows start on
  Growth. AI runs on monthly credits per user
  ([pricing](https://www.close.com/pricing)).

**Their email bet.** The inbox is a to-do list of things to react to, not a copy of the
mailbox.

**Why they made it.**
- The Inbox "should be considered a to-do list". "Your sales team should be able to work
  exclusively from their Close inbox" ([help](https://help.close.com/feature-guide/inbox)).
- "Most CRMs are systems of record. Close has always been a system of action"
  ([press release](https://www.globenewswire.com/news-release/2026/06/09/3309383/0/en/close-launches-chloe-an-ai-sales-agent-built-directly-into-the-crm.html)).
- "You don't have to do the work somewhere else and come back later to tell your CRM
  about it." Close calls itself "Independent and profitable"
  ([about](https://close.com/about)).
- Only mail that reaches the client's Inbox folder appears
  ([help](https://help.close.com/feature-guide/inbox)).
- Inferred: a profitable, self-funded company must win daily use. A queue of things to do
  brings reps back every morning; a mailbox mirror does not.

**The cost they accepted.**
- G2: "the Inbox catches emails from all leads and contacts, which can make it cluttered
  and harder to prioritize key conversations". The reviewer asks for an "Ignored" option
  ([G2](https://www.g2.com/products/close/reviews?page=2&qs=pros-and-cons)).
- G2: the inbox "sometimes seems a bit confusing"
  ([G2](https://www.g2.com/products/close/reviews?qs=pros-and-cons)).
- You cannot reply from a Potential Contact notification until you add it to a lead
  ([help](https://help.close.com/feature-guide/inbox)).

**Trajectory 2024 to 2026.**
- Chloe, an AI agent, reached GA in June 2026 after 306 beta businesses
  ([press release](https://www.globenewswire.com/news-release/2026/06/09/3309383/0/en/close-launches-chloe-an-ai-sales-agent-built-directly-into-the-crm.html)).
- Marketing says Chloe "replies to emails" ([Chloe](https://close.com/chloe)). The help
  center says Chloe "does not send messages independently"
  ([help](https://help.close.com/feature-guide/chloe)).
- "Every action Chloe takes is logged, reviewable, and editable"
  ([Chloe](https://close.com/chloe)).
- Signal: the action queue gains an agent that works the same queue.

**Six future-proof tests.**
1. Agents: partial. Chloe is native; no public CRM MCP server was found in these sources.
2. No AI key: pass. The queue, Done and reply detection are rules.
3. Scale 10x: pass. Only Inbox-folder mail from known leads enters the queue.
4. Providers: pass. Gmail, Microsoft and custom SMTP are supported ([Capterra](https://www.capterra.com/p/132667/Close-io/reviews?page=4)).
5. Trust: partial. Lead visibility rules exist only on Scale.
6. Reversibility: pass. Done syncs both ways with Gmail; nothing is copied beyond sync.

**Lesson for Relaticle: copy.** Needs reply is a to-do list scoped to known records, and
Done must sync back to the provider. "Potential Contacts" is our Unlinked view with one
click to create a person. Give a per-contact mute from day one, the exact request Close
users file.

---

## 5. Pipedrive

**Who they serve and how they make money.**
- Small pipeline-driven sales teams. Seats from $14 (Lite) to $79
  ([review](https://www.bigin.com/crm-reviews/pipedrive.html)).
- Lite has no email sync. Growth syncs one account. The team inbox starts on Premium
  ([sync](https://support.pipedrive.com/en/article/email-sync),
  [setup](https://support.pipedrive.com/en/article/how-can-i-set-up-the-email-sync)).
- AI email writing, summaries and suggested replies need Premium
  ([AI](https://support.pipedrive.com/en/article/pipedrive-ai)).

**Their email bet.** Email is a deal-linked feature you unlock by upgrading. The Sales
Inbox mirrors the mailbox inside the CRM.

**Why they made it.**
- "Use email sync to centralize your email communication in Pipedrive". You can "View,
  send and reply to email conversations directly from your Sales Inbox"
  ([sync](https://support.pipedrive.com/en/article/email-sync)).
- The 2025 AI vision promises digital teammates "while allowing salespeople to stay in
  control" ([press](https://www.pipedrive.com/en/newsroom/pipedrive-unveils-next-generation-ai-crm-with-agentic-experience)).
- Visibility is chosen at connect: Private or Shared, plus history depth and folders
  ([setup](https://support.pipedrive.com/en/article/how-can-i-set-up-the-email-sync)).
- Inferred: sync is the main reason to leave Lite, so it is the upgrade lever.
- Inferred: small-business owners fear exposing reps' mail, so visibility is asked up front.

**The cost they accepted.**
- "Lite, the $14 plan that anchors all the advertising, has no email sync. Not limited
  sync. None" ([review](https://www.bigin.com/crm-reviews/pipedrive.html)).
- Users report "emails don't always attach to deals when sent in Google Mail" and the
  "email interface inside Pipedrive isn't that good"
  ([review](https://www.bigin.com/crm-reviews/pipedrive.html)).
- AI email tools are absent from Lite and Growth
  ([review](https://crmsorted.com/reviews/pipedrive)).

**Trajectory 2024 to 2026.**
- Feb 2025: agentic AI vision. Nova, an assistive meeting agent, is in beta
  ([blog](https://www.pipedrive.com/en/blog/ai-sales-agent)).
- Jun 2026: native MCP server "respecting existing user permissions and maintaining a
  full audit trail" ([press](https://www.pipedrive.com/en/newsroom/pipedrive-launches-native-mcp-server-bringing-crm-workflows-directly-into-ai-assistants)).
- Admins can switch MCP off company-wide; Gmail archiving became two-way
  ([updates](https://www.pipedrive.com/en/product-updates)).
- Signal: SMB CRMs treat outside agents as a channel, with an admin kill switch.

**Six future-proof tests.**
1. Agents: pass. MCP with permissions, audit trail and an off switch.
2. No AI key: pass. Sync and the Sales Inbox need no model.
3. Scale 10x: partial. A shared limit of 40 email actions a minute per company ([sync](https://support.pipedrive.com/en/article/email-sync)).
4. Providers: pass. Gmail, Microsoft and other providers, with a remove-and-reconnect path for switching.
5. Trust: pass. Private, Shared and Team only; only the owner changes it ([benchmark](benchmark.md)).
6. Reversibility: partial. Visibility can change, but history depth is set at connect.

**Lesson for Relaticle: copy the controls, avoid the gate.** Ask visibility, history start
and folders at connect, and repeat the visibility choice in the composer. Copy the MCP
kill switch for admins. Never gate sync itself: a self-hosted install with one plan must
get the whole feature.

---

## 6. Front

**Who they serve and how they make money.**
- Support, operations and account teams that share mailboxes. Starter $25 (10 seats, one
  channel type), Professional $65, Enterprise $105 ([pricing](https://front.com/pricing)).
- Copilot is a $20 add-on below Enterprise. Autopilot starts at $0.05 a conversation
  ([review](https://www.getmacha.com/blog/front-ai-review)).

**Their email bet.** Email is a team's shared work queue, and every conversation has one
owner.

**Why they made it.**
- Co-founder Laurent Perrin, via Mathilde Collin: "The email inbox has become the primary
  location of decision making for knowledge workers". Collaboration in email is "work on
  top of work" ([Medium](https://collinmathilde.medium.com/our-vision-for-front-ad9296dca6af)).
- Collin, 2018: "Shared inbox is not something people are looking for". Shared addresses
  were the go-to-market ([YC](https://www.ycombinator.com/blog/mathilde-collin-on-feature-prioritization-and-employee-retention-at-front)).
- "We believe people can be far more efficient with the tool already used by their team,
  customers, partners" ([YC Q&A](https://www.ycombinator.com/blog/qa-with-mathilde-collin-cofounder-of-front)).
- Oct 2026: "We built Front's shared inbox so nothing got stuck with one person holding
  all the context" ([update](https://community.front.com/product-updates/put-ai-agents-to-work-with-your-team-for-seamless-collaboration-4098)).
- Inferred: per-seat pricing pushed Front toward support desks, where seat counts are high.

**The cost they accepted.**
- Per-seat price is the top complaint. One user: "Front is getting so expensive for our
  company" ([review](https://www.usecarly.com/blog/front-review)).
- Capterra users report lost two-way Outlook sync
  ([complaints](https://www.getmacha.com/blog/front-common-complaints-and-problems)).
- Front's own guide: you "cannot automate work with AI agents in individual inboxes"
  ([implementation](https://www.getmacha.com/blog/front-ai-implementation)).

**Trajectory 2024 to 2026.**
- Apr 2026: Autopilot Playbooks, Resolve and a new Copilot
  ([blog](https://front.com/blog/autopilot-playbooks-resolve-copilot)).
- Oct 2026: external agents built with Claude, Codex or Sierra join as teammates with
  "clear ownership, shared context"
  ([update](https://community.front.com/product-updates/put-ai-agents-to-work-with-your-team-for-seamless-collaboration-4098)).
- Agents get identity and permissions "just like a human teammate"
  ([help](https://help.front.com/en/articles/4991744)).
- Signal: an agent is assigned conversations the same way a person is.

**Six future-proof tests.**
1. Agents: pass. Agents are teammates with identity, permissions and analytics.
2. No AI key: pass. Assignment, comments and rules need no model.
3. Scale 10x: pass. Built for high-volume team queues.
4. Providers: partial. Outlook actions that do not sync back are a known complaint.
5. Trust: pass. An @mention shares one private conversation after a confirmation ([benchmark](benchmark.md)).
6. Reversibility: partial. Moving mail into shared inboxes changes who owns it.

**Lesson for Relaticle: adapt.** Show Rela as a named actor on a thread, the way Front
shows an agent teammate. Copy "mention shares only this conversation, after a
confirmation" for per-thread sharing. Avoid assignment and SLAs: our threads average 1.12
messages, which is sales, not support.

---

## 7. Missive

**Who they serve and how they make money.**
- Small teams of 3 to 50 that run on email, SMS and WhatsApp. Starter $14 (up to 5
  users), Productive $24, Business $36 ([pricing](https://missiveapp.com/pricing)).
- Rules, integrations, API, MCP and the AI assistant start on Productive
  ([pricing explained](https://www.getmacha.com/blog/missive-pricing-explained)).

**Their email bet.** An email is a team chat object: you discuss the message inside the
message.

**Why they made it.**
- Philippe Lehoux: the company is "self-funded", with "a small team of three co-founders
  and one employee" at $1M ARR ([blog](https://missiveapp.com/blog/how-we-built-1m-arr-email-client)).
- "We knew it was impossible to bootstrap an email client on all major platforms ... with
  multiple codebases at our team size". One JavaScript codebase "is the main reason we
  can compete with massively funded startups" ([blog](https://missiveapp.com/blog/how-we-built-1m-arr-email-client)).
- "As a small team ourselves, we believe in accessible pricing. Your local car repair shop
  probably uses Missive" ([pricing](https://missiveapp.com/pricing)).
- Inferred: a tiny team cannot host mail or fund inference, so it rides on the provider
  and lets customers bring their own AI key.

**The cost they accepted.**
- Capterra: some say pricing "feels expensive for solo users or small teams, especially
  since rules" sit on the higher plan ([Capterra](https://www.capterra.com/p/152884/Missive)).
- G2 mines "Missing Features" as the leading con
  ([pricing explained](https://www.getmacha.com/blog/missive-pricing-explained)).

**Trajectory 2024 to 2026.**
- 2025: AI rules for labeling, drafts and pre-send checks; tasks reimagined; team doubled
  ([2025 review](https://missiveapp.com/blog/year-in-review-2025)).
- Apr 2026: "choose between using Missive AI credits or bring-your-own-key (BYOK)"
  ([changelog](https://missiveapp.com/changelog)).
- Sep 2026: "Let an agent triage new conversations against your CRM before you look at
  them" ([changelog](https://missiveapp.com/changelog)).
- Signal: a team inbox wants CRM context, and BYOK is becoming normal.

**Six future-proof tests.**
1. Agents: partial. API and MCP exist, but only on Productive and up.
2. No AI key: pass. Static rules work, and AI is optional or BYOK.
3. Scale 10x: partial. A 2026 fix stopped freezes at 1,000+ accounts ([changelog](https://missiveapp.com/changelog)).
4. Providers: pass. Gmail, Microsoft 365 and IMAP, even with untrusted certificates.
5. Trust: pass. SOC 2 Type II from the entry plan; avatars show who has access.
6. Reversibility: pass. Mail stays at the provider.

**Lesson for Relaticle: copy.** BYOK plus a "Fast or Powerful" model choice is the right
shape for a self-hosted Rela. Copy the "my sent or everyone's sent" toggle for Awaiting
reply. Missive users now want CRM context in triage, which is exactly the list we plan.

---

## 8. Gmail

**Who they serve and how they make money.**
- Everyone. Free consumer Gmail funds itself through the Google account; Workspace is paid
  per user.
- AI Inbox first required Google AI Ultra, US English only
  ([help](https://support.google.com/mail/answer/16845247)). It reached paid Workspace
  users in October 2026 ([update](https://workspaceupdates.googleblog.com/2026/10/gmails-ai-inbox-is-rolling-out-to-paid-Workspace-users.html)).
- Thread summaries are free; asking your inbox questions needs AI Pro or Ultra
  ([blog](https://blog.google/products-and-platforms/products/gmail/gmail-is-entering-the-gemini-era)).

**Their email bet.** The mailbox host owns triage, and AI rides on one smart-features consent.

**Why they made it.**
- Blake Barnes, VP Product: "Your inbox is full of important information, but accessing it
  has required you to become a power searcher"
  ([blog](https://blog.google/products-and-platforms/products/gmail/gmail-is-entering-the-gemini-era)).
- For business: ask "What's the latest status of the Acme contract?"
  ([Workspace blog](https://workspace.google.com/blog/product-announcements/more-personalized-and-proactive-assistance-in-gmail-coming-to-business-customers)).
- AI Inbox shows "Suggested to-dos" and "Topics to catch up on"
  ([update](https://workspaceupdates.googleblog.com/2026/10/gmails-ai-inbox-is-rolling-out-to-paid-Workspace-users.html)).
- Google's rule for everyone else: "Apps accessing restricted data from or through a
  third-party server must undergo an annual security assessment"
  ([restricted scopes](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification)).
- Inferred: gating to Ultra limits inference cost while proving demand.

**The cost they accepted.**
- You cannot turn off only the AI summary. Smart features are bundled, so turning them off
  also kills tabs and Smart Compose ([community](https://support.google.com/mail/thread/402740666/how-to-turn-off-ai?hl=en)).
- A Reddit user calls this "holding 'Important' messages hostage" behind AI permissions
  ([r/GMail](https://www.reddit.com/r/GMail/comments/1ow65ip/gmail_is_holding_important_messages_hostage/)).
- Senders report summaries that misstate offers
  ([r/Emailmarketing](https://www.reddit.com/r/Emailmarketing/comments/1n72r6k/gmail_ai_summary_can_it_be_managed)).

**Trajectory 2024 to 2026.**
- 2026: AI Overviews in search went global in September; AI Inbox reached Workspace in
  October ([update](https://workspaceupdates.googleblog.com/2026/09/gmail-searchs-ai-overviews-now-available-globally.html)).
- AI Inbox skips delegated inboxes and non-Primary tabs
  ([help](https://support.google.com/mail/answer/16845247)).
- Signal: generic "what needs me" triage becomes a free feature of the host.

**Six future-proof tests.**
1. Agents: partial. Gemini sees all; third-party agents pay the restricted-scope tax.
2. No AI key: pass. Categories and filters work, though bundled with AI consent.
3. Scale 10x: pass.
4. Providers: fail. It is one provider and works only on itself.
5. Trust: partial. Bundled consent drew a visible backlash.
6. Reversibility: partial. Users cannot cleanly opt out of one AI feature.

**Lesson for Relaticle: avoid competing, avoid bundling.** Gmail will give everyone a
free to-do inbox, so our list wins only with record context. Let users turn off Rela
summaries without losing Needs reply or labels. Inferred: our cloud must budget the
annual assessment for Gmail read scopes, while self-hosters register their own Google
project.

---

## 9. Outlook

**Who they serve and how they make money.**
- Microsoft 365 organizations. Copilot features need a Copilot licence
  ([FAQ](https://support.microsoft.com/en-us/outlook/frequently-asked-questions-about-copilot-in-outlook)).
- 16% of Relaticle mailboxes are Microsoft ([README](README.md)).

**Their email bet.** The assistant runs the inbox, and the client becomes the place you
supervise it.

**Why they made it.**
- Apr 2026: "Outlook used to be where you worked. Starting today, it's where Copilot works
  for you" ([blog](https://techcommunity.microsoft.com/blog/outlook/copilot-in-outlook-new-agentic-experiences-for-email-and-calendar/4514601)).
- Copilot works "showing what it's doing along the way so you can review, adjust, or step
  in whenever you choose" ([blog](https://techcommunity.microsoft.com/blog/outlook/copilot-in-outlook-new-agentic-experiences-for-email-and-calendar/4514601)).
- Prioritize assigns high, normal or low "and gives a reason why"
  ([help](https://support.microsoft.com/en-us/outlook/copilot-outlook/prioritize-my-inbox)).
- A Microsoft PM: "It will make inboxes less passive"
  ([Inside Track](https://www.microsoft.com/insidetrack/blog/wrangling-our-email-with-the-prioritize-my-inbox-feature-in-microsoft-outlook)).
- Inferred: retiring EWS moves every third party onto Graph, where Microsoft controls
  throttling and the agent surface.

**The cost they accepted.**
- New Outlook draws anger: "A complete fiasco with a huge amount of gaps and issues"
  ([Microsoft Q&A](https://learn.microsoft.com/en-us/answers/questions/5538340/new-outlook-a-complete-fiasco-with-a-huge-amount-o)).
- How-To Geek: after six months "things have got worse"
  ([How-To Geek](https://www.howtogeek.com/new-outlook-still-just-as-bad-after-six-months/)).
- Copilot skips group mailboxes and non-Exchange Online mailboxes
  ([FAQ](https://support.microsoft.com/en-us/outlook/frequently-asked-questions-about-copilot-in-outlook)).

**Trajectory 2024 to 2026.**
- 2025: Prioritize my inbox. Apr 2026: agentic triage and follow-up drafting.
- Oct 2026: EWS blocking began; full shutdown in 2027
  ([Microsoft](https://techcommunity.microsoft.com/blog/exchange/exchange-online-ews-your-time-is-almost-up/4492361)).
- Signal: providers deprecate APIs on their own schedule. Relaticle already uses Graph
  (`packages/EmailIntegration/src/Services/MicrosoftGraphMailService.php`).

**Six future-proof tests.**
1. Agents: pass inside Microsoft 365.
2. No AI key: pass. Focused Inbox and rules remain.
3. Scale 10x: pass.
4. Providers: fail for third parties. The EWS retirement forces rewrites.
5. Trust: pass. Each priority carries a reason.
6. Reversibility: partial. Users report being forced into the new client.

**Lesson for Relaticle: copy the card anatomy.** "Priority plus reason" and "show what it is
doing" are the shape of a Rela card. Keep every provider behind one interface, since
Microsoft just proved APIs expire.

---

## 10. HEY

**Who they serve and how they make money.**
- Individuals and small businesses who want calm email. A flat $99 a year
  ([The HEY Way](https://www.hey.com/the-hey-way/)).

**Their email bet.** The human decides where each sender goes, once, and no AI decides for
them.

**Why they made it.**
- "HI not AI. Email's better with a human at the helm. That's you"
  ([The HEY Way](https://www.hey.com/the-hey-way/)).
- "To make significant, meaningful upgrades to email, you need to build your own
  platform" ([The HEY Way](https://www.hey.com/the-hey-way/)).
- "We're not interested in your personal data"
  ([The HEY Way](https://www.hey.com/the-hey-way/)).
- Quoted on the homepage: "HEY assumes that only the people you want email from should be
  able to email you" ([hey.com](https://www.hey.com/)).
- Inferred: hosting mail makes the Screener reliable, and a small profitable team can
  sustain a niche.

**The cost they accepted.**
- A user who left: "I had to check three separate inboxes"
  ([blog](https://www.mikebuss.com/posts/leaving-hey)).
- A loyal user: "it works best when you just submit to whatever they want you to do"
  ([review](https://justinharter.com/an-updated-review-of-how-im-using-hey-email-in-2025-despite-things/)).
- "You are stuck with HEY's app on every platform"
  ([review](https://justinharter.com/an-actually-useful-review-of-proton-mail-vs-fastmail-vs-hey)).

**Trajectory 2024 to 2026.**
- The calendar is the biggest addition; "Not too much has changed"
  ([review](https://justinharter.com/an-updated-review-of-how-im-using-hey-email-in-2025-despite-things/)).
- "HI not AI" is still stated in 2026 ([The HEY Way](https://www.hey.com/the-hey-way/)).
- Signal: a principled no-AI product survives as a niche, not as the market.

**Six future-proof tests.**
1. Agents: fail. No agent surface was found.
2. No AI key: pass by design.
3. Scale 10x: pass.
4. Providers: fail. You must move your address to HEY.
5. Trust: pass. Simple and explainable.
6. Reversibility: partial. Leaving means moving your address.

**Lesson for Relaticle: copy one idea.** One decision per sender that also moves past mail
is the right model for the Unlinked view: "track this company" or "ignore this sender".
Keep a complete no-AI path. Avoid forcing several piles on people.

---

## 11. Salesforce Einstein Activity Capture

**Who they serve and how they make money.**
- Enterprise Sales Cloud orgs. Standard EAC keeps at most 6 months of captured activity.
  Up to 5 years needs Sales Cloud Einstein, Inbox or Sales Engagement licences
  ([retention](https://help.salesforce.com/s/articleView?id=000382878&language=en_US&type=1)).

**Their email bet.** Old: capture email cheaply, outside the org. New since Summer '25:
store it as standard records so reports and agents can use it.

**Why they made it.**
- Old design: captured emails "aren't created as records and aren't saved in the database
  of Salesforce" ([help](https://help.salesforce.com/s/articleView?id=000384895&language=en_US&type=1)).
- The reversal: email data "wasn't stored in standard objects, wasn't accessible to many
  Salesforce Platform features, and couldn't be exported". The new setting makes it
  available to "reports, automation, SOQL, and APIs"
  ([retirement](https://help.salesforce.com/s/articleView?id=004633781&language=en_US&type=1)).
- The stated reason: "This unlocks reporting, automation, and Agentforce integration that
  the legacy features can't support"
  ([retirement](https://help.salesforce.com/s/articleView?id=005385951&language=en_US&type=1)).
- Inferred: the original design kept customers' storage bills low and Salesforce's data
  model untouched. Agents made that trade-off untenable.

**The cost they accepted.**
- Emails vanish from the timeline if a contact's address changes or after the retention
  window ([help](https://help.salesforce.com/s/articleView?id=000384895&language=en_US&type=1)).
- Sent emails stop appearing on accounts unless the recipient is a related contact
  ([help](https://help.salesforce.com/s/articleView?id=000382526&language=en_US&type=1)).
- Storage now counts: "100 users × 50 emails/day × 180 days retained = ~24 GB"
  ([storage](https://help.salesforce.com/s/articleView?id=005385953&language=en_US&type=1)).

**Trajectory 2024 to 2026.**
- Summer '25: "Sync Email as Salesforce Activity" stores Task and EmailMessage records.
- Spring '27: four legacy EAC features retire
  ([retirement](https://help.salesforce.com/s/articleView?id=005385951&language=en_US&type=1)).
- Signal: the largest CRM decided agents need email as queryable data inside the record store.

**Six future-proof tests.**
1. Agents: pass for the new design.
2. No AI key: pass. Capture is rule-based.
3. Scale 10x: partial. Storage now grows with every email.
4. Providers: partial. Gmail and Microsoft only.
5. Trust: pass. Automated mail is Don't Share until reviewed ([benchmark](benchmark.md)).
6. Reversibility: fail. The old design needed a migration to fix, which is under way.

**Lesson for Relaticle: this confirms our storage choice.** We already keep email in
Postgres where Rela and MCP can query it, which Salesforce is now paying to reach. Never
tie retention to a licence. Copy "automated mail starts unshared until its owner reviews it".

---

## 12. Streak

**Who they serve and how they make money.**
- Small teams of roughly 2 to 15 who live in Gmail. Free email tools; Pro $49, Pro+ $69,
  Enterprise $129 ([pricing](https://www.streak.com/pricing?pp=iaqb)).
- The free plan has tracking and mail merge but no pipelines.

**Their email bet.** The CRM lives inside Gmail, and email threads are the deals.

**Why they made it.**
- "Streak started because two founders needed a CRM but was frustrated of constantly
  switching between their inbox and external systems. They built a spreadsheet inside
  Gmail instead" ([about](https://start.streak.com/about)).
- "The best CRM is one that fits how you already work"
  ([about](https://start.streak.com/about)).
- "Building inside Gmail since 2011". Still "independent, still profitable"
  ([about](https://start.streak.com/about)).
- Inferred: zero switching cost, bought with total dependence on Gmail's interface.

**The cost they accepted.**
- "It heavily slows down Gmail with any level of scale"
  ([review](https://efficient.app/apps/streak)).
- G2: "When pipelines get bigger or more complex, the reporting options feel limited"
  ([G2](https://www.g2.com/products/streak/reviews?qs=pros-and-cons)).
- Gmail only, so Microsoft shops cannot use it.

**Trajectory 2024 to 2026.**
- Feb 2026: AI summary, autofill and Q&A left beta. An MCP endpoint exists at
  `api.streak.com/mcp` ([updates](https://www.streak.com/updates)).
- Aug 2026: a Claude connector. Sep 2026: automations fire on new emails
  ([updates](https://www.streak.com/updates)).
- Signal: even the Gmail-native CRM now exposes its data outside Gmail.

**Six future-proof tests.**
1. Agents: pass. MCP and a Claude connector.
2. No AI key: pass. Pipelines and magic columns are rules.
3. Scale 10x: fail. Reviewers say Gmail slows down at volume.
4. Providers: fail. Gmail only.
5. Trust: pass. Teammates' unshared mail shows only to, from and date ([benchmark](benchmark.md)).
6. Reversibility: partial. Data lives in Streak but the workflow lives in Gmail's DOM.

**Lesson for Relaticle: copy two details.** "Just this thread" or "All emails with this
contact or domain" is the right manual-link prompt. A sparkle on auto-linked mail explains
where links came from. Avoid living inside another vendor's interface.

---

## 13. Folk

**Who they serve and how they make money.**
- Founders, agencies and relationship-driven businesses. Standard $24, Premium $48,
  Enterprise from $80 ([pricing](https://www.folk.app/pricing)).
- Standard syncs 1 account per member, Premium 5. Sequences and "Full history of
  interactions" are Premium. Internal and sensitive interaction controls are Enterprise
  ([pricing](https://www.folk.app/pricing)).

**Their email bet.** Email is relationship memory that fills a people-first CRM.

**Why they made it.**
- Founder Simo Lemhandez found CRM "the largest software category, and the NPS was very
  low" ([Growth Unhinged](https://www.growthunhinged.com/p/inside-folks-journey)).
- folk chose to be "People-first rather than sales process-first" and "more
  'Notion-like'" ([Growth Unhinged](https://www.growthunhinged.com/p/inside-folks-journey)).
- Sync skips mail older than 3 years and threads with more than 20 recipients, and is
  private by default ([benchmark](benchmark.md)).
- Inferred: caps on age and recipients cut cost and noise for a small team.

**The cost they accepted.**
- G2: "When emails are sent through Folk CRM, it does not automatically show as sent in
  email account such as outlook" ([G2](https://www.g2.com/products/folk-folk/reviews?qs=pros-and-cons)).
- G2: "Most of those workflow tools are locked behind the higher-priced Premium plan"
  ([G2](https://www.g2.com/products/folk-folk/reviews?qs=pros-and-cons)).

**Trajectory 2024 to 2026.**
- Follow-up, Recap and Workflow assistants; WhatsApp sync; an MCP server
  ([pricing](https://www.folk.app/pricing)).
- Signal: the relationship CRM sells AI follow-up reminders, not an inbox.

**Six future-proof tests.**
1. Agents: pass. An MCP server is listed.
2. No AI key: pass. Sync and contacts work without AI.
3. Scale 10x: pass. Age and recipient caps bound the data.
4. Providers: partial. Gmail and Outlook, no IMAP found.
5. Trust: pass. Private by default.
6. Reversibility: pass.

**Lesson for Relaticle: copy the noise caps.** Skip auto-linking for threads with many
recipients. That cuts list noise before any AI runs. Folk's Follow-up assistant is our
Awaiting reply view with a nudge.

---

## 14. Twenty

**Who they serve and how they make money.**
- Technical teams that want an open-source, customizable CRM. Self-hosting is free with Pro
  features. Cloud Pro is $9; Organization adds SSO and row-level permissions
  ([pricing](https://twenty.com/pricing), [FAQ](https://docs.twenty.com/user-guide/billing/how-tos/billing-faq)).
- Email sharing is "fully customizable" on every plan ([pricing](https://twenty.com/pricing)).
- Twenty is our closest peer: open source, self-hostable, with an MCP server.

**Their email bet.** Email is data for the record timeline inside an open CRM framework.
There is no inbox.

**Why they made it.**
- Félix Malfait, CEO: "I didn't like the idea of being hostage of a closed-source project"
  ([heise](https://www.heise.de/en/news/Three-questions-and-answers-How-Twenty-wants-to-replace-proprietary-sales-CRMs-10492349.html)).
- "The real opportunity is to build abstraction layers around that, so people can solve
  their own unique problems" ([heise](https://www.heise.de/en/news/Three-questions-and-answers-How-Twenty-wants-to-replace-proprietary-sales-CRMs-10492349.html)).
- Folder sync gives "better privacy by importing only the folders you need". Internal team
  emails do not sync, "preventing access to internal discussions"
  ([releases](https://twenty.com/releases)).
- Inferred: a framework invests in the data model; a triage list is a product opinion
  they have not taken.

**The cost they accepted.**
- Self-hosted Gmail sync fails while cloud works on the same account
  ([#15241](https://github.com/twentyhq/twenty/issues/15241),
  [#16073](https://github.com/twentyhq/twenty/issues/16073)).
- IMAP showed "synced" but imported nothing ([#13884](https://github.com/twentyhq/twenty/issues/13884)).
- Sep 2026: "IMAP backfill deadlocks permanently when a batch is entirely filtered out"
  ([issues](https://github.com/twentyhq/twenty/issues?q=is%3Aissue+is%3Aopen+%22IMAP+backfill%22)).

**Trajectory 2024 to 2026.**
- Feb 2025 Microsoft; Aug 2025 IMAP, SMTP and CalDAV; Nov 2025 label and folder sync
  ([releases](https://twenty.com/releases)).
- 2026: pick a model, "build agents that write emails", and expose the workspace to any
  MCP client ([releases](https://twenty.com/releases), [MCP](https://docs.twenty.com/user-guide/ai/capabilities/mcp)).
- Signal: open-source CRMs converge on IMAP plus MCP, still with no list.

**Six future-proof tests.**
1. Agents: pass. OAuth MCP on cloud and self-host.
2. No AI key: pass. The CRM runs without a model.
3. Scale 10x: partial. About 400 messages a minute, and backfill bugs at volume ([benchmark](benchmark.md)).
4. Providers: pass. Gmail, Microsoft and IMAP.
5. Trust: pass. Visibility is chosen in a wizard before the first sync.
6. Reversibility: pass.

**Lesson for Relaticle: our direct wedge.** Twenty has no conversation list, so ours is a
real differentiator in open source. Add IMAP later for the providers test. Twenty's
silent self-host failures mirror our 9% broken mailboxes, so sync health ships before
the list.

---

## 15. Notion Mail

**Who they serve and how they make money.**
- Notion users on Gmail. The app was free, a funnel into Notion and Notion AI
  ([help](https://www.notion.com/en-gb/help/get-started-with-notion-mail)).
- Launched 15 April 2025. Shut down 22 September 2026
  ([shutdown](https://www.notion.com/help/notion-mail-inbox-is-going-away-what-to-do-next)).

**Their email bet.** An inbox that "thinks like you" through AI labels and saved views.

**Why they made it.**
- Launch: "What might email look like if we started from scratch?"
  ([launch](https://www.notion.com/blog/introducing-notion-mail)).
- Shutdown: "As Notion agents have gotten more capable, we've seen more users hand off
  email workflows to them. Today, more than half of Notion Mail users manage emails
  without ever opening their inbox. So, we're going all in on using agents to run your
  inbox" ([TechCrunch](https://techcrunch.com/2026/06/25/notion-mail-shuts-down-amid-agent-takeover)).
- Inferred: a free client could not justify its cost once agents across Notion did the
  same jobs.

**The cost they accepted.**
- Reviewers said email never truly linked to the rest of Notion
  ([review](https://clean.email/blog/email-clients/notion-mail-review)).
- Gmail only, one address per account
  ([help](https://www.notion.com/en-gb/help/get-started-with-notion-mail)).
- HIPAA customers had to leave by June 30, 2026
  ([shutdown](https://www.notion.com/help/notion-mail-inbox-is-going-away-what-to-do-next)).

**Trajectory 2024 to 2026.**
- What survives: the Gmail AI connector, mail blocks in pages, and agent mail tools that
  "read, draft, and send email via Gmail"
  ([shutdown](https://www.notion.com/help/notion-mail-inbox-is-going-away-what-to-do-next)).
- Signal: email moved from a screen to a tool that agents call.

**Six future-proof tests.**
1. Agents: pass. That is where it went.
2. No AI key: fail. Auto labels were the core feature.
3. Scale 10x: not tested by its life.
4. Providers: fail. Gmail only.
5. Trust: partial. HIPAA users had an early cut-off.
6. Reversibility: pass. Two-way sync meant "your email history will stay exactly where it is in Gmail".

**Lesson for Relaticle: copy the escape hatch, avoid the generic inbox.** Our list must be
a view over synced data, so removing it never loses mail. Every list state needs a Rela
and MCP equivalent, because half of Notion's users never opened the inbox.

---

## 16. Shortwave

**Who they serve and how they make money.**
- Gmail power users and teams. Free; Pro $18; Business $30, Premier $45, Max $120, monthly
  billing ([review](https://www.thisandthat.chat/blog/shortwave-review/)).
- Tiers map to named Anthropic models
  ([review](https://cmdk.email/post/shortwave-review)).

**Their email bet.** Email is the richest data source for an AI agent, and the client exists
to host that agent.

**Why they made it.**
- "We're focusing on email initially, as it's (1) ubiquitous (2) an open platform and (3)
  has a diverse set of valuable business data" ([about](https://www.shortwave.com/about)).
- Andrew Lee: "people really need a reason to switch"
  ([AI Summer](https://www.aisummer.org/p/andrew-lee-on-running-an-ai-email)).
- Lee on the pivot: "We had over-hired, and for the wrong roles, so had to do layoffs".
  Later, "we had users using Shortwave who had no interest in using our email"
  ([X](https://x.com/startupandrew/status/2018396943672889372)).
- Inferred: inference cost drives the price ladder, which is why tiers name models.

**The cost they accepted.**
- Gmail first; Outlook users are a narrow fit
  ([review](https://www.thisandthat.chat/blog/shortwave-review/)).
- The free plan stamps "Sent with Shortwave" on every email
  ([pricing](https://www.shortwave.com/pricing)).

**Trajectory 2024 to 2026.**
- Oct 2025: Tasklet launched as a separate automation product, because "our AI agent
  technology could automate far more than just email"
  ([Tasklet](https://www.shortwave.com/blog/introducing-tasklet-ai-automation)).
- Jan 2026: Tasklet drafts appear in Shortwave ([changelog](https://www.shortwave.com/changelog/)).
- Signal: the agent outgrew the email client.

**Six future-proof tests.**
1. Agents: pass. MCP integrations and Tasklet.
2. No AI key: partial. The client works, but AI is the product.
3. Scale 10x: pass.
4. Providers: fail. Gmail only.
5. Trust: partial. Live shares and private comments, no tiers.
6. Reversibility: pass. Mail stays in Gmail.

**Lesson for Relaticle: copy the reading aids, plan for the agent.** Copy bundles for
automated senders and the summary at the top of a thread. Design Rela's email tools to
work without the page, since Shortwave's best users stopped using its email.

---

## 17. Spark

**Who they serve and how they make money.**
- Consumers and small teams on any provider. Free core; Premium about $59.99 a year for
  individuals at launch ([Spark 3](https://sparkmailapp.com/blog/all-new-spark)).

**Their email bet.** A free, polished client across providers. Premium sells focus and AI.
Since 2026 the desktop app is also a local context layer for agents.

**Why they made it.**
- "We started by making email smart, then improved team collaboration - and now we've
  tackled information overload" ([Spark 3](https://sparkmailapp.com/blog/all-new-spark)).
- On agents: email is "the richest context store they own", yet "almost entirely invisible
  to AI agents" ([Spark CLI](https://sparkmailapp.com/blog/introducing-spark-cli)).
- On Send: "If an account is set to Triage and an agent tries to send, Spark refuses"
  ([agents](https://sparkmailapp.com/blog/spark-agents-that-follow-through)).
- +AI is off by default; "you must opt in"
  ([privacy](https://sparkmailapp.com/legal/archives/privacy-app-20250213)).
- Inferred: a privacy reputation from server-side tokens pushed Spark toward a local CLI.

**The cost they accepted.**
- Spark stores an access token on its servers for push and team features
  ([privacy](https://support.readdle.com/spark/privacy/privacy-explained)). Users called
  this a broken privacy promise ([r/apple](https://www.reddit.com/r/apple/comments/ccontj/spark_mail_by_readdle_promised_privacy_but_didnt)).
- The Electron move brought slowness and partly loaded threads
  ([r/SparkMail](https://www.reddit.com/r/SparkMail/comments/1uosj7r)).

**Trajectory 2024 to 2026.**
- May 2026: Spark CLI, read-only, across Gmail, Outlook, Exchange, iCloud and IMAP
  ([CLI](https://sparkmailapp.com/blog/introducing-spark-cli)).
- Jul 2026: a Send permission with limits "Spark enforces"
  ([agents](https://sparkmailapp.com/blog/spark-agents-that-follow-through)).
- Signal: graduated agent permissions, enforced by the product, not the prompt.

**Six future-proof tests.**
1. Agents: pass. Read, triage and send are separate permissions.
2. No AI key: pass. +AI is opt-in.
3. Scale 10x: partial. Long threads load partly.
4. Providers: pass. Every major provider plus IMAP.
5. Trust: partial. Server-held tokens still worry users.
6. Reversibility: pass.

**Lesson for Relaticle: copy the permission ladder.** Rela and MCP should get read, triage
(labels, Done, links) and send as separate grants. The server must refuse, whatever the
prompt says.

---

## 18. Zero

**Who they serve and how they make money.**
- Individuals; Free, Pro $20 ([pricing](https://0.email/pricing)).
- MIT-licensed and self-hostable ([GitHub](https://github.com/Mail-0/Zero)).

**Their email bet.** An open-source, AI-native client, with self-hosting as the trust story.

**Why they made it.**
- "Most email services today are either closed-source, data-hungry, or too complex to
  self-host" ([GitHub](https://github.com/Mail-0/Zero)).
- "We believe that email is too important to be controlled by a single entity"
  ([about](https://0.email/about)).
- "We realized the real solution isn't just a new interface". The rest of the sentence
  says AI should act as an assistant in the inbox ([about](https://0.email/about)).
- Inferred: open source bought trust and contributors, not retention.

**The cost they accepted.**
- The company left the client. Zero became Orchid, an assistant in iMessage: "No app, no
  new inbox" ([Orchid](https://orchid.ai/blog/orchid-ai-assistant-imessage)).
- The founder called the change difficult and worthwhile
  ([YesPress](https://yespress.io/nizar-abi-zaher)).

**Trajectory 2024 to 2026.**
- Feb 2025 repo; Spring 2025 YC launch ([YC](https://www.ycombinator.com/companies/orchid-ai)).
- 2026: Orchid texts your day, drafts replies, books meetings. "The drafts stay drafts
  until you say send" ([Orchid](https://orchid.ai/blog/orchid-ai-assistant-imessage)).
- Signal: an open-source AI client was not enough. The assistant moved to messaging.

**Six future-proof tests.**
1. Agents: pass. That is the pivot.
2. No AI key: partial. The client worked, but AI was the pitch.
3. Scale 10x: pass. The list never loads bodies ([benchmark-modern](benchmark-modern.md)).
4. Providers: fail. Gmail first.
5. Trust: pass. Open source and self-hostable.
6. Reversibility: pass. Mail stayed in Gmail.

**Lesson for Relaticle: copy the code, not the strategy.** Take the row summary, prefetch
and undo toasts we already chose. Open source and self-hosting did not save a standalone
client. Record context is the moat.

---

## 19. Inbox Zero

**Who they serve and how they make money.**
- Founders and executives; Starter $18, Plus $28, Professional $42. The email app is free,
  AI is paid, Enterprise offers on-premise ([pricing](https://www.getinboxzero.com/pricing)).

**Their email bet.** AI works in the background of the inbox you already use. Reply
tracking is the core job.

**Why they made it.**
- Elie Steinbock: "It's not here to replace Gmail or Outlook". It organizes "in the
  background and also draft replies in your existing email client"
  ([interview](https://www.20i.com/blog/interview-with-elie-steinbock-founder-of-inbox-zero/)).
- On open source: "Being open means there's more trust and transparency. It also gives a
  marketing boost" ([interview](https://www.20i.com/blog/interview-with-elie-steinbock-founder-of-inbox-zero/)).
- It "flags the threads where someone went quiet on you, and the ones where you went quiet
  on them" ([assistant](https://www.getinboxzero.com/ai-email-assistant)).
- Inferred: not replacing the client avoids the "reason to switch" problem Shortwave hit.

**The cost they accepted.**
- "Open source, so you can run it for free" is "wrong for most companies, and the reason
  is in the license file" ([review](https://www.thisandthat.chat/blog/inbox-zero-review-2026/)).
- Every AI feature is paid; the free app is a funnel
  ([pricing](https://www.getinboxzero.com/pricing)).

**Trajectory 2024 to 2026.**
- Reply tracking, an MCP server, Slack and Telegram, meeting briefings and a notetaker
  ([home](https://www.getinboxzero.com/)).
- Signal: the two-state reply tracker became a product in its own right.

**Six future-proof tests.**
1. Agents: pass. MCP plus chat surfaces.
2. No AI key: partial. Free sender rules run first, but statuses need a model.
3. Scale 10x: pass. Rules filter before any model call.
4. Providers: pass. Gmail and Outlook.
5. Trust: pass. Open code and SOC 2.
6. Reversibility: pass. Output is Gmail labels.

**Lesson for Relaticle: copy (already chosen).** Two-stage Needs reply: free rules always,
Rela refinement optional, with a stored reason. Recompute on every send.

---

## 20. Cora

**Who they serve and how they make money.**
- Busy individuals on Gmail. About $20 a month, or included in the $20 Every bundle
  ([pricing](https://help.cora.computer/en/articles/13225579-what-are-cora-s-plans-and-pricing),
  [bundle](https://every.to/on-every/the-every-bundle-now-includes-cora)).

**Their email bet.** Most email should be read as a digest, not as a list.

**Why they made it.**
- Dan Shipper: "90% of the emails we get don't require a response"
  ([launch](https://every.to/p/introducing-cora-manage-your-inbox-with-ai)).
- Drafting came first. The team then saw that stress comes from "the cognitive load of
  reading, archiving, and organizing emails", so Cora centers on Briefs
  ([podcast](https://every.to/podcast/an-inside-look-at-building-an-email-client-in-three-months)).
- Cora "only drafts emails that it thinks that it can do a great job drafting"
  ([podcast](https://every.to/podcast/an-inside-look-at-building-an-email-client-in-three-months)).
- Hard limits: it "Can't delete emails" and "Can't send emails automatically"
  ([help](https://help.cora.computer/en/articles/13225520-getting-started-with-cora)).

**The cost they accepted.**
- "Cora is currently expensive to run"
  ([launch](https://every.to/p/introducing-cora-manage-your-inbox-with-ai)).
- Beta users "freaked out" at standalone pricing
  ([transcript](https://every.to/podcast/transcript-how-we-built-our-ai-email-assistant-a-behind-the-scenes-look-at-cora)).
- It handles only mail after activation, so a backlog stays
  ([ToolGPA](https://toolgpa.com/tools/cora)).

**Trajectory 2024 to 2026.**
- Dec 2024 waitlist, Jun 2025 general release, then a CLI and custom categories
  ([help](https://help.cora.computer/en/articles/13225579-what-are-cora-s-plans-and-pricing)).
- Signal: digest-first works for individuals, at a real inference cost.

**Six future-proof tests.**
1. Agents: partial. A CLI, no team permissions.
2. No AI key: fail. Classification and Briefs need a model.
3. Scale 10x: pass. It checks for mail every 10 minutes.
4. Providers: fail. Gmail only.
5. Trust: partial. Drafts only, single user.
6. Reversibility: pass. Mail stays in Gmail.

**Lesson for Relaticle: adapt.** A digest of FYI mail on tracked companies (decision 32) is
right. Build it from SQL first, so it works without a key, and let Rela summarize on top.

---

## 21. Day.ai

**Who they serve and how they make money.**
- Founders and early go-to-market teams. Priced per agent, not per seat: Turbo $24,
  Professional $60, Executive $200 ([pricing](https://day.ai/pricing)).

**Their email bet.** Email is context for an agent's memory of the customer. The assistant
is the main interface.

**Why they made it.**
- Christopher O'Donnell, ex-HubSpot: "We built Day AI for a different user: AI agents. A
  context graph designed for agents to navigate"
  ([Series A](https://www.day.ai/resources/series-a-and-the-beginning-of-the-shift-in-crm)).
- 2024: "You can't just bolt new AI onto Legacy CRM"
  ([seed](https://www.day.ai/resources/day-ai-raises-4m-from-sequoia-capital-to-reimagine-crm-for-the-ai-age)).
- 2026: "Email is where most of your customer relationships actually happen"
  ([timeline](https://www.day.ai/resources/the-agent-origin-story-and-emails-in-timeline)).
- Privacy is Share All or Share None. "Workspace admins cannot see your private emails or
  agent chats" ([privacy](https://www.day.ai/resources/data-privacy-sharing)).

**The cost they accepted.**
- Day's own guide: "Outlook mail doesn't sync in"
  ([guide](https://day.ai/guides/best-ai-crm-for-scale-ups)).
- Day's own guide names the weak spot: "Where it breaks: the second user"
  ([guide](https://day.ai/guides/crm-is-low-resolution)).
- "'Share None' doesn't fully disconnect your agent". In a direct conversation the agent
  can still read your mail ([privacy](https://www.day.ai/resources/data-privacy-sharing)).

**Trajectory 2024 to 2026.**
- $4M seed in 2024, $20M Series A in Feb 2026 led by Sequoia.
- Jul 2026: email history joined the person and company timeline
  ([timeline](https://www.day.ai/resources/the-agent-origin-story-and-emails-in-timeline)).
- Signal: an agent-first CRM still had to add a human view of email.

**Six future-proof tests.**
1. Agents: pass. Agents inherit their person's permissions.
2. No AI key: fail. The product is the agent.
3. Scale 10x: not documented.
4. Providers: fail. Gmail only.
5. Trust: partial. Binary sharing, with a read path inside Share None.
6. Reversibility: partial. Binary settings leave no middle ground to fall back to.

**Lesson for Relaticle: copy the cards, avoid the binary.** Copy cards that state promises
made, provenance and state. Keep our four tiers, since two choices push people to Share
None. Day adding email to the timeline in 2026 says humans still read mail.

---

## 22. Clarify

**Who they serve and how they make money.**
- Founders and early sellers. Unlimited seats on every plan; you pay for AI work in
  credits. Free 1k credits, Starter $40 a month, larger plans from $1,000 a month
  ([pricing](https://www.clarify.ai/pricing)).

**Their email bet.** Email lives on the record, and the AI does the CRM work, priced by
the work.

**Why they made it.**
- The founders "rebuilt it from first principles". The CRM should be "Autonomous", one that
  "Works for you instead of demanding constant feeding"
  ([funding](https://www.clarify.ai/blog/funding-and-ga)).
- The Inbox Reply Drafter acts "only on mail addressed to you personally". It skips cc mail,
  shared aliases and "threads a teammate has already handled ... so it won't step on
  anyone else's inbox" ([template](https://www.clarify.ai/templates/inbox-reply-drafter)).
- The workspace default is Participants only, with request access and a note
  ([sharing](https://docs.clarify.ai/en/articles/16774485-sharing-and-permissions)).
- Inferred: credit pricing removes the seat objection that slows team adoption.

**The cost they accepted.**
- G2 mines "Missing Features" and "Integration Issues"
  ([G2](https://www.g2.com/products/clarify-crm/reviews)).
- A reviewer finds the credit model "not transparent enough"
  ([review](https://salesdorado.com/en/crm/crm-software/review-clarify-ai)).
- Its docs say drafts cannot be saved or shared for review before sending
  ([benchmark-modern](benchmark-modern.md)).

**Trajectory 2024 to 2026.**
- Jun 2025: $22.5M raised and general availability.
- Oct 2026: email `rep@clarify.ai` or text it; multiple inboxes; teammates can send from
  your account; HubSpot two-way sync
  ([changelog](https://www.clarify.ai/changelog/october-2026)).
- Signal: the AI CRM moves into the user's inbox as a correspondent.

**Six future-proof tests.**
1. Agents: pass. MCP, per a reviewer ([G2](https://www.g2.com/products/clarify-crm/reviews?qs=pros-and-cons)).
2. No AI key: partial. Records work, but the value is the AI.
3. Scale 10x: not documented.
4. Providers: partial. Gmail and Microsoft.
5. Trust: pass. Participants-only default, request with a note, separate send delegation.
6. Reversibility: pass.

**Lesson for Relaticle: copy.** Never draft on a thread a teammate already answered. Add a
note to access requests. Keep reading and sending as separate delegations.

---

## 23. Lightfield

**Who they serve and how they make money.**
- Founders and fast-growing companies. Startup $79, Pro $199, Growth custom
  ([pricing](https://lightfield.app/pricing)).

**Their email bet.** No inbox, by design. Email lives on account, contact and opportunity
pages, and an agent does the work.

**Why they made it.**
- Keith Peiris, on leaving Tome: "LLMs are good at starting work, but lack the context
  required to finish it" ([X](https://x.com/keithpeiris/status/1902817105264906655)).
- "The CRM, categorically, is perhaps the most complex and lowest satisfaction piece of
  software on Earth" ([VentureBeat](https://venturebeat.com/technology/tomes-founders-ditch-viral-presentation-app-with-20m-users-to-build-ai)).
- "Lightfield was built from day one for agents. We reimagined the CRM as a business world
  model" ([Series A](https://lightfield.app/blog/our-series-a)).
- In Selective mode Lightfield "only logs emails and creates records for interactions
  you've responded to" ([capture](https://support.lightfield.app/articles/3050423158-understanding-how-lightfield-captures-data)).
- The help center sends email questions to record pages
  ([Up next](https://docs.lightfield.app/help/up-next)).

**The cost they accepted.**
- When mailboxes share an item, the most permissive setting wins ([benchmark-modern](benchmark-modern.md)).
- No list means no triage view; inferred from the Up next answer.
- The price is high for founders at $79 a seat.

**Trajectory 2024 to 2026.**
- Mar 2025 pivot from Tome; Nov 2025 public launch; May 2026 agentic pipeline generation
  ([blog](https://lightfield.app/blog/building-the-crm-that-works-for-you)).
- Sep 2026: $47M Series A led by a16z, over 5,000 companies signed up
  ([Series A](https://lightfield.app/blog/our-series-a)).
- Its API reads are "privacy-aware and may be redacted based on the caller"
  ([API](https://docs.lightfield.app/api)).

**Six future-proof tests.**
1. Agents: pass. Privacy-aware redaction per caller.
2. No AI key: fail. The agent is the product.
3. Scale 10x: not documented.
4. Providers: partial. Multiple accounts; providers not listed in these sources.
5. Trust: partial. Most-permissive-wins is hard to defend.
6. Reversibility: pass. "See excluded activity" lets you sync items back in.

**Lesson for Relaticle: copy two rules, avoid one.** Default to linking only threads someone
replied to, with an "excluded" view to recover the rest. Redact MCP reads per caller with
the same tiers as the UI. Never let the most permissive mailbox win.

---

## Strategic map

**Axes.**
- Where email lives:
  - Record-first: email is shown on records.
  - Inbox-first: a thread list is the main surface.
  - Agent-first: an assistant reads mail and you approve its output.
- Who it serves: individual or team.
- The arrow shows the direction of 2025 and 2026 moves, from the sources above.

| Product | Where email lives | Who it serves | Moving toward |
|---|---|---|---|
| Attio | Record-first | Team | Agent (MCP, Ask Attio) |
| HubSpot | Record-first, plus a separate service inbox | Team | Agent (MCP, prospecting agent) |
| Salesforce EAC | Record-first | Team | Agent (Agentforce on stored email) |
| Folk | Record-first | Team (small) | Agent assistants |
| Twenty | Record-first | Team | Agent (MCP, AI agents) |
| Clarify | Record-first | Team | Agent (email it, text it) |
| Lightfield | Record-first, no inbox by design | Team | Agent |
| Day.ai | Agent-first, timeline added 2026 | Team, weak with a second user | Record (timeline) |
| Pipedrive | Inbox-first mirror inside the CRM | Team | Agent (MCP, Nova) |
| Close | Inbox-first action queue | Team | Agent (Chloe) |
| Streak | Inbox-first, inside Gmail | Team (small) | Agent (MCP, Claude) |
| Front | Inbox-first shared queue | Team | Agent teammates |
| Missive | Inbox-first shared queue | Team (small) | Agent triage against a CRM |
| Superhuman | Inbox-first | Individual | Agent (Email Assistant inside Gmail) |
| Gmail | Inbox-first | Individual | Agent (AI Inbox) |
| Outlook | Inbox-first | Individual, enterprise-managed | Agent (Copilot runs the inbox) |
| HEY | Inbox-first | Individual | Stays (HI not AI) |
| Spark | Inbox-first | Individual | Agent (CLI with send permission) |
| Shortwave | Inbox-first | Individual | Agent (Tasklet) |
| Notion Mail | Inbox-first, shut down | Individual | Agent (Notion agents) |
| Zero | Inbox-first, pivoted | Individual | Agent (Orchid in iMessage) |
| Inbox Zero | Agent-first, writes Gmail labels | Individual | Agent |
| Cora | Agent-first, Brief digest | Individual | Agent |

**The grid.**

| | Individual | Team |
|---|---|---|
| **Record-first** | (none) | Attio, HubSpot, Salesforce, Folk, Twenty, Clarify, Lightfield |
| **Inbox-first** | Superhuman, Gmail, Outlook, HEY, Spark, Shortwave, Notion Mail, Zero | Pipedrive, Close, Streak, Front, Missive |
| **Agent-first** | Inbox Zero, Cora | Day.ai (weak with a second user) |

**The empty quadrant Relaticle can own.** Nobody offers a team thread list that is scoped
to CRM records, respects per-teammate privacy tiers, and exposes the same states to agents
under the viewer's permissions. Self-hosted, with no AI key required.
- The record-first CRMs have the tiers but no list.
- The inbox-first team tools have the list but no tiers or records.
- The agent-first tools have neither for a second user.

---

## Five market shifts that shape our decisions

**1. Standalone inboxes are giving way to agents.**
- Notion Mail shut down because "more than half of Notion Mail users manage emails without
  ever opening their inbox" ([TechCrunch](https://techcrunch.com/2026/06/25/notion-mail-shuts-down-amid-agent-takeover)).
- Zero became an iMessage assistant ([Orchid](https://orchid.ai/blog/orchid-ai-assistant-imessage)).
  Shortwave spun its agent into Tasklet ([Tasklet](https://www.shortwave.com/blog/introducing-tasklet-ai-automation)).
- Superhuman now sells its assistant inside Gmail and Outlook
  ([help](https://help.superhuman.com/hc/en-us/articles/46005854346893-Email-Assistant-by-Superhuman-Mail-Gmail)).
- Decision impact: every Conversations state (Needs reply, Awaiting reply, Unlinked) must
  also be a Rela tool and an MCP read.

**2. The mailbox hosts are making generic triage free.**
- Gmail's AI Inbox shows "Suggested to-dos" to paid Workspace users
  ([update](https://workspaceupdates.googleblog.com/2026/10/gmails-ai-inbox-is-rolling-out-to-paid-Workspace-users.html)).
- Outlook's Copilot now "triages emails ... and surfaces what matters most"
  ([blog](https://techcommunity.microsoft.com/blog/outlook/copilot-in-outlook-new-agentic-experiences-for-email-and-calendar/4514601)).
- Decision impact: our list must never be a generic inbox. Every row carries record
  context, and its scope is tracked people and companies.

**3. Agents became a second user, through MCP, under the user's permissions.**
- In 2026 alone: HubSpot MCP GA in April
  ([changelog](https://developers.hubspot.com/changelog/remote-hubspot-mcp-server-is-now-generally-available)),
  Pipedrive in June ([press](https://www.pipedrive.com/en/newsroom/pipedrive-launches-native-mcp-server-bringing-crm-workflows-directly-into-ai-assistants)),
  Streak in August ([updates](https://www.streak.com/updates)).
- Attio's MCP searches email semantically ([docs](https://docs.attio.com/mcp/overview)).
- Front gives external agents identity and assigned conversations
  ([update](https://community.front.com/product-updates/put-ai-agents-to-work-with-your-team-for-seamless-collaboration-4098)).
- Lightfield redacts API reads per caller ([API](https://docs.lightfield.app/api)).
- Decision impact: one owner for "who may see this email". The UI, Rela and MCP all read
  it. This is test 1.

**4. Autonomy is approval-first and graduated.**
- Cora "Can't send emails automatically"
  ([help](https://help.cora.computer/en/articles/13225520-getting-started-with-cora)).
- Clarify leaves every reply as a draft ([template](https://www.clarify.ai/templates/inbox-reply-drafter)).
- Orchid's drafts "stay drafts until you say send"
  ([Orchid](https://orchid.ai/blog/orchid-ai-assistant-imessage)).
- Spark lets an agent send only within limits "Spark enforces"
  ([agents](https://sparkmailapp.com/blog/spark-agents-that-follow-through)).
- Attio's MCP asks before every write ([help](https://attio.com/help/reference/how-to-guides/attio-mcp-prompts-and-best-practices)).
- Decision impact: Rela's proposal and approve pipeline is the market shape. Add read,
  triage and send as separate grants, refused server-side.

**5. Email is becoming first-class record data, priced by work rather than seats.**
- Salesforce moved captured email into standard records to unlock "Agentforce integration"
  ([retirement](https://help.salesforce.com/s/articleView?id=005385951&language=en_US&type=1)).
- Pricing moved to credits and agents: Clarify sells credits with unlimited seats
  ([pricing](https://www.clarify.ai/pricing)), and Day.ai charges per agent
  ([pricing](https://day.ai/pricing)).
- Close includes AI credits per user ([pricing](https://www.close.com/pricing)).
- Missive lets customers bring their own key ([changelog](https://missiveapp.com/changelog)).
- Decision impact: keep email in Postgres, queryable. Make every AI step optional and
  BYOK-friendly for self-hosters. This is test 2.

---

## What would make our direction wrong

1. **Nobody opens the list.**
   - Notion saw half its users stop opening the inbox.
   - If weekly openers of Conversations stay flat while Rela email requests grow, the page
     is the wrong surface. Invest in tools and digests instead.
   - Watch: distinct weekly users of the page against Rela email tool calls.
2. **The hosts add CRM context.**
   - Gmail and Outlook already ask your inbox about "the Acme contract"
     ([Workspace blog](https://workspace.google.com/blog/product-announcements/more-personalized-and-proactive-assistance-in-gmail-coming-to-business-customers)).
   - If Gemini or Copilot start reading CRM records through connectors, a CRM list loses its edge.
   - Watch: Gemini and Copilot connector announcements for CRMs, including our MCP server.
3. **Teammates never share.**
   - The team thesis rests on tiers and access requests. Today: 95.4% metadata only and
     0 requests ([README](README.md)).
   - If requests stay near zero after the one-click flow ships, sales email in small teams
     is personal. The list should then default to "my mail".
   - Watch: access requests and shares per month after phase 4.
4. **A record-first leader ships a conversation list.**
   - If Attio, HubSpot or Twenty add a cross-record thread list with tiers, the empty
     quadrant closes. Our edge narrows to open source and self-hosting.
   - Watch: their changelogs ([Attio](https://attio.com/changelog), [Twenty](https://twenty.com/releases)).
5. **Provider rules tighten.**
   - Google requires a yearly assessment for restricted scopes
     ([Google](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification)).
   - Microsoft is retiring EWS on its own schedule
     ([Microsoft](https://techcommunity.microsoft.com/blog/exchange/exchange-online-ews-your-time-is-almost-up/4492361)).
   - If full-mailbox read access gets costlier or narrower, a list of all mail becomes
     expensive. Selective sync of replied threads, as Lightfield does, becomes the default.
   - Watch: Google API policy notices and Graph throttling changes.
