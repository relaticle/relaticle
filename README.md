<p align="center">
  <a href="https://relaticle.com">
    <img src="https://relaticle.com/brand/logomark.svg" width="100px" alt="Relaticle logo" />
  </a>
</p>

<h1 align="center">The Open-Source CRM Built for People and AI-Powered Work</h1>

<p align="center">
  <a href="https://github.com/Relaticle/relaticle/actions/workflows/tests.yml?query=branch%3Amain"><img src="https://img.shields.io/github/actions/workflow/status/Relaticle/relaticle/tests.yml?branch=main&style=for-the-badge&label=tests" alt="Tests"></a>
  <a href="https://relaticle.com/docs/mcp"><img src="https://img.shields.io/badge/MCP-Server-8A2BE2?style=for-the-badge" alt="MCP Server"></a>
  <a href="https://laravel.com/docs/13.x"><img src="https://img.shields.io/badge/Laravel-13.x-FF2D20?style=for-the-badge&logo=laravel" alt="Laravel 13"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.5-777BB4?style=for-the-badge&logo=php" alt="PHP 8.5"></a>
  <a href="https://github.com/Relaticle/relaticle/blob/main/LICENSE"><img src="https://img.shields.io/badge/License-AGPL--3.0-blue.svg?style=for-the-badge" alt="License"></a>
</p>

<p align="center">
  <a href="https://relaticle.com">Website</a> ·
  <a href="https://relaticle.com/docs">Documentation</a> ·
  <a href="https://relaticle.com/docs/mcp">MCP Server</a> ·
  <a href="https://github.com/orgs/Relaticle/projects/1/views/1">Roadmap</a> ·
  <a href="https://relaticle.com/discord">Discord</a>
</p>

<p align="center">
  <img src="https://relaticle.com/images/github-preview-light.png?v=5" alt="Relaticle Home - Rela the AI assistant, chat history, and your open tasks in one view" />
  <br>
  <sub>Clean, modern interface built with Filament 5 and Livewire 4</sub>
</p>

---

# About Relaticle

Relaticle is a self-hosted CRM your AI agents can work in. Connect Claude, ChatGPT, or an open-source model over MCP to read, write, and analyze your CRM data. Custom fields, REST API, and multi-workspace isolation.

**Perfect for:** Developer-led teams, AI-forward startups, and SMBs who want AI agent integration without vendor lock-in.

**Core Strengths:**

- **Agent-Native Infrastructure** - MCP server and REST API with full CRUD, schema access, activity history, and pipeline analysis
- **Customizable Data Model** - Custom fields for text, dates, currency, selects, and links between records, with per-field encryption. No migrations needed.
- **Multi-Workspace Isolation** - Workspace-scoped data with role-based permissions
- **Modern Tech Stack** - Laravel 13, Filament 5, PHP 8.5, with an automated test suite on every change
- **Privacy-First** - Self-hosted, AGPL-3.0, your data stays on your server

# Requirements

- PHP 8.5+
- PostgreSQL 17+
- Composer 2 and Node.js 22+
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

# Internationalization

Relaticle ships English-only in the main repository, but the codebase is fully i18n-ready. Self-host forks can drop a `lang/<locale>/` directory in place to translate the entire app. See [`docs/i18n.md`](docs/i18n.md) for the override workflow and CI guardrails.

# Documentation

Visit our [documentation](https://relaticle.com/docs) for guides on business usage, technical architecture, MCP server setup, REST API integration, and more.

# Community & Support

- [Report Issues](https://github.com/Relaticle/relaticle/issues)
- [Request Features](https://relaticle.com/discord)
- [Ask Questions](https://relaticle.com/discord)
- [Star us on GitHub](https://github.com/Relaticle/relaticle) to support the project

# License

Relaticle is open-source software licensed under the [AGPL-3.0 license](LICENSE).

# Star History

[![Star History Chart](https://api.star-history.com/chart?repos=relaticle/relaticle&type=date&legend=top-left&sealed_token=c38GyCHd6M75Bak3QvcMoEfYHGDlV1lAIyuGyJoQ8AA1kyVwXhZR7A1qYLuGDZg9H0LsvERRX3YUEkOWkjt4q0N0y-mlHVm2NI3mDb74NKAka4KPxMrBBA)](https://www.star-history.com/?repos=relaticle%2Frelaticle&type=date&legend=top-left)
