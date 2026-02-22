# MCP Server

MCP (Model Context Protocol) lets AI assistants like Claude work directly with your Relaticle CRM data. Instead of copy-pasting between tools, your AI assistant can list companies, create tasks, update contacts, and more -- all from a natural conversation.

---

## What You Can Do

With the Relaticle MCP server, your AI assistant can:

- **List and search** companies, people, opportunities, tasks, and notes
- **Create new records** directly from a conversation
- **Update existing records** -- rename a company, reassign a task
- **Delete records** you no longer need
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

The server provides 20 tools across five CRM entities:

### Companies

| Tool | Description |
|------|-------------|
| `list_companies` | List companies with optional search by name and pagination |
| `create_company` | Create a new company (requires `name`) |
| `update_company` | Update a company by ID |
| `delete_company` | Soft-delete a company by ID |

### People

| Tool | Description |
|------|-------------|
| `list_people` | List contacts with optional search, filter by company |
| `create_people` | Create a new contact (requires `name`, optional `company_id`) |
| `update_people` | Update a contact by ID |
| `delete_people` | Soft-delete a contact by ID |

### Opportunities

| Tool | Description |
|------|-------------|
| `list_opportunities` | List deals with optional search, filter by company |
| `create_opportunity` | Create a new deal (requires `name`, optional `company_id`, `contact_id`) |
| `update_opportunity` | Update a deal by ID |
| `delete_opportunity` | Soft-delete a deal by ID |

### Tasks

| Tool | Description |
|------|-------------|
| `list_tasks` | List tasks with optional search by title |
| `create_task` | Create a new task (requires `title`) |
| `update_task` | Update a task by ID |
| `delete_task` | Soft-delete a task by ID |

### Notes

| Tool | Description |
|------|-------------|
| `list_notes` | List notes with optional search by title |
| `create_note` | Create a new note (requires `title`) |
| `update_note` | Update a note by ID |
| `delete_note` | Soft-delete a note by ID |

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
