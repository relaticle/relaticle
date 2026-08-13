---
title: Approve what the assistant changes
description: Every create, update, or delete the AI proposes waits for your review — nothing is saved until you approve it.
order: 2
updated: "2026-08-13"
related: [help/ai-assistant/ask-questions-about-your-data, help/getting-started/use-custom-fields]
---

When you ask the assistant to change something — "create a follow-up task for
Acme", "move this deal to Negotiation", "delete these three notes" — it never
writes to your CRM directly. It drafts the change as a proposal card, shown
above the message box under **Review before continuing**, and waits.

![A proposal card headed "Review before continuing", listing a task's title, linked opportunity, assignee, status, due date, and priority, with Discard and Create buttons](/help-assets/ai-assistant/approve-what-the-assistant-changes-1.png)

## The proposal card

The card lists every field the assistant wants to set, old value → new value
for updates. From there you can:

- **Approve** — the primary button is named for the action: **Create**,
  **Save changes**, or **Delete** (shown in red). `⌘↵` approves from the
  keyboard.
- **Edit a field first** — click the pencil next to any field, correct the
  value, then **Save**. Approve when the card looks right.
- **Discard** — nothing happens, and you can just keep chatting.

Any field you could set on the record's own form — including your workspace's
custom fields — the assistant can set too, and it appears on the card for
review.

## Batches

One request can propose up to 25 records — "add these five people from my
meeting notes" arrives as a single card you step through with **Previous
record** / **Next record**. Each item is approved or skipped on its own; a
resolved item shows a **Created** or **Skipped** chip and a link to the new
record. Deletes work the same way: one card, every record listed, all-or-
nothing per approval.

## Proposals don't wait forever

- Sending another message while a proposal is open discards it — the assistant
  treats your new message as the current instruction.
- An untouched proposal expires after 15 minutes and shows
  **This action has expired**. Ask again to get a fresh card.
- You can't regenerate an answer while its proposal is pending — resolve the
  card first.

## Who can propose what

The assistant acts with your permissions. Creating and editing custom field
definitions from chat is workspace-owner territory, exactly like the
**Custom Fields** page itself — for other members the assistant explains and
links there instead.
