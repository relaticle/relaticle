---
title: Billing and plans
description: Relaticle Cloud is priced per workspace, never per seat. Trials, subscriptions, credits, and pausing explained.
order: 3
updated: "2026-11-08"
related: [help/ai-assistant/ai-credits-and-limits, help/workspace/rename-or-delete-your-workspace]
---

Relaticle Cloud is billed **per workspace, never per seat**, so you can invite
your whole team without the price moving. The **Billing** page lives under your
workspace name in the sidebar; subscription actions there belong to the
workspace owner, and other members see who to ask.

## What Cloud Pro costs

Cloud Pro is the entry plan: unlimited users and records, 2,000 AI
credits a month, premium AI models, up to 10 connected mailboxes, the REST
API and MCP server, and email support. It costs **$19/mo per workspace billed
yearly** ($228 a year), or **$24/mo billed monthly**. For larger rollouts,
Enterprise implementation starts at $20,000 a year. The
[pricing page](/pricing) has the details.

## What Cloud Scale costs

Cloud Scale is for teams that run on Relaticle all day. It includes everything
in Cloud Pro, raises the allowance to 5,000 AI credits a month, allows up to
50 connected mailboxes, triples REST API and MCP throughput, and comes with
priority support. It costs **$49/mo per workspace billed yearly** ($588 a
year), or **$59/mo billed monthly**. Switch between Pro and Scale from the
Billing page; Stripe prorates the change.

## The trial

Every new Cloud workspace starts a **14-day Pro trial automatically, with no
card**. The trial includes everything in Cloud Pro, with 2,000 AI credits and
one connected mailbox. Premium AI models unlock once the workspace holds a
record of its own.
When it ends, subscribe from the Billing page to keep Cloud access; without
a subscription the workspace pauses. If you need more time, the owner can
extend the trial by 7 days once from the Billing page. AI credits do not
refill during the extension. Relaticle Cloud has no free plan.

We keep a paused trial workspace for 60 days after the last sign-in. If no one
signs in or chooses a plan in that time, we delete it and its records. We email
the owner twice first.

## Managing the subscription

**Manage subscription** opens the billing portal, where you update the
payment method, download invoices, and switch between monthly and yearly.
Cancelling keeps your plan until the end of the paid period. On Enterprise,
the portal is where you pay invoices and update the payment method. Plan
changes go through us.

## If the subscription lapses

The workspace **pauses**. The app, API, MCP server, and Rela step
aside, but **your records are safe** and remain stored. Resubscribing
restores everything exactly as it was. A paused workspace can still be
deleted by its owner, and its 30-day deletion grace period applies as usual.

## AI credits

The plan's monthly credit allowance resets each billing period. Paid
workspaces can buy one-time **credit packs** from the Billing page.
Purchased credits are spent only after the monthly allowance and never
expire. The same page shows how many credits the workspace has used this
period. What credits are and what messages cost is covered in
[AI credits and rate limits](/help/ai-assistant/ai-credits-and-limits).

## Self-hosting instead

Relaticle is open source (AGPL-3.0). Self-hosted installs are free forever,
with no subscription and no workspace pausing, and AI runs on your own
provider keys. The assistant does not meter credits there. Only a per-minute
send limit applies, which defaults to 60 and which the operator can change.
See the [Self-Hosting Guide](/developers/self-hosting).
