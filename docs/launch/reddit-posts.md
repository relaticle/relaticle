# Reddit Launch Posts — Relaticle

---

## Post 1: r/selfhosted

**Title:** Relaticle — open-source CRM with MCP server for AI agents (self-hosted, Docker)

**Body:**

Hey r/selfhosted,

I've been building an open-source CRM called Relaticle that I wanted to share. The main thing that makes it different: it ships with a production MCP server (20 tools) so AI agents can read and write your CRM data directly.

**What it is:**
- Self-hosted CRM for managing contacts, companies, opportunities, tasks, and notes
- MCP server with 20 tools — any MCP-compatible AI agent can operate it
- REST API with Sanctum auth, JSON:API format
- 22 custom field types (no code changes needed)
- Multi-team support with role-based permissions

**Tech stack:**
- Laravel 12, PHP 8.4, PostgreSQL 17
- Filament 5 admin panel
- Docker deployment

**Self-hosting details:**
- Standard Docker Compose setup
- PostgreSQL + Redis
- Works behind reverse proxy (Traefik, Caddy, nginx)
- 900+ automated tests

**License:** AGPL-3.0

**Links:**
- GitHub: github.com/relaticle/relaticle
- Managed hosting (free tier): app.relaticle.com
- API docs: /docs endpoint with Scalar UI

Happy to answer any questions about the architecture or deployment.

---

## Post 2: r/opensource

**Title:** Relaticle: open-source CRM built for AI agents (AGPL-3.0, Laravel 12)

**Body:**

Sharing an open-source project I've been working on. Relaticle is a CRM built from the ground up for AI agent integration.

**Why another CRM?**

The CRM space has a gap: HubSpot and Salesforce are closed-source with expensive per-seat pricing. SuiteCRM and EspoCRM are open source but have dated UIs and no AI/MCP integration. There's nothing open-source that treats AI agents as first-class users.

**What Relaticle does differently:**
- Ships with an MCP server (20 tools) — AI agents can create contacts, update deals, log notes
- REST API with JSON:API format, Sanctum auth, Spatie QueryBuilder
- 22 custom field types with conditional visibility and per-field encryption
- Modern UI built with Filament 5
- Self-hosted with full data ownership

**License:** AGPL-3.0

We chose AGPL because we believe CRM data is sensitive and the software managing it should be auditable. The copyleft license ensures improvements stay open.

**How to contribute:**
- Star and fork: github.com/relaticle/relaticle
- Report issues on GitHub
- Join the Discord for discussion
- PRs welcome — we have 900+ tests and CI

Looking for feedback, feature requests, and contributors.

---

## Post 3: r/laravel

**Title:** Built a CRM with Laravel 12, Filament 5, and a production MCP server — here's the architecture

**Body:**

Hey r/laravel,

I wanted to share the architecture of Relaticle, an open-source CRM I've been building with Laravel 12 and Filament 5. The interesting part is the MCP server integration — 20 tools that let AI agents operate the CRM.

**Architecture highlights:**

1. **Shared Actions layer** — Business logic lives in `app/Actions/` (CreateCompany, ListPeople, etc.). Both the REST API controllers and MCP tools call the same actions. No logic duplication.

2. **MCP server** — Uses `laravel/mcp` package. 20 tools registered on `RelaticleServer`. Per-entity schema resources expose custom field definitions dynamically.

3. **REST API** — Versioned under `/api/v1/`. Sanctum auth. JSON:API format using Laravel 12's native `JsonApiResource`. Spatie QueryBuilder for filtering/sorting.

4. **Custom fields** — 22 field types via a custom package (`relaticle/custom-fields`). Uses EAV pattern. No migrations needed when users add fields. Conditional visibility and per-field encryption supported.

5. **Team-scoped tokens** — API tokens are permanently scoped to a team at creation time (like GitHub PATs). The `SetApiTeamContext` middleware bridges Sanctum auth to the web guard so model observers and global scopes work unchanged.

6. **API docs** — Scribe + Scalar UI. Custom strategy auto-documents Spatie QueryBuilder parameters.

**Stack:** Laravel 12, Filament 5, Livewire 4, PHP 8.4, PostgreSQL 17, 900+ tests.

**Links:**
- GitHub: github.com/relaticle/relaticle
- AGPL-3.0 license

Happy to dive into any of these patterns if you're interested.
