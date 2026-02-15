# Developer Guide

Technical documentation for developers and contributors.

---

## Quick Start

```
git clone https://github.com/Relaticle/relaticle.git
cd relaticle && composer app-install
composer run dev
```

Visit `http://localhost:8000` to access the application.

---

## Tech Stack

| Component | Technology                            |
|-----------|---------------------------------------|
| Backend | PHP 8.4, Laravel 12                   |
| Admin UI | Filament 5                            |
| Frontend | Livewire 4, Alpine.js, Tailwind CSS 4 |
| Database | PostgreSQL (recommended), MySQL       |
| Queue | Laravel Horizon                       |
| Testing | Pest v4                               |
| Static Analysis | PHPStan (Level 7)                     |
| Code Style | Laravel Pint, Rector                  |
| Auth | Laravel Jetstream                     |

---

## Architecture

### Core Models

```
Team ─┬─ User (via Membership)
      ├─ Company ─┬─ People
      │           └─ Opportunity ─── Contact (People)
      ├─ Task (many-to-many with Company, People, Opportunity)
      └─ Note (many-to-many with Company, People, Opportunity)
```

### Multi-Tenancy

All workspace data is isolated via the `HasTeam` trait. Every query automatically scopes to the current team.

### Key Traits

| Trait | Purpose |
|-------|---------|
| `HasTeam` | Workspace isolation |
| `HasCreator` | Tracks record creator |
| `HasNotes` | Polymorphic notes relationship |
| `HasAiSummary` | AI-generated summaries |

---

## Development Setup

### Requirements

- **PHP 8.4+** with extensions: pdo_pgsql, gd, bcmath, mbstring, xml
- **PostgreSQL 13+** or MySQL 8.0+
- **Node.js 20+**
- **Composer 2+**

### Manual Installation

```
git clone https://github.com/Relaticle/relaticle.git
cd relaticle
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan storage:link
npm run build
composer run dev
```

### Environment Config

For PostgreSQL:
```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=relaticle
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

---

## Quality Tools

```
composer lint          # Format with Pint + Rector
composer test:lint     # Check formatting
composer test:types    # PHPStan analysis
composer test:pest     # Run tests
composer test          # All checks (required before PR)
```

### Git Hooks

Enable pre-commit checks:
```
git config core.hooksPath .githooks
```

---

## Testing

All contributions require:
- Unit tests for new functionality
- Feature tests for user interactions
- Minimum 99.9% type coverage

Run specific tests:
```
php artisan test tests/Feature/ExampleTest.php
php artisan test --filter="test_method_name"
```

---

## Custom Fields

Relaticle includes a custom fields system for extending entities without migrations.

- **License**: AGPL-3.0 (free for open source) or Commercial
- **Docs**: [custom-fields.relaticle.com](https://custom-fields.relaticle.com)

---

## Deployment

### Docker (Recommended)

```
docker pull ghcr.io/relaticle/relaticle:latest
cp docker-compose.prod.yml docker-compose.yml
cp .env.example .env
# Edit .env with production settings
docker compose up -d
```

By default, the CRM panel is available at `{APP_URL}/app` (path mode). For subdomain routing, set `APP_PANEL_DOMAIN` in your `.env` (e.g., `APP_PANEL_DOMAIN=app.example.com`).

Included services:
- **app** - Web server (nginx + php-fpm) on port 8080
- **horizon** - Queue processing
- **scheduler** - Cron jobs
- **postgres** - Database
- **redis** - Cache and sessions

### Manual Deployment

1. Pull latest code
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build`
4. `php artisan migrate --force`
5. `php artisan optimize`
6. `php artisan queue:restart`

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Queue not processing | `php artisan queue:restart` |
| File upload errors | `chmod -R 775 storage bootstrap/cache` |
| Slow queries | Use Laravel Telescope to identify, then add indexes |
| View cache issues | `php artisan view:clear && npm run build` |

---

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feat/your-feature`
3. Make changes following coding standards
4. Run tests: `composer test`
5. Commit with conventional messages
6. Open a Pull Request

PRs must pass all checks before merge.

---

## Resources

- [Laravel Docs](https://laravel.com/docs/12.x)
- [Filament Docs](https://filamentphp.com/docs)
- [Livewire Docs](https://livewire.laravel.com/)
- [Pest Docs](https://pestphp.com/)
