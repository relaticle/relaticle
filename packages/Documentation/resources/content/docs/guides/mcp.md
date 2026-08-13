---
title: MCP Server
description: Connect AI assistants like Claude to your CRM.
order: 2
updated: "2026-08-12"
---

MCP (Model Context Protocol) lets AI assistants like Claude work directly with your Relaticle CRM data. Instead of copy-pasting between tools, your AI assistant can list companies, create tasks, update contacts, and more -- all from a natural conversation.

---

## What You Can Do

With the Relaticle MCP server, your AI assistant can:

- **List and search** companies, people, opportunities, tasks, and notes
- **Get a single record** with full details and relationships
- **Create new records** directly from a conversation
- **Update existing records** -- rename a company, reassign a task
- **Delete records** you no longer need
- **Attach or detach** tasks and notes to companies, people, and opportunities
- **Read entity schemas** to understand your custom fields
- **Get a CRM overview** with record counts and recent activity

---

## Prerequisites

Before connecting an AI assistant, you need an access token:

1. Log in to Relaticle
2. Click your avatar in the top-right corner
3. Select **Access Tokens**
4. Click **Create** and give your token a name
5. Copy the token -- it won't be shown again

The token scopes your access to the team you select when creating it. All MCP operations use that team's data.

---

## Authentication

Relaticle's MCP server supports two authentication methods:

### OAuth 2.1 (recommended for end users)

The MCP endpoint advertises OAuth metadata at:

- `https://mcp.relaticle.com/.well-known/oauth-authorization-server`
- `https://mcp.relaticle.com/.well-known/oauth-protected-resource`

Clients that support Dynamic Client Registration (RFC 7591) — including Claude.ai, Claude Desktop, Claude Code, and ChatGPT custom connectors — register themselves automatically and walk you through a one-click consent flow. PKCE is required (`S256`).

At consent you pick **one workspace** for the connector. That choice is permanent for that connector: to point it at a different workspace, revoke it and connect again. Paused workspaces cannot be selected — subscribe first, or the connector would have no data to read.

Access tokens last 30 days and refresh tokens 90 days; supported clients refresh silently in the background.

### Revoking a connector

**Settings → Access Tokens → AI Connectors** lists every assistant you have connected, the workspace each is bound to, and a **Revoke** button. Revoking invalidates the connector's access and refresh tokens immediately.

### Personal access tokens (recommended for developer tools)

For Cursor, VS Code, MCP Inspector, or any client without OAuth support, create a personal access token from your account settings and pass it as `Authorization: Bearer YOUR_TOKEN`.

---

## Setup by Client

The MCP server endpoint is `https://mcp.relaticle.com`. Replace `YOUR_TOKEN` in the examples below with the access token you created above.

### Claude Desktop

Add this to your Claude Desktop configuration file (`claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "relaticle": {
      "type": "streamable-http",
      "url": "https://mcp.relaticle.com",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

### Claude Code

Add the server from your terminal:

```bash
claude mcp add relaticle \
  --transport streamable-http \
  https://mcp.relaticle.com \
  --header "Authorization: Bearer YOUR_TOKEN"
```

### Cursor

Add this to your Cursor MCP configuration (`.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "relaticle": {
      "type": "streamable-http",
      "url": "https://mcp.relaticle.com",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

### VS Code (GitHub Copilot)

Add this to your VS Code settings (`.vscode/mcp.json`):

```json
{
  "servers": {
    "relaticle": {
      "type": "streamable-http",
      "url": "https://mcp.relaticle.com",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

---

## Available Tools

The server provides 32 tools: one account tool, two cross-entity discovery tools (search/fetch), full CRUD across five CRM entities, and link management for tasks and notes.

### Cross-entity discovery

| Tool | Description |
|------|-------------|
| `search` | Search across companies, people, opportunities, tasks, and notes. Returns canonical URLs for citation. |
| `fetch` | Fetch a single record by canonical URL. Pair with `search` for ChatGPT Company Knowledge integration. |

### Account

| Tool | Description |
|------|-------------|
| `who-ami-tool` | Get the authenticated user, current team, team members, and token abilities |

### Companies

| Tool | Description |
|------|-------------|
| `list-companies-tool` | List companies with optional search by name and pagination |
| `get-company-tool` | Get a single company by ID with full details and relationships |
| `create-company-tool` | Create a new company (requires `name`) |
| `update-company-tool` | Update a company by ID |
| `delete-company-tool` | Soft-delete a company by ID |

### People

| Tool | Description |
|------|-------------|
| `list-people-tool` | List contacts with optional search, filter by company |
| `get-people-tool` | Get a single person by ID with full details and relationships |
| `create-people-tool` | Create a new contact (requires `name`, optional `company_id`) |
| `update-people-tool` | Update a contact by ID |
| `delete-people-tool` | Soft-delete a contact by ID |

### Opportunities

| Tool | Description |
|------|-------------|
| `list-opportunities-tool` | List deals with optional search, filter by company |
| `get-opportunity-tool` | Get a single opportunity by ID with full details and relationships |
| `create-opportunity-tool` | Create a new deal (requires `name`, optional `company_id`, `contact_id`) |
| `update-opportunity-tool` | Update a deal by ID |
| `delete-opportunity-tool` | Soft-delete a deal by ID |

### Tasks

| Tool | Description |
|------|-------------|
| `list-tasks-tool` | List tasks with optional search by title |
| `get-task-tool` | Get a single task by ID with full details and relationships |
| `create-task-tool` | Create a new task (requires `title`) |
| `update-task-tool` | Update a task by ID |
| `delete-task-tool` | Soft-delete a task by ID |
| `attach-task-to-entities-tool` | Link a task to companies, people, opportunities, or assign users. Adds without removing existing links. |
| `detach-task-from-entities-tool` | Unlink a task from companies, people, opportunities, or unassign users |

### Notes

| Tool | Description |
|------|-------------|
| `list-notes-tool` | List notes with optional search by title |
| `get-note-tool` | Get a single note by ID with full details and relationships |
| `create-note-tool` | Create a new note (requires `title`) |
| `update-note-tool` | Update a note by ID |
| `delete-note-tool` | Soft-delete a note by ID |
| `attach-note-to-entities-tool` | Link a note to companies, people, or opportunities. Adds without removing existing links. |
| `detach-note-from-entities-tool` | Unlink a note from companies, people, or opportunities |

All list tools support `search`, `per_page` (default 15), and `page` parameters. Create and update tools accept `custom_fields` as key-value pairs when your team has custom fields configured.

---

## Schema Resources

The server exposes five schema resources that describe each entity's fields, including any custom fields your team has configured:

| Resource URI | Description |
|---|---|
| `relaticle://schema/company` | Company fields and custom fields |
| `relaticle://schema/people` | People (contact) fields and custom fields |
| `relaticle://schema/opportunity` | Opportunity (deal) fields and custom fields |
| `relaticle://schema/task` | Task fields and custom fields |
| `relaticle://schema/note` | Note fields and custom fields |

AI assistants read these schemas automatically to understand what data they can work with, including your team's custom fields.

---

## CRM Overview Prompt

The server includes a built-in prompt called **CRM Overview** that gives your AI assistant a snapshot of your CRM data -- record counts for each entity and recently created companies and people. This is a great starting point for any conversation.

---

## Example Prompts

Once connected, try these in your AI assistant:

- "List all my companies"
- "Create a new company called Acme Corp"
- "Show me the people at company X"
- "Create a task to follow up with John next week"
- "Give me an overview of my CRM"
- "Update the name of company X to Y"
- "Delete the task with ID abc-123"

---

## Troubleshooting

### "Unauthorized" or 401 Error

Your access token may be expired or invalid. Create a new one from **Settings > Access Tokens**.

### No Data Returned

The MCP server scopes all data to the team associated with your token. Make sure the token was created for the correct team and that the team has data.

### Connection Refused

Verify the MCP URL is correct: `https://mcp.relaticle.com`.

### Custom Fields Not Showing

Custom fields are team-specific. If you don't see them, confirm they're configured for your team in **Settings > Custom Fields**. The AI assistant reads schema resources automatically to discover available fields.

### Rate Limiting

The MCP server uses the same rate limits as the REST API. If you hit limits, wait a moment and retry.
