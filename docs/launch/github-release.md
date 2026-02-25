# GitHub Release Notes — v3.1.0

## Relaticle v3.1.0 — Agent-Native CRM

Relaticle is now agent-native. This release introduces a production MCP server, REST API, and positions Relaticle as the open-source CRM built for AI agents.

### MCP Server (20 Tools)
- Full CRUD for all 5 entity types: companies, people, opportunities, tasks, notes
- Custom field support in create/update operations
- Per-entity schema resources exposing custom field definitions
- CRM overview prompt for agent orientation
- Schema discovery so agents know what fields exist before writing data

### REST API
- Versioned at `/api/v1/`
- JSON:API format with Laravel 12's `JsonApiResource`
- Sanctum authentication with team-scoped tokens
- Spatie QueryBuilder integration for filtering, sorting, and pagination
- Custom fields metadata endpoint (`GET /api/v1/custom-fields`)
- API documentation with Scribe + Scalar UI at `/docs`

### Access Tokens
- Renamed from "API Tokens" to "Access Tokens" (used for both REST API and MCP)
- Team-scoped tokens — permanently bound to a specific team at creation time
- Token expiration support (30 days, 60 days, 90 days, 1 year, no expiration)
- Filament-based management UI with permissions

### Custom Fields
- Writable via REST API and MCP (previously read-only)
- Validation for field types, required fields, and option constraints
- Unknown custom fields silently ignored (forward-compatible)

### Other Changes
- `CreationSource::MCP` enum value for tracking AI-created records
- Shared Actions layer for business logic (no duplication between API and MCP)
- Explicit AI crawler allows in robots.txt
- JSON-LD schema markup on homepage
- Landing page copy updated for agent-native positioning

### Technical
- 900+ automated tests (58 new API + MCP tests)
- Laravel 12, Filament 5, PHP 8.4, PostgreSQL 17
- Spatie QueryBuilder v6 for declarative API filtering/sorting
