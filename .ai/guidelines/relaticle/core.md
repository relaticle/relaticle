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

## Fixing & Verification

- Never change production code solely to make a test or CI pass. A failing check
  means one of: production bug, wrong assertion, or test-state leak — diagnose
  which first, then fix at that layer. A production behavior change must be
  justified on its own merits and covered by its own dedicated test.
- After any fix, re-run the original failing repro (test, browser flow, query)
  and show the new output before claiming it is fixed. "Should work now" is not done.
- Before reporting an investigation or cleanup complete, do a second independent
  verification pass: re-grep all references, re-run the checks, re-walk the repro.
- Debug production errors by reproducing them locally first (failing test, seeded
  data, or browser repro with the real queue). Production access (Tinkerwell/SSH)
  is for short read-only queries that capture the failing payload or state —
  never the iteration loop.
- When a failing operation has a working sibling (approve vs reject, one entity
  type vs another), diff the two code paths first — it is the fastest localizer.

## Minimal Change

- Default to the smallest change that satisfies the requirement. Every new file,
  script, DB column, or abstraction must be justified by an explicit need — when
  in doubt, leave it out and propose it instead.
- Internal contracts (chat tool schemas, action signatures, internal APIs) have
  no external consumers — when extending one, migrate all callers in the same
  change. Never leave deprecated parameters, fallbacks, or dual old/new paths.
- Environment-specific developer data belongs in `database/seeders/LocalSeeder.php` —
  never as `app()->environment()` branches inside `app/Actions/` or other
  production code.

## Scheduling

- All scheduled commands go in `bootstrap/app.php` via `withSchedule()` — not in `routes/console.php`
