# Show HN Post — Relaticle

**Title:** Show HN: Relaticle -- Open-source CRM with 20 MCP tools for AI agents

**Body:**

Relaticle is an open-source CRM (AGPL-3.0) with a production MCP server that lets AI agents manage customer data. Self-hosted, built with Laravel 12 and PHP 8.4.

**The problem:** CRMs weren't built for AI agents. Adding an MCP server to existing CRMs is like bolting an API onto software designed for mouse clicks. Schema discovery, custom fields, authorization -- all break down.

**The approach:** Build the MCP server and REST API as first-class citizens from the start. The same Actions layer powers both the web UI and the agent interface.

**What's in the box:**
- MCP server with 20 tools (CRUD for companies, people, opportunities, tasks, notes + schema discovery)
- REST API with JSON:API format, Spatie QueryBuilder, Sanctum auth
- 22 custom field types (entity relationships, conditional visibility, per-field encryption)
- Multi-team isolation with 5-layer authorization
- 900+ automated tests

**Technical details:**
- Shared Actions pattern -- `CreateCompany`, `ListPeople`, etc. called by both API controllers and MCP tools
- Team-scoped API tokens (like GitHub PATs) -- tokens are permanently bound to a team
- Custom field schema exposed as MCP resources so agents can discover what fields exist
- Built on Laravel 12, Filament 5, PostgreSQL 17

GitHub: https://github.com/relaticle/relaticle
Try free: https://app.relaticle.com
