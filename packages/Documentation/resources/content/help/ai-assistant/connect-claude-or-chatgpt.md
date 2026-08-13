---
title: Connect Claude or ChatGPT to your workspace
description: Add Relaticle to the AI assistant you already use — one URL, a consent screen, and no code involved.
order: 5
updated: "2026-08-13"
related: [docs/guides/mcp, help/ai-assistant/ask-questions-about-your-data, help/ai-assistant/ai-credits-and-limits]
---

The assistants you already use — Claude and ChatGPT — can work with your
Relaticle data directly. You add Relaticle as a **connector**, approve access
once, and from then on you can ask your assistant about your pipeline, or
have it create and update records, from its own chat.

No code, no API keys: the whole setup is a consent screen.

## Connect it

1. In your assistant's connector or integration settings, add a custom
   connector with the URL `https://mcp.relaticle.com`. (In Claude that's
   **Settings → Connectors**; in ChatGPT it's part of custom connectors.)
2. The assistant sends you to Relaticle to sign in, if you aren't already.
3. Approve access and **pick the workspace** the connector may use.
4. That's it — ask your assistant something: "what's in my pipeline this
   month?"

The connector acts as **you**, inside **that one workspace**: it can do what
you can do there, and nothing else. To point an assistant at a different
workspace, revoke the connector and connect again.

## What your assistant can do

Search and read companies, people, opportunities, tasks, and notes; create,
update, and delete them; attach tasks and notes to records; and read your
workspace's custom fields so its answers use your real schema.

One thing to know: changes made through a connector are applied directly —
the review card you get in Relaticle's own built-in chat doesn't exist here,
so rely on your assistant's own confirmation prompts before it acts.
Connector activity never spends your workspace's
[AI credits](/help/ai-assistant/ai-credits-and-limits) — those are only for
the built-in assistant.

## See and revoke connections

Open **Settings → Access Tokens** from your avatar menu. The
**AI Connectors** section lists every assistant connected to your
workspaces, which workspace each is bound to, and how many active tokens it
holds. **Revoke** cuts an assistant's access immediately — you can always
connect it again later.

![The AI Connectors list showing Claude and ChatGPT bound to a workspace, with active token counts and a revoke button per row](/help-assets/ai-assistant/connect-claude-or-chatgpt-1.png)

## Good to know

- Works with an active Relaticle Cloud workspace — a paused workspace can't
  be selected on the consent screen until it's subscribed again.
- Claude and ChatGPT are the assistants with a built-in connector flow
  today, but the server speaks the open MCP standard — any other
  MCP-capable tool can use the same URL with a personal access token, the
  way developers connect Cursor (see the
  [MCP Server guide](/developers/mcp)).
- Self-hosted installs include the same connector server on your own domain
  — the [MCP Server guide](/developers/mcp) covers the setup.
- Access renews itself in the background; you only see the consent screen
  again if you revoke and reconnect.
