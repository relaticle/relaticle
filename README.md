<p align="center">
  <a href="https://relaticle.com">
    <img src="https://relaticle.com/brand/logomark.svg" width="100px" alt="Relaticle logo" />
  </a>
</p>

<h1 align="center">The Open-Source CRM Built for AI Agents</h1>

<p align="center">
  <a href="https://github.com/Relaticle/relaticle/actions"><img src="https://img.shields.io/github/actions/workflow/status/Relaticle/relaticle/deploy.yml?style=for-the-badge&label=tests" alt="Tests"></a>
  <a href="https://relaticle.com/docs/mcp"><img src="https://img.shields.io/badge/MCP_Tools-30-8A2BE2?style=for-the-badge" alt="30 MCP Tools"></a>
  <a href="https://laravel.com/docs/12.x"><img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel" alt="Laravel 12"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.4-777BB4?style=for-the-badge&logo=php" alt="PHP 8.4"></a>
  <a href="https://github.com/Relaticle/relaticle/blob/main/LICENSE"><img src="https://img.shields.io/badge/License-AGPL--3.0-blue.svg?style=for-the-badge" alt="License"></a>
</p>

<p align="center">
  <a href="https://relaticle.com">Website</a> ·
  <a href="https://relaticle.com/docs">Documentation</a> ·
  <a href="https://relaticle.com/docs/mcp">MCP Server</a> ·
  <a href="https://github.com/orgs/Relaticle/projects/1/views/1">Roadmap</a> ·
  <a href="https://github.com/Relaticle/relaticle/discussions">Discussions</a>
</p>

<p align="center">
  <img src="https://relaticle.com/images/github-preview-light.png?v=3" alt="Relaticle Dashboard - Manage contacts, companies, and opportunities in a modern interface" />
  <br>
  <sub>Clean, modern interface built with Filament 5 and Livewire 4</sub>
</p>

---

# About Relaticle

Relaticle is a self-hosted CRM with a production-grade MCP server. Connect any AI agent -- Claude, GPT, or open-source models -- with 30 tools for full CRM operations. 22 custom field types, REST API, and multi-team isolation.

**Perfect for:** Developer-led teams, AI-forward startups, and SMBs who want AI agent integration without vendor lock-in.

**Core Strengths:**

- **Agent-Native Infrastructure** - MCP server with 30 tools, REST API with full CRUD, schema discovery for AI agents
- **Customizable Data Model** - 22 field types including entity relationships, conditional visibility, and per-field encryption. No migrations needed.
- **Multi-Team Isolation** - 5-layer authorization with team-scoped data and workspaces
- **Modern Tech Stack** - Laravel 12, Filament 5, PHP 8.4, 1,100+ automated tests
- **Privacy-First** - Self-hosted, AGPL-3.0, your data stays on your server

# Requirements

- PHP 8.4+
- PostgreSQL 17+
- Composer 2 and Node.js 20+
- Redis for queues (optional for development)

# Installation

```bash
git clone https://github.com/Relaticle/relaticle.git
cd relaticle && composer app-install
```

# Development

```bash
# Start everything (server, queue, vite)
composer dev

# Run tests
composer test

# Format code
composer lint
```

# Self-Hosting

See the [Self-Hosting Guide](https://relaticle.com/docs/self-hosting) for Docker and manual deployment instructions.

# Documentation

Visit our [documentation](https://relaticle.com/docs) for guides on business usage, technical architecture, MCP server setup, REST API integration, and more.

# Community & Support

- [Report Issues](https://github.com/Relaticle/relaticle/issues)
- [Request Features](https://github.com/Relaticle/relaticle/discussions/categories/ideas)
- [Ask Questions](https://github.com/Relaticle/relaticle/discussions/categories/q-a)
- [Discord](https://discord.gg/relaticle)
- [Star us on GitHub](https://github.com/Relaticle/relaticle) to support the project

# License

Relaticle is open-source software licensed under the [AGPL-3.0 license](LICENSE).

# Star History

[![Star History Chart](https://api.star-history.com/svg?repos=Relaticle/relaticle&type=Date)](https://www.star-history.com/#Relaticle/relaticle&Date)
