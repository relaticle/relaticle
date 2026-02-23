<p align="center">
  <a href="https://relaticle.com">
    <img src="https://relaticle.com/brand/logomark.svg" width="100px" alt="Relaticle logo" />
  </a>
</p>

<h1 align="center"> The Open-Source CRM Built for AI Agents</h1>

<p align="center">
  <a href="https://github.com/Relaticle/relaticle/actions"><img src="https://img.shields.io/github/actions/workflow/status/Relaticle/relaticle/tests.yml?branch=main&style=for-the-badge&label=tests" alt="Tests"></a>
  <a href="https://laravel.com/docs/12.x"><img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel" alt="Laravel 12"></a>
  <a href="https://filamentphp.com"><img src="https://img.shields.io/badge/Filament-4.x-FBBC04?style=for-the-badge" alt="Filament 4"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.4-777BB4?style=for-the-badge&logo=php" alt="PHP 8.4"></a>
  <a href="https://github.com/Relaticle/relaticle/blob/main/LICENSE"><img src="https://img.shields.io/badge/License-AGPL--3.0-blue.svg?style=for-the-badge" alt="License"></a>
</p>

<p align="center">
  <a href="https://relaticle.com">🌐 Website</a> ·
  <a href="https://relaticle.com/documentation">📚 Documentation</a> ·
  <a href="https://github.com/orgs/Relaticle/projects/1/views/1">🛣️ Roadmap</a> ·
  <a href="https://github.com/Relaticle/relaticle/discussions">💬 Discussions</a>
</p>

<p align="center">
  <img src="https://relaticle.com/images/github-preview-light.png" alt="Relaticle Dashboard - Manage contacts, companies, and opportunities in a modern interface" />
  <br>
  <sub>Clean, modern interface built with Filament 5 and Livewire 4</sub>
</p>

---

# About Relaticle

**Perfect for:** Teams and AI agents that need a modern, self-hosted CRM with first-class API and MCP support.

Relaticle is an agent-native CRM platform built for teams who want full control over their data -- and full access for their AI. Ships with a production-grade MCP server (20 tools), REST API, and 22 custom field types. Self-hosted, open source, no vendor lock-in.

**Core Strengths:**
- **Agent-Native** - MCP server with 20 tools, REST API, schema resources for AI discovery
- **Fully Customizable** - 22 custom field types, no-code, no migrations required
- **Multi-Team Support** - Isolated workspaces with 5-layer authorization
- **Self-Hosted** - Complete data ownership, bring your own AI provider
- **Open Source** - Transparent development with AGPL-3.0 license

**vs Other CRMs:**
- **vs HubSpot:** Self-hosted, 20 MCP tools (vs their 9), no per-seat pricing
- **vs Salesforce:** Open source, no enterprise bloat, you control the AI
- **vs Attio:** Self-hosted, open source, same agent-native vision
- **vs SuiteCRM:** Modern stack, agent-native, beautiful UI

Visit our [website](https://relaticle.com) to learn more about Relaticle's capabilities.

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

# Docker Deployment

For production deployment using Docker, see [`docker-compose.prod.yml`](docker-compose.prod.yml).

# Documentation

Visit our [comprehensive documentation](https://relaticle.com/documentation) for guides on business usage, technical architecture, API integration, and more.

# Community & Support

- 🐛 [Report Issues](https://github.com/Relaticle/relaticle/issues)
- 💡 [Request Features](https://github.com/Relaticle/relaticle/discussions/categories/ideas)
- 💬 [Ask Questions](https://github.com/Relaticle/relaticle/discussions/categories/q-a)
- ⭐ [Star us on GitHub](https://github.com/Relaticle/relaticle) to support the project

# License

Relaticle is open-source software licensed under the [AGPL-3.0 license](LICENSE).

# Star History

[![Star History Chart](https://api.star-history.com/svg?repos=Relaticle/relaticle&type=Date)](https://www.star-history.com/#Relaticle/relaticle&Date)
