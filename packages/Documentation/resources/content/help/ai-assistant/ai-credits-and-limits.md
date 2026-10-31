---
title: AI credits and rate limits
description: How assistant messages spend credits on Relaticle Cloud, what each plan includes, and what happens at zero. Self-hosted installs have no credit limit.
order: 4
updated: "2026-10-30"
related: [help/ai-assistant/choose-an-ai-model, help/workspace/billing-and-plans]
---

On Relaticle Cloud, every message you send the assistant costs credits from
your workspace's monthly allowance. A plain reply costs one credit, and each
tool the assistant uses to answer adds to it. Credits are shared by the whole
workspace, not per person.

## Self-hosted installs

A self-hosted install has no credit limit. The assistant runs on your own
provider key or local model, and you pay that provider directly. Only the
per-minute send limit applies, as described under Rate limits below.

## What a message costs

On Relaticle Cloud, the cost scales with the model's multiplier (see
[Choose an AI model](/help/ai-assistant/choose-an-ai-model)) plus a little
for each tool the assistant uses while answering. Searching records, reading
a company, and drafting a proposal each count. A simple question on the default
model costs 1 credit; a multi-step request on a 3x model costs more. The
final cost settles after the answer completes.

## What each plan includes

| Plan | Credits per month | Messages per minute |
|------|-------------------|---------------------|
| Cloud Pro and its trial | 2,000 | 30 |
| Cloud Concierge | 5,000 | 30 |
| Enterprise | Agreed with your team | Agreed with your team |
| Self-hosted | Not metered | 60, set by `CHAT_RATE_LIMIT_PER_MINUTE` (0 for no limit) |

Relaticle Cloud has no free plan; what each plan costs is in
[Billing and plans](/help/workspace/billing-and-plans).

Cloud allowances reset with your billing period. Pro workspaces can also buy
credit packs. Purchased credits are only drawn on after the monthly allowance
and **never expire**. Your remaining balance for the period is shown on the
**Billing** page, under your workspace name.

## When credits run out

On Relaticle Cloud, the chat shows **You've used all your AI credits** with
your reset date, and new messages wait until then. Nothing else in Relaticle is affected. Pro
workspaces get an **Add credits** button on the same notice. The rest of the
CRM never spends credits; only assistant messages do.

## Rate limits

The per-minute message limit is shared across the workspace. If your team
sends faster, the chat shows **Sending too fast** with a countdown and
sends your message automatically when the window opens, so nothing is lost.
A single message can be up to 5,000 characters.

On a self-hosted install the limit defaults to 60 messages a minute. The
operator sets it with `CHAT_RATE_LIMIT_PER_MINUTE`, and 0 removes it.
