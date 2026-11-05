---
title: Research a company with Rela
description: Let Rela search the web for a company or person that is not in your workspace yet, and choose when it may do so.
order: 6
updated: "2026-11-04"
related: [help/ai-assistant/approve-what-the-assistant-changes, help/ai-assistant/ai-credits-and-limits, help/ai-assistant/choose-an-ai-model]
---

Rela, the built-in AI assistant, answers from your workspace first. When a
question needs something your records do not hold, such as what a company
sells or who founded it, Rela can search the web and read the pages it finds.

## How a research request works

Ask in plain words, for example "Research Filaforms and tell me if they could
be a fit for us". When Rela needs the web, it asks before it searches. A card
above the message box shows what it wants to search for and why, with three
buttons:

- **Allow** lets Rela search the web for this request only. Your next message asks again.
- **Always allow** does the same and stops asking from then on.
- **Deny** keeps Rela on your workspace data. It answers from your records and says it did not use the web.

While Rela works, the chat shows what it is searching for and which page it
is reading. The reply ends with a **Sources** row: one link for each
page Rela cited, which opens in a new tab.

Rela never writes what it found into your records on its own. When it
suggests adding a note or filling in a field, you approve that change the
same way as any other. See
[Approve what the assistant changes](/help/ai-assistant/approve-what-the-assistant-changes).

## Choose when Rela may search

Open **Settings** from your avatar menu, then **Web search**, and pick one:

| Choice | What happens |
|--------|--------------|
| Always ask | Rela asks before each research request. This is the default. |
| Always allow | Rela searches without asking. |
| Don't allow | Rela never searches the web and answers from your workspace only. |

The choice is yours alone. Teammates pick their own.

## Turn web search off for the whole workspace

The workspace owner can turn web search off for every member on **Workspace
Settings** (click your workspace name in the sidebar), under **Web search**. While it is off, Rela never asks to search,
and each member's setting shows that the owner turned it off.

## What leaves your workspace

Rela writes the search queries itself, and they go to the AI provider's web
search: Anthropic for Claude models, OpenAI for GPT models. A query can name a
company or person from your request. The pages Rela reads are fetched by the
same provider, which already handles every message you send Rela.

Pages on the web are not instructions. Rela treats what it reads as
information, and any change it suggests still waits for your approval.

## What a search costs

On Relaticle Cloud, each web search uses 2 credits on top of the message's
normal cost. Reading a page counts like any other tool. See
[AI credits and rate limits](/help/ai-assistant/ai-credits-and-limits).

## Which models can search

Only Claude and GPT models can search the web. Rela never asks to search while
another model is selected, such as Gemini or a self-hosted model (Ollama or an
OpenAI-compatible endpoint). Pick a Claude or GPT model in the model picker to
research a company. See [Choose an AI model](/help/ai-assistant/choose-an-ai-model).
