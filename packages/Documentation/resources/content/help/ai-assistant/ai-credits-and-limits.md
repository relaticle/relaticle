---
title: AI credits and rate limits
description: How assistant messages spend credits, what each plan includes monthly, and what happens at zero.
order: 4
updated: "2026-08-13"
related: [help/ai-assistant/choose-an-ai-model, help/workspace/billing-and-plans]
---

Every message you send the assistant costs credits from your workspace's
monthly allowance — most messages cost a single credit, heavier ones a few
more. Credits are shared by the whole workspace, not per person.

## What a message costs

The cost scales with the model's multiplier (see
[Choose an AI model](/help/ai-assistant/choose-an-ai-model)) plus a little
for each tool the assistant uses while answering — searching records, reading
a company, drafting a proposal each count. A simple question on the default
model costs 1 credit; a multi-step request on a 3x model costs more. The
final cost settles after the answer completes.

## What each plan includes

| Plan | Credits per month | Messages per minute |
|------|-------------------|---------------------|
| Free | 300 | 10 |
| Pro | 2,000 | 30 |

Allowances reset with your billing period. Pro workspaces can also buy credit
packs — purchased credits are only drawn on after the monthly allowance and
**never expire**. Your remaining balance for the period is shown on the
**Billing** page, under your workspace name.

## When credits run out

The chat shows **You've used all your AI credits** with your reset date, and
new messages wait until then — nothing else in Relaticle is affected. Pro
workspaces get an **Add credits** button on the same notice. The rest of the
CRM never spends credits; only assistant messages do.

## Rate limits

The per-minute message limit is shared across the workspace. If your team
sends faster, the chat shows **You're sending fast** with a countdown and
sends your message automatically when the window opens — nothing is lost.
A single message can be up to 5,000 characters.
