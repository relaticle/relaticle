<p align="center">
  <a href="https://relaticle.com">
    <img src="https://relaticle.com/relaticle-logo.svg" width="100px" alt="Relaticle logo" />
  </a>
</p>

<h1 align="center"> Next-Generation Open-Source CRM</h1>

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
  <sub>Clean, modern interface built with Filament 4 and Livewire 3</sub>
</p>

---

# About Relaticle

**Perfect for:** Laravel developers, agencies, and SMBs who need a modern CRM they can customize and self-host.

Relaticle is a powerful, adaptable CRM platform built for teams who've outgrown spreadsheets but find Salesforce overkill. Unlike SaaS CRMs that lock your data in their cloud, Relaticle gives you complete control with self-hosting and unlimited customization through our no-code custom fields system.

**Core Strengths:**
- **Fully Customizable** - Create and manage custom fields without coding
- **Multi-Team Support** - Securely manage multiple business units with isolated workspaces
- **Modern Technology** - Built on Laravel 12, PHP 8.4, and Filament 4
- **Privacy-Focused** - Self-host with complete data ownership
- **Open Source** - Transparent development with AGPL-3.0 license

**vs Other CRMs:**
- **vs HubSpot/Salesforce:** Self-hosted, no monthly fees, own your data
- **vs SuiteCRM:** Modern Laravel stack, no-code customization, beautiful UI
- **vs Custom Build:** Production-ready, maintained, community-supported

Visit our [website](https://relaticle.com) to learn more about Relaticle's capabilities.

# Requirements

- PHP 8.4+
- PostgreSQL 15+
- Composer 2 and Node.js 20+
- Redis for queues (optional for development)

# Installation

## Local Development

For a streamlined setup experience, use the single installation command:

```bash
git clone https://github.com/Relaticle/relaticle.git
cd relaticle && composer app-install
```

## Docker Deployment

Deploy Relaticle using Docker with a pre-built image:

```bash
# Generate an APP_KEY first
docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Create a `docker-compose.yml`:

```yaml
services:
  relaticle:
    image: ghcr.io/relaticle/relaticle:latest
    ports:
      - "8080:80"
    environment:
      APP_KEY: "base64:your-generated-key-here"
      APP_URL: "http://localhost:8080"
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PASSWORD: secretpassword
      REDIS_HOST: cache
      CACHE_STORE: redis
      SESSION_DRIVER: redis
      QUEUE_CONNECTION: redis
      AUTO_MIGRATE: "true"
    depends_on: [db, cache]

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: relaticle
      POSTGRES_USER: relaticle
      POSTGRES_PASSWORD: secretpassword
    volumes:
      - postgres-data:/var/lib/postgresql/data

  cache:
    image: redis:7-alpine
    volumes:
      - redis-data:/data

volumes:
  postgres-data:
  redis-data:
```

```bash
docker-compose up -d
```

Visit `http://localhost:8080` to access Relaticle.

For advanced Docker configuration, see [`docker-compose.prod.yml`](docker-compose.prod.yml).

# Development

```bash
# Start everything (server, queue, vite)
composer dev

# Run tests
composer test

# Format code
composer lint
```

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
