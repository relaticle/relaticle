---
title: Approve what the assistant changes
description: Every create, update, or delete the AI proposes waits for your review. Nothing is saved until you approve it.
order: 2
updated: "2026-11-06"
related: [help/ai-assistant/ask-questions-about-your-data, help/getting-started/use-custom-fields, help/email-and-calendar/use-your-email-with-an-ai-assistant]
---

When you ask the assistant to change something ("create a follow-up task for
Acme", "move this deal to Negotiation", "delete these three notes"), it never
writes to your CRM directly. It drafts the change as a proposal card where
the message box was, and waits. Approve or discard it to keep chatting.

![A proposal card for "Create task Send updated proposal to Figma", listing its title, linked company and opportunity, status, due date and priority, with Discard and Approve buttons above the line "Approve or discard the proposal to keep chatting"](/help-assets/ai-assistant/approve-what-the-assistant-changes-1.png)

## The proposal card

The card lists every change as one line, with every value it sets. An update
shows the old value struck through next to the new one. From there you can:

- **Approve**. The button counts what it writes: **Approve**, or
  **Approve 3 changes** for several. A delete reads **Delete 2 tasks** in red.
  `⌘↵` approves from the keyboard, except for deletes, emails and invitations,
  which always take a click.
- **Edit a field first**. Click the pencil next to a value, correct it, then
  **Save**. Approve when the card looks right.
- **Leave something out**. Hover a field, a line or a record and click its
  **×**. It stays on the card, struck through, with **Keep** to bring it back.
  Nothing is decided until you press **Approve**.
- **Discard**. Nothing is written.

The assistant can set any field you could set on the record's own form,
including your workspace's custom fields, and every one appears on the card
for review.

## Batches and plans

One request can propose up to 25 records. "Add these five people from my
meeting notes" arrives as one card that lists all five. Leave out the ones
you don't want and approve the rest. Deletes work the same way.

A request that needs several linked changes, such as a new company with a
person and a task attached, arrives as one plan of up to 6 changes. A record
the plan creates and another change links to is marked **new**. Leave out
that record and the changes that link to it go with it; the card tells you
which, with **Keep** to bring them back. You approve the plan once.

## After you decide

The card leaves and the conversation keeps a receipt: **Applied 3 changes**
with one line per record, each linking to it. Anything you left out shows
struck through. A discarded proposal leaves one grey line with
**Propose again**.

## Email

An email is approved one at a time. Its card shows every recipient and the
whole message, and its button reads **Send email**. A plan lists the email
as "Then email …" and never sends it with the rest: once you approve the plan,
the email gets the card to itself and waits for its own **Send email**.
See [Use your email with an AI assistant](/help/email-and-calendar/use-your-email-with-an-ai-assistant).

## Invitations

Inviting someone to your workspace emails them, so an invitation keeps its own
button too: **Send invitation**. Approving a plan never sends it.

## Proposals don't wait forever

- While a proposal waits, the message box is locked. Approve or discard it to
  keep chatting.
- An untouched proposal expires after 24 hours and its line reads
  **expired**, with **Propose again** to get a fresh card.
- You can't regenerate an answer while its proposal is pending. Resolve the
  card first.

## Who can propose what

The assistant acts with your permissions. Creating and editing custom field
definitions from chat is owner-and-Admin territory, exactly like the
**Custom Fields** page itself. For everyone else the assistant explains and
links there instead.
