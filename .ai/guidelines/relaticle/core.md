# Project

This is production code for a commercial SaaS product with paying customers.
Bugs directly impact revenue and user trust.

Treat every change like it's going through senior code review:

- No lazy shortcuts or placeholder code
- Handle errors and edge cases properly
- Write code that won't embarrass you in 6 months

## Database

- This project uses **PostgreSQL exclusively** — do not add SQLite/MySQL compatibility layers, driver checks, or conditional SQL
- Migrations must only have `up()` methods — do not write `down()` methods

## Pre-Commit Quality Checks

Before committing any changes, always run these checks in order:

1. `vendor/bin/pint --dirty --format agent` — fix code style
2. `vendor/bin/rector --dry-run` — if rector suggests changes, apply them with `vendor/bin/rector`
3. `vendor/bin/phpstan analyse` — ensure no new static analysis errors
4. `composer test:type-coverage` — type coverage must stay at 100%
5. `php artisan test --compact` — run relevant tests (use `--filter` for targeted runs)

Do not add new PHPStan ignores without approval. All parameters and return types must be explicitly typed — untyped closures/parameters will fail type coverage in CI.

## Scheduling

- All scheduled commands go in `bootstrap/app.php` via `withSchedule()` — not in `routes/console.php`
