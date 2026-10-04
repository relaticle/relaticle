---
title: MCP Server
description: Read the reference for Relaticle's MCP server, with OAuth and personal access token setup, custom field access and direct writes.
order: 2
updated: "2026-10-07"
---

MCP (Model Context Protocol) lets AI assistants like Claude work directly with your Relaticle CRM data. Instead of copy-pasting between tools, your AI assistant can list companies, create tasks, update people, and more -- all from a natural conversation.

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

Writes made over MCP apply immediately. The approval card belongs to the assistant built into Relaticle, and an MCP client relies on its own confirmation prompts. Email tools exist for a connection that was given email access. Meetings are not exposed. A sent email is the one write that does not apply immediately: it is held first and its user can cancel it.

---

## Connect to Relaticle

Clients with OAuth support need only the MCP endpoint. ChatGPT and Claude open Relaticle's consent screen and let you choose one workspace.

Clients without OAuth support need a personal access token:

1. Log in to Relaticle
2. Click your avatar in the top-right corner
3. Select **Access Tokens**
4. Click **Create** and give your token a name
5. Copy the token -- it won't be shown again

The token scopes your access to the workspace you select when creating it. All MCP operations use that workspace's data.

---

## Authentication

Relaticle's MCP server supports two authentication methods:

### OAuth 2.1 (recommended for end users)

The MCP endpoint advertises OAuth metadata at:

- `https://mcp.relaticle.com/.well-known/oauth-authorization-server`
- `https://mcp.relaticle.com/.well-known/oauth-protected-resource`

Clients that support Dynamic Client Registration (RFC 7591), including Claude.ai, Claude Desktop, Claude Code, and ChatGPT custom connectors, register themselves automatically and walk you through a one-click consent flow. PKCE is required (`S256`).

At consent you pick **one workspace** for the connector. That choice is permanent for that connector: to point it at a different workspace, revoke it and connect again. Paused workspaces cannot be selected. Subscribe first, or the connector would have no data to read.

The consent screen lists what the connector will be able to do, and the list follows your role in the workspace you pick. Where email is on, every role gets reading and drafting, and the Owner, Admin and Member roles get sending. The client cannot ask for more or less. Relaticle shows this screen every time a connector authorizes, so the connector always holds what its user saw.

Access tokens last 30 days and refresh tokens 90 days; supported clients refresh silently in the background.

### Revoking a connector

**Settings → Access Tokens → AI Connectors** lists every assistant you have connected, the workspace each is bound to, and a **Revoke** button. Revoking invalidates the connector's access and refresh tokens immediately.

### Personal access tokens (recommended for developer tools)

For Cursor, VS Code, MCP Inspector, or any client without OAuth support, create a personal access token from your account settings and pass it as `Authorization: Bearer YOUR_TOKEN`.

A token gets email access through its permissions: **Read email**, **Draft email**, and **Send email**. A new token has none of them until you tick one.

---

## Setup by Client

The MCP server endpoint is `https://mcp.relaticle.com`. ChatGPT and Claude use OAuth. The remaining examples use a personal access token.

### ChatGPT

1. Open the [Relaticle plugin](https://chatgpt.com/plugins/plugin_asdk_app_6a92c3af04a0819180ed6652ebe09961) in ChatGPT's plugin directory and click **Install plugin**.
2. Sign in to Relaticle and choose one workspace.
3. Start a message with **@Relaticle**.

### Claude

1. Open **Customize → Connectors**.
2. Select the plus button, then **Add custom connector**.
3. Enter `Relaticle` and `https://mcp.relaticle.com`.
4. Connect, sign in to Relaticle, and choose one workspace.

### Claude Desktop with a personal access token

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

### Claude Code with a personal access token

Add the server from your terminal:

```bash
claude mcp add relaticle \
  --transport streamable-http \
  https://mcp.relaticle.com \
  --header "Authorization: Bearer YOUR_TOKEN"
```

### Cursor with a personal access token

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

### VS Code with a personal access token

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

The server provides 44 tools. They cover account context, cross-entity discovery, workspace analysis, full CRUD across five CRM entities, relationship management, file uploads, reading synced email, saving email drafts, and sending email. A connection without email access sees 39 tools.

### Cross-entity discovery

| Tool | Description |
|------|-------------|
| `search` | Search across companies, people, opportunities, tasks, and notes. Returns canonical URLs for citation. |
| `fetch` | Fetch a single record by canonical URL. Pair with `search` for ChatGPT Company Knowledge integration. |

### Account

| Tool | Description |
|------|-------------|
| `who-ami-tool` | Get the authenticated user, current workspace, workspace members, and token abilities |

### Workspace intelligence

| Tool | Description |
|------|-------------|
| `get-crm-schema-tool` | Get the active schema, custom fields, filters, and relationships for one entity type |
| `get-crm-summary-tool` | Get record counts, pipeline totals by stage, and task due status in your timezone |
| `aggregate-opportunities-tool` | Group opportunity counts and amounts by stage or company, with optional date bounds |
| `list-activity-tool` | List recent CRM changes with actors, record links, and field-level differences. A deleted account shows as `Former member` |
| `list-custom-fields-tool` | List active and inactive custom-field definitions, including choice options |

### Companies

| Tool | Description |
|------|-------------|
| `list-companies-tool` | List companies with an optional filter, sort and pagination |
| `get-company-tool` | Get a single company by ID with full details and relationships |
| `create-company-tool` | Create a new company (requires `name`) |
| `update-company-tool` | Update a company by ID |
| `delete-company-tool` | Soft-delete a company by ID |

### People

| Tool | Description |
|------|-------------|
| `list-people-tool` | List people with an optional filter, sort and pagination |
| `get-people-tool` | Get a single person by ID with full details and relationships |
| `create-people-tool` | Create a new person (requires `name`, optional `company_id`) |
| `update-people-tool` | Update a person by ID |
| `delete-people-tool` | Soft-delete a person by ID |

### Opportunities

| Tool | Description |
|------|-------------|
| `list-opportunities-tool` | List opportunities with an optional filter, sort and pagination |
| `get-opportunity-tool` | Get a single opportunity by ID with full details and relationships |
| `create-opportunity-tool` | Create a new opportunity (requires `name`, optional `company_id`, `contact_id`) |
| `update-opportunity-tool` | Update an opportunity by ID |
| `delete-opportunity-tool` | Soft-delete an opportunity by ID |

### Tasks

| Tool | Description |
|------|-------------|
| `list-tasks-tool` | List tasks with an optional filter, sort and pagination |
| `get-task-tool` | Get a single task by ID with full details and relationships |
| `create-task-tool` | Create a new task (requires `title`) |
| `update-task-tool` | Update a task by ID |
| `delete-task-tool` | Soft-delete a task by ID |
| `attach-task-to-entities-tool` | Link a task to companies, people, opportunities, or assign users. Adds without removing existing links. |
| `detach-task-from-entities-tool` | Unlink a task from companies, people, opportunities, or unassign users |

### Notes

| Tool | Description |
|------|-------------|
| `list-notes-tool` | List notes with an optional filter, sort and pagination |
| `get-note-tool` | Get a single note by ID with full details and relationships |
| `create-note-tool` | Create a new note (requires `title`) |
| `update-note-tool` | Update a note by ID |
| `delete-note-tool` | Soft-delete a note by ID |
| `attach-note-to-entities-tool` | Link a note to companies, people, or opportunities. Adds without removing existing links. |
| `detach-note-from-entities-tool` | Unlink a note from companies, people, or opportunities |

### Email

These tools appear only for a connection that was given email access. The reading tools show the synced email its user may see, at the sharing level the mailbox owner chose. The mailbox, draft and send tools work on the user's own mailboxes.

The two reading tools need read access. The mailbox tool appears for a connection allowed to draft or send, and the draft tool for one allowed to draft. The send tool needs the send permission and a role that may send through an assistant. An email goes to at most 20 recipients in total across To, CC and BCC. A user can have up to 10 assistant emails waiting at once.

| Tool | Description |
|------|-------------|
| `list-emails-tool` | List synced emails newest first, with optional search, linked record, direction, thread and date filters. Subject and snippet appear only where the sharing level allows. |
| `get-email-tool` | Get one synced email by ID. The body and attachment names appear only at full access, and a long body is cut. |
| `list-email-accounts-tool` | List the mailboxes the user has connected in this workspace, with the ID to use when drafting and whether each can send. |
| `create-email-draft-tool` | Save a draft in one of the user's mailboxes, as plain text or markdown, with the default signature unless you turn it off. Nothing is sent: the user reviews the draft in Relaticle. |
| `send-email-tool` | Send an email from one of the user's mailboxes. Each email waits a few minutes before it leaves, and its user can cancel it from notifications or the Outbox. |

### Files

| Tool | Description |
|------|-------------|
| `upload-file` | Store a file in the workspace from a public https URL, a base64 body with `filename`, or an `upload_id`. Returns `suggested_markdown` to embed the file in a rich-editor field such as a note body, plus `file_id` for the stored file. Allowed types: pdf, doc, docx, xlsx, pptx, jpg, jpeg, png, gif, webp, up to 10 MB. 60 calls per hour per workspace. |
| `create-upload-url` | Get a five-minute signed URL to `PUT` a file body to, then pass the returned `upload_id` to `upload-file`. |

Uploads stay pending for 24 hours. Saving a record whose rich-editor body embeds the file claims it; unclaimed files are purged.

Entity list tools take `filter`, `sort`, `include`, `per_page` (default 15, maximum 25) and `page`. `sort` is an object with `field` and `direction` (`asc` or `desc`). `include` is a list of singular relationships or relationship counts. A list tool refuses any other argument. To find records by words across every entity, use the `search` tool.

`sort` takes a native field or a custom field that holds one value. A field that holds a list, such as email, phone, link or tags, cannot be sorted.

List responses include `page`, `per_page`, `total`, `has_more`, and `next_page`. Create and update tools accept `custom_fields` as key-value pairs. A write stores a phone in E.164 form, a domain as a bare host, and a date-time as its UTC instant.

### Filter records

Pass `filter` to a list tool to narrow the records it returns. A filter is a JSON object. The [REST API](/developers/rest-api) and Rela, the built-in AI assistant, read the same filter.

Every key in a filter is one of these:

| Key | Meaning |
|---|---|
| `$and`, `$or`, `$not` | Logic |
| `$eq`, `$in`, `$contains` and the other operators | A test on the field or relation around it |
| A native field such as `name` or `created_at` | A condition on one of the record's own columns |
| A relation such as `company` or `assignees` | A condition on linked records |
| `custom_fields` | Conditions on your custom fields, keyed by field code |
| A sub-field such as `domain` | One part of an email or link value |

A key that starts with `$` is a keyword. Every other key is a name. There is no shorthand: `{"stage": "Won"}` is an error, and so is an operator without its `$`.

Native fields and relations sit at the top level. Custom fields sit under `custom_fields`, so a custom field coded `name` never collides with the native `name`.

#### Native fields and relations

| Entity | Filter names |
|---|---|
| Companies | Native fields `name`, `created_at`, `updated_at`, `creation_source`. Record relations `people`, `opportunities`. Member relations `creator`, `accountOwner`. |
| People | Native fields `name`, `created_at`, `updated_at`, `creation_source`. Record relation `company`. Member relation `creator`. |
| Opportunities | Native fields `name`, `created_at`, `updated_at`, `creation_source`, `stale_days`. Record relations `company`, `contact`. Member relation `creator`. |
| Tasks | Native fields `title`, `created_at`, `updated_at`, `creation_source`, `assigned_to_me`. Record relations `companies`, `people`, `opportunities`. Member relations `creator`, `assignees`. |
| Notes | Native fields `title`, `created_at`, `updated_at`, `creation_source`. Record relations `companies`, `people`, `opportunities`. Member relation `creator`. |

`name` and `title` take the text operators. `created_at` and `updated_at` take the date and time operators. A date operand is `YYYY-MM-DD` or an ISO 8601 date-time such as `2026-10-01T09:30:00Z`, and any other format is an error. A date-time with an offset, such as `2026-10-01T13:00:00+05:00`, is read as the same instant in UTC. A date without a time covers that whole UTC day, on these fields and on a custom date and time field. `creation_source` is a single choice with the values `web`, `sample`, `import`, `api`, `mcp`, `chat` and `mailbox`. `stale_days` matches opportunities by whole days without activity, as in `{"$gte": 30}`. `assigned_to_me` takes `{"$eq": true}`.

#### Combine conditions

- Keys in one object combine with AND.
- `$or` takes a list of condition objects and returns records that match any of them.
- `$and` takes a list too. Use it when one object would need two `$or` keys.
- `$not` takes one condition object. It returns every record that object does not return, including records where the field is empty.
- An empty `$and` or `$or` list, an empty `$not` object and an empty relation node are errors. An empty filter means no filter.

#### Filter through a relation

A relation node holds the same filter grammar, applied to the related records. The names inside it belong to the related entity.

- Record relations take `$in`, `$not_in` (record IDs) and `$is_empty`, or conditions on the related record.
- Member relations take `$in`, `$not_in` (member IDs) and `$is_empty`. Member names and emails are not filterable. Someone who has left the workspace counts as no member, so a record they created matches `{"creator": {"$is_empty": true}}`.
- A to-many relation matches when at least one related record matches. Every condition in one relation node applies to the same related record. To find records with none, wrap the node in `$not`.
- Relations nest at most 2 levels, such as `company` then `people`.

#### Operators by field type

Native fields take the operators of the field type they match. `$is_empty` takes `true` or `false`.

| Field type | Operators |
|---|---|
| Single choice (select, status, radio, toggle buttons), `creation_source` | `$eq`, `$in`, `$not_in`, `$is_empty` |
| Multi choice (multi select, checkbox list), tags, email, phone, link, domain | `$has_any`, `$has_none`, `$is_empty` |
| Text | `$eq`, `$contains`, `$is_empty` |
| Number, currency, date, date and time | `$eq`, `$gt`, `$gte`, `$lt`, `$lte`, `$is_empty` |
| Checkbox, toggle | `$eq`, `$is_empty` |
| Record, relationship | `$eq`, `$in`, `$contains` |

Email and link fields also take a `domain` sub-field with `$in` and `$not_in`. It matches the domain of each value, so `{"domain": {"$in": ["canva.com"]}}` finds every address at `canva.com`. A domain is a bare host. A path, user or port is an error.

On a record or relationship field, `$eq` and `$in` take the IDs of the linked records, and `$contains` matches a linked record by its name.

Text area, rich text and color fields take no filter. Neither does an encrypted or inactive field.

#### How values match

- Choice values take the option label or its ID. An unknown label returns an error listing the valid labels. An ambiguous label asks for the option ID.
- Email and link values match in any letter case. A link also matches with or without its scheme, so `acme.com/team` finds `https://acme.com/team`.
- Domain values match as a bare host or a full URL, with or without `www.`, so `https://www.acme.com/about` finds `acme.com`.
- Phone values match in any format. A phone operand needs a country code, such as `+1 415 555 0100`. An operand without one returns the error `phone_number needs a country code, for example +1 415 555 0100.`
- Tags match the exact stored value.
- `$not_in` and `$has_none` also match records where the field is empty.

#### Limits

A filter holds at most 20 conditions, counted as every operator at any depth. It nests `$and`, `$or` and `$not` at most 3 levels and relations at most 2 levels. A list holds at most 100 values.

#### Examples

Opportunities worth at least 10,000 that sit in Proposal/Price Quote or Negotiation/Review. The condition outside `$or` combines with it by AND.

```json
{
  "custom_fields": {"amount": {"$gte": 10000}},
  "$or": [
    {"custom_fields": {"stage": {"$eq": "Proposal/Price Quote"}}},
    {"custom_fields": {"stage": {"$eq": "Negotiation/Review"}}}
  ]
}
```

Opportunities at companies with the `icp` toggle on. `company` is a relation, so its node holds conditions on the company, including its custom fields.

```json
{"company": {"custom_fields": {"icp": {"$eq": true}}}}
```

People with an email address at one domain. This works the same for a link field.

```json
{"custom_fields": {"emails": {"domain": {"$in": ["canva.com"]}}}}
```

Stage labels and field codes belong to your workspace. Call `get-crm-schema-tool` to read yours.

#### Read the filter vocabulary

`get-crm-schema-tool` returns `filterable_fields` for one entity type. It holds three parts:

- Each native field and relation by name, with its `type`, `operators` and an `example`. Relations add the related `entity`. `creation_source` adds its `values`. Relations, member relations, `stale_days` and `assigned_to_me` add an `operand` that states what they accept.
- `types` maps each custom field type present in your workspace to its `operators`, its `matching` rule and any `sub_fields`. A type whose values you type freely also carries an `example`, and a choice type carries it on the field instead.
- `custom_fields` lists each filterable custom field by code, with its `name` and `type`. A choice field also carries its `options` and an `example` built from them.

#### Filter errors

An invalid filter returns an error that names the part to fix. For example, an unknown custom field code returns `"stagee" is not a filterable custom field on opportunity. Available: amount, close_date, stage.` A bare operator returns `Operators start with $. Use $eq.`

The flat names of the earlier filter no longer exist. Inside `filter`, keys such as `search`, `created_after`, `company_id` and `assignee_ids` return an error that names the replacement, for example `company_id was replaced. Use company (or companies) with $in.` The same names sent beside `filter` return that error too.

---

## Schema Resources

The server exposes five schema resources that describe each entity's fields, including any custom fields your workspace has configured, plus a summary resource with record counts:

| Resource URI | Description |
|---|---|
| `relaticle://schema/company` | Company fields and custom fields |
| `relaticle://schema/people` | People fields and custom fields |
| `relaticle://schema/opportunity` | Opportunity fields and custom fields |
| `relaticle://schema/task` | Task fields and custom fields |
| `relaticle://schema/note` | Note fields and custom fields |

Resource support varies by MCP client. Use `get-crm-schema-tool` before a custom-field write when your client does not expose resources automatically.

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

The MCP server scopes all data to the workspace associated with your token. Make sure the token was created for the correct workspace and that the workspace has data.

### Connection Refused

Verify the MCP URL is correct: `https://mcp.relaticle.com`.

### Custom Fields Not Showing

Custom fields are workspace-specific. If you don't see them, confirm they're configured for your workspace in **Settings > Custom Fields**. Then call `get-crm-schema-tool` for the entity type.

### Rate Limiting

MCP tool requests are limited to 120 per minute per authenticated user. OAuth authorization endpoints are limited to 20 per minute per IP address.
