# Project

This is production code for a commercial SaaS product with paying customers.
Bugs directly impact revenue and user trust.

Treat every change like it's going through senior code review:

- No lazy shortcuts or placeholder code
- Handle errors and edge cases properly
- Write code that won't embarrass you in 6 months

## Database

- This project uses **PostgreSQL exclusively**. Do not add SQLite/MySQL compatibility layers, driver checks, or conditional SQL
- Migrations must only have `up()` methods. Never write a `down()` method
- Every datetime column is `timestamp without time zone` holding **UTC**. Never write one from
  the database clock. That rules out `DB::raw('now()')`, `CURRENT_TIMESTAMP`, and `->useCurrent()` /
  `->useCurrentOnUpdate()` column defaults. Those resolve against the *session* timezone and
  write local wall-clock into a UTC column. Pass a PHP-side `now()` instead:
  `->update(['used_at' => now()])`. The pgsql connection pins `'timezone' => 'UTC'` so the two
  agree today. Do not rely on that. It is the safety net, not the contract.

## Dates

- Dates are immutable application-wide. `AppServiceProvider::register()` calls
  `Date::use(CarbonImmutable::class)`, so `now()`, `today()`, the `Date` facade, and
  every `datetime` cast return `CarbonImmutable`
- Never name the mutable `Carbon` class in code. `CarbonImmutable` does not extend it,
  so a type hint becomes a TypeError and an `instanceof` check silently turns false.
  `tests/Arch/ConventionsTest.php` fails on a bare `Carbon` anywhere in `app/`,
  `packages/`, `database/`, or `tests/`
- Type a date as `CarbonImmutable` when our own `Date::` factory or a model cast
  produced it. Use `CarbonInterface` when a vendor may still hand you a mutable date
- Build dates through `now()`, `today()`, or the `Date` facade. A hardcoded `Carbon::`
  static call bypasses the factory, and `CarbonToDateFacadeRector` rewrites it
- Steer the clock in tests with `$this->travelTo()`. `Carbon::setTestNow()` names the
  mutable class, so `CarbonSetTestNowToTravelToRector` rewrites it

## Pre-Commit Quality Checks

Before committing any changes, always run these checks in order:

1. `vendor/bin/pint --dirty --format agent`: fix code style
2. `vendor/bin/rector --dry-run`: if rector suggests changes, apply them with `vendor/bin/rector`
3. `vendor/bin/phpstan analyse`: ensure no new static analysis errors
4. `composer test:type-coverage`: type coverage must stay at 100%
5. `php artisan test --compact`: run relevant tests (use `--filter` for targeted runs)

`--dirty` only covers files with uncommitted changes, so a file you committed
earlier in the branch stops being checked and its style break surfaces only in
CI. Before pushing, run what CI runs: `composer test:lint` (`pint --test
--parallel`, whole repo).

Do not add new PHPStan ignores without approval. All parameters and return types must be explicitly typed. Untyped closures and parameters fail type coverage in CI.

## Fixing & Verification

- Never change production code solely to make a test or CI pass. A failing check
  means one of: production bug, wrong assertion, or test-state leak. Diagnose
  which first, then fix at that layer. A production behavior change must be
  justified on its own merits and covered by its own dedicated test.
- After any fix, re-run the original failing repro (test, browser flow, query)
  and show the new output before claiming it is fixed. "Should work now" is not done.
- Before reporting an investigation or cleanup complete, do a second independent
  verification pass: re-grep all references, re-run the checks, re-walk the repro.
- Debug production errors by reproducing them locally first (failing test, seeded
  data, or browser repro with the real queue). Production access (Tinkerwell/SSH)
  is for short read-only queries that capture the failing payload or state.
  It is never the iteration loop.
- When a failing operation has a working sibling (approve vs reject, one entity
  type vs another), diff the two code paths first. It is the fastest localizer.

## Minimal Change

- Default to the smallest change that satisfies the requirement. Every new file,
  script, DB column, or abstraction must be justified by an explicit need. When
  in doubt, leave it out and propose it instead.
- Internal contracts (chat tool schemas, action signatures, internal APIs) have
  no external consumers. When extending one, migrate all callers in the same
  change. Never leave deprecated parameters, fallbacks, or dual old/new paths.
- Environment-specific developer data belongs in `database/seeders/LocalSeeder.php`.
  Never put it behind an `app()->environment()` branch inside `app/Actions/` or
  other production code.

## Comments

Write code that needs no comment. In a finished diff, 90%+ of the code carries zero
comments: names, small methods, and a test named for the behaviour say it all. A comment
is the exception that admits the code could not.

- A comment states only what code cannot: a non-obvious *why*, a magic value's source, or
  a warning against a refactor that looks safe. Never what the code does.
- Cap it at 2 lines. Longer rationale belongs in the PR body or the commit, not the file.
- Never narrate the diff (`// added to fix X`), argue it (*without this*, *otherwise*,
  *this ensures*), or carry traceability (ticket IDs, criterion tags). The reviewer reads
  the PR; the next reader reads the code.
- No comments in tests. The test name carries the intent.
- Docblocks carry types, generics, and array shapes PHPStan cannot infer. Never prose.
  This overrides the composed Boost PHP rule that prefers docblocks over inline comments.
- Draft with comments if it helps you think. Before handing over the diff, re-read every
  `//` you added and delete any the code already says.

## Scheduling

- All scheduled commands go in `bootstrap/app.php` via `withSchedule()`, not in `routes/console.php`
