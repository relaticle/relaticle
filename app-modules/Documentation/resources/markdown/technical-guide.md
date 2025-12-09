# Technical Guide

## Architecture Overview

Relaticle is built on the Laravel 12 framework with Filament 4 for the admin panel interface. The front-end uses
Livewire and Tailwind CSS to create a responsive and interactive user experience.

### Tech Stack

- **Backend**: PHP 8.4, Laravel 12
- **Admin UI**: Filament 4
- **Frontend**: Livewire, Tailwind CSS, Alpine.js
- **Database**: PostgreSQL (recommended), MySQL (supported)
- **Testing**: Pest
- **Static Analysis**: PHPStan
- **Code Quality**: Laravel Pint, Rector
- **Task Queue**: Laravel Horizon
- **Authentication**: Laravel Jetstream, Laravel Fortify
- **Error Tracking**: Sentry integration

### Premium Component

Relaticle includes one premium component:

**Custom Fields** - A FilamentPHP plugin that enables unlimited custom fields on any Laravel model without database migrations.

- **License**: AGPL-3.0 (free for open source) or Commercial license (required for proprietary projects)
- **Documentation**: [custom-fields.relaticle.com](https://custom-fields.relaticle.com)

This is the only paid component in Relaticle, helping sustain the open-source project.

## Core Components

### Models

Relaticle's data structure revolves around these key models:

- **Team**: The organizational unit that groups users together
- **User**: System users with authentication and permissions
- **Company**: Organizations your business interacts with
- **People**: Individual contacts at companies
- **Opportunity**: Potential deals in the sales pipeline
- **Task**: Actionable items assigned to users
- **Note**: Documentation of interactions and important information

### Relationships

- Teams have many Users (through Memberships)
- Companies belong to Teams
- Companies have many People
- Companies have many Opportunities
- People belong to Companies
- Opportunities belong to Companies
- Tasks can be associated with various models (polymorphic)
- Notes can be associated with various models (polymorphic)

## Development Environment

### System Requirements

To develop Relaticle locally, you'll need:

- **PHP 8.4+** with the following extensions:
    - pdo_sqlite (recommended for development) or pdo_pgsql/pdo_mysql
    - gd
    - bcmath
    - ctype
    - fileinfo
    - json
    - mbstring
    - openssl
    - tokenizer
    - xml
- **Database**: SQLite (included with PHP) or PostgreSQL 13+/MySQL 8.0+
- **Node.js 20+** with npm
- **Composer 2+**

### Quick Installation (Recommended)

For a streamlined setup experience, use the single installation command:

```bash
git clone https://github.com/Relaticle/relaticle.git
cd relaticle && composer app-install
```

After installation, start all development services:

```bash
composer run dev
```

This will start the development server, queue worker, real-time logs, and asset watcher in parallel. Visit `http://localhost:8000` in your browser to access the application.

### Manual Installation Steps

If you prefer manual installation or need more control, follow these detailed steps:

1. **Clone the repository**

```bash
git clone https://github.com/Relaticle/relaticle.git
cd relaticle
```

2. **Install dependencies**

```bash
composer install
npm install
```

3. **Configure environment**

```bash
cp .env.example .env
php artisan key:generate
```

Open the `.env` file and configure your database connection. For SQLite (recommended for development):

```
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database/database.sqlite
```

Or for PostgreSQL:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=relaticle
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

4. **Run migrations**

```bash
php artisan migrate
```

5. **Link storage**

```bash
php artisan storage:link
```

6. **Build assets**

```bash
npm run build
```

7. **Start development services**

```bash
composer run dev
```

This starts all development services (server, queue, logs, assets) in parallel. Visit `http://localhost:8000` in your browser to access the application.

> **Note:** By default, emails are sent to the `log` driver. You can change this in the `.env` file to something like
> `mailtrap` for development.

## Development Guidelines

### Coding Standards

Relaticle follows Laravel's coding standards and conventions. We enforce these through:

- **Laravel Pint**: Ensures consistent code styling
- **Rector**: Refactors code to use modern PHP features
- **PHPStan**: Validates type correctness and prevents common issues
- **Pest**: Ensures code quality through comprehensive tests

### Quality Assurance Tools

Relaticle uses several tools to maintain code quality:

```bash
# Lint the code using Pint
composer lint
composer test:lint

# Refactor the code using Rector
composer refactor
composer test:refactor

# Run PHPStan
composer test:types

# Run the test suite
composer test:pest

# Run test architecture checks
composer test:arch

# Run type coverage checks (must be 99.9%+)
composer test:type-coverage

# Run all quality checks
composer test
```

> Pull requests that don't pass the test suite will not be merged. Always run `composer test` before submitting your
> changes.

### Git Hooks

Relaticle uses Git Hooks to automate quality checks during the development process. The hooks are located in the
`.githooks` directory, and you can enable them by running:

```bash
git config core.hooksPath .githooks
```

This will automatically run the appropriate checks when you commit or push code.

### Testing Requirements

All code contributions should include:

- Unit tests for new functionality
- Feature tests for user interactions
- Passing static analysis checks
- Minimum 99.9% type coverage

## Customization and Extension

### Adding New Features

When adding new features:

1. Start by creating the necessary models and migrations
2. Implement business logic in dedicated Service classes
3. Create Filament resources for admin panel integration
4. Add Livewire components for frontend interactions
5. Write comprehensive tests
6. Document the feature in relevant documentation

### Creating Custom Components

The Filament ecosystem makes it easy to create custom components. Here's an example of a Filament resource:

```php
namespace App\Filament\App\Resources;

use App\Filament\App\Resources\CompanyResource\Pages;
use App\Models\Company;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;
    
    // Resource configuration...
    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    
    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                // Additional form fields...
            ]);
    }
    
    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                // Additional table columns...
            ]);
    }
}
```

## Performance Considerations

- Use eager loading to prevent N+1 query issues
- Keep frontend dependencies minimal
- Optimize database indexes for common queries
- Use Laravel queues for processing background tasks
- Implement caching for expensive operations
- Consider database query optimization for large datasets
- Set up proper indexing for search functionality


## Security Best Practices

- All user input must be validated
- Follow Laravel's authentication and authorization patterns
- Implement proper CSRF protection
- Use Laravel Sanctum for API authentication
- Regularly update dependencies for security patches
- Implement rate limiting for sensitive endpoints
- Follow the principle of the least privilege for user permissions
- Properly sanitize user inputs, especially for database queries

## Deployment

### Docker Deployment (Recommended)

The easiest way to deploy Relaticle is using Docker. Pre-built images are available on GitHub Container Registry.

**Pull the image:**

```bash
# Latest stable release
docker pull ghcr.io/relaticle/relaticle:latest

# Specific version
docker pull ghcr.io/relaticle/relaticle:1.0.0

# Latest from main branch (for staging)
docker pull ghcr.io/relaticle/relaticle:main
```

**Quick start with Docker Compose:**

```bash
# Copy and configure the production compose file
cp docker-compose.prod.yml docker-compose.yml

# Create your .env file
cp .env.example .env
# Edit .env with your production settings

# Start all services
docker compose up -d
```

The Docker setup includes:
- **app** - Web server (nginx + php-fpm) on port 8080
- **horizon** - Queue processing with Laravel Horizon
- **scheduler** - Cron jobs (schedule:work)
- **postgres** - Database
- **redis** - Cache, sessions, and queues

Migrations, caching, and storage linking run automatically on container startup.

### Manual Deployment

For manual server deployment without Docker:

#### Production Deployment Checklist

1. Configure environment-specific variables
2. Set up a production database with proper credentials
3. Configure a robust caching system (Redis recommended)
4. Set up a queue worker using Supervisor
5. Configure a web server (Nginx recommended)
6. Set up SSL certificates
7. Configure proper backups
8. Set up monitoring tools

#### Deployment Process

1. Pull latest code from the repository
2. Install production dependencies: `composer install --no-dev --optimize-autoloader`
3. Build frontend assets: `npm ci && npm run build`
4. Run migrations: `php artisan migrate --force`
5. Clear and rebuild caches: `php artisan optimize`
6. Restart queue workers: `php artisan queue:restart`

#### Server Requirements

- PHP 8.4+ with required extensions
- PostgreSQL 13+ or MySQL 8.0+
- Redis (recommended for caching and queues)
- Nginx or Apache
- Supervisor (for managing queue workers)

## Troubleshooting

### Common Issues

#### Queue Worker Not Processing Jobs

```bash
# Check queue status
php artisan queue:status

# Restart queue worker
php artisan queue:restart
```

#### File Upload Issues

Ensure proper permissions on the storage directory:

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

#### Slow Database Queries

Use Laravel Telescope or Clockwork to identify slow queries, then add appropriate indexes.

#### Custom Fields Plugin Not Working

1. Verify the license is active
2. Check that the package is properly installed: `composer require relaticle/custom-fields`

#### Filament Admin Panel Loading Issues

1. Clear the view cache: `php artisan view:clear`
2. Rebuild the assets: `npm run build`
3. Check browser console for JavaScript errors

## Contributing

We welcome contributions from developers of all skill levels! Relaticle is an open-source project that thrives on community involvement.

### How to Contribute

1. **Fork the repository** on GitHub
2. **Create a feature branch**: `git checkout -b feat/amazing-feature` or `fix/your-fix`
3. **Make your changes** following our coding standards outlined in the Development Guidelines section
4. **Run tests** to ensure quality: `composer test`
5. **Commit your changes** with descriptive messages following conventional commits
6. **Push to your branch**: `git push origin feat/amazing-feature`
7. **Create a Pull Request** and explain your changes clearly

### Types of Contributions

- **Bug fixes** - Help us improve stability
- **Feature additions** - Implement new capabilities
- **Documentation improvements** - Enhance guides and examples
- **Test coverage** - Add or improve tests
- **UI/UX enhancements** - Improve the user experience

### Code of Conduct

We strive to maintain a welcoming and inclusive environment for all contributors. Please be respectful in all interactions and focus on constructive feedback.

Remember that all contributions to Relaticle are subject to review and must align with the project's goals and quality standards. Working closely with maintainers will help ensure your contribution is accepted and merged efficiently.

## Additional Resources

- [Laravel Documentation](https://laravel.com/docs/12.x)
- [Filament Documentation](https://filamentphp.com/docs)
- [Livewire Documentation](https://livewire.laravel.com/)
- [Pest Testing Framework](https://pestphp.com/)
- [PostgreSQL Documentation](https://www.postgresql.org/docs/)
- [Tailwind CSS Documentation](https://tailwindcss.com/docs) 
