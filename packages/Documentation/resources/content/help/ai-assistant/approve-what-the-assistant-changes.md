---
title: Approve what the assistant changes
description: Every create, update, or delete the AI proposes waits for your review. Nothing is saved until you approve it.
order: 2
updated: "2026-10-07"
related: [help/ai-assistant/ask-questions-about-your-data, help/getting-started/use-custom-fields, help/email-and-calendar/use-your-email-with-an-ai-assistant]
---

When you ask the assistant to change something ("create a follow-up task for
Acme", "move this deal to Negotiation", "delete these three notes"), it never
writes to your CRM directly. It drafts the change as a proposal card above
the message box, and waits.

![A proposal card headed "Review before continuing", listing a task's title, linked opportunity, assignee, status, due date, and priority, with Discard and Create buttons](/help-assets/ai-assistant/approve-what-the-assistant-changes-1.png)

## The proposal card

The card lists every field the assistant wants to set, old value → new value
for updates. From there you can:

- **Approve**. The primary button is named for the action: **Create**,
  **Save changes**, or **Delete** (shown in red). `⌘↵` approves from the
  keyboard.
- **Edit a field first**. Click the pencil next to any field, correct the
  value, then **Save**. Approve when the card looks right.
- **Discard**. Nothing happens, and you can just keep chatting.

The assistant can set any field you could set on the record's own form,
including your workspace's custom fields, and it appears on the card for
review.

## Batches

One request can propose up to 25 records. "Add these five people from my
meeting notes" arrives as a single card you step through with **Previous
record** / **Next record**. Each item is approved or skipped on its own; a
resolved item shows a **Created** or **Skipped** chip and a link to the new
record. Deletes work the same way: you step through the records and delete
or skip each one.

A request that needs several linked changes, such as a new company with a
person and a task attached, arrives as one plan of up to 6 steps. You review
every step and approve the plan once.

## Email

An email is approved one at a time. Its card shows every recipient and the
whole message, and its button reads **Send** or **Save draft**. In a plan,
**Approve all** never sends an email. Each one waits for its own **Send**.
See [Use your email with an AI assistant](/help/email-and-calendar/use-your-email-with-an-ai-assistant).

## Proposals don't wait forever

- Sending another message while a proposal is open discards it. The assistant
  treats your new message as the current instruction.
- An untouched proposal expires after 24 hours and its card shows
  **Expired**. Ask again to get a fresh card.
- You can't regenerate an answer while its proposal is pending. Resolve the
  card first.

## Who can propose what

The assistant acts with your permissions. Creating and editing custom field
definitions from chat is owner-and-Admin territory, exactly like the
**Custom Fields** page itself. For everyone else the assistant explains and
links there instead.
