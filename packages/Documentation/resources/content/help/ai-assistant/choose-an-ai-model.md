---
title: Choose an AI model
description: Pick the model behind the assistant per conversation, or leave it on Auto and let Relaticle decide.
order: 3
updated: "2026-08-13"
related: [help/ai-assistant/ai-credits-and-limits, help/ai-assistant/ask-questions-about-your-data]
---

The model picker sits in the message box — click it to see which AI models
your workspace can use. **Auto** is the default and picks a sensible model for
you; you only need this menu when you want to trade speed against depth
yourself.

![The model picker open above the message box, listing Auto, Claude, and GPT models alongside self-hosted ones](/help-assets/ai-assistant/choose-an-ai-model-1.png)

## What's in the list

Which models appear depends on your plan and, for self-hosted installs, on
what the operator has configured. On Relaticle Cloud:

| Model | Availability | Credit multiplier |
|-------|--------------|-------------------|
| Auto | Everyone | Depends on the model chosen |
| Claude Sonnet | Everyone | 1x |
| Claude Opus | Pro | 3x |
| GPT-5 class models | Pro | 1.5x |

Models marked with an amber **Pro** badge need a Pro workspace. The
multiplier scales what a message costs in credits — see
[AI credits and rate limits](/help/ai-assistant/ai-credits-and-limits).

Self-hosted workspaces can also point the assistant at their own models
(Ollama or any OpenAI-compatible endpoint); those appear in the same picker
once configured.

## How the choice behaves

Your selection is remembered in the browser you made it in and applies to the
messages you send from then on. It's a per-person choice — teammates pick
their own — and switching back to **Auto** at any time is always safe.
