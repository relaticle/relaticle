# Project

This is production code for a commercial SaaS product with paying customers.
Bugs directly impact revenue and user trust.

Treat every change like it's going through senior code review:

- No lazy shortcuts or placeholder code
- Handle errors and edge cases properly
- Write code that won't embarrass you in 6 months

A rule that cannot name the artifact failing when you break it is decoration. Every
rule here names a class, a test, or a command, because the one abstract rule this file
used to carry ("never store the same fact in two places") was in force for the three
months two copies of the same field vocabulary drifted apart.

## Database

- This project uses **PostgreSQL exclusively**. Do not add SQLite/MySQL compatibility layers, driver checks, or conditional SQL
- Migrations must only have `up()` methods. Never write a `down()` method
- Prove a migration by rehearsing it on anonymized production data, never with a test. A test
  seeds the rows its author imagined. Production holds the rest: `creation_source = 'system'`
  meant both seeded samples and mailbox-synced contacts, which no fixture mixed. The rehearsal:
  1. Export read-only from production: `pg_dump -s` for the schema, `pg_dump -a -t migrations`
     so only the new migration is pending, and `\copy` of every table the migration reads.
     Replace personal columns in the export query (names, emails, bodies, free-text JSON values)
  2. Load the export into a scratch database. Tables loaded without their parents need
     `set session_replication_role = replica`. A migration that inserts needs a stub parent row
     for every enforced foreign key, because `migrate` runs with the checks on. Save a
     before-state query of the rows in scope
  3. Run `DB_DATABASE=<scratch> php artisan migrate --force` and confirm only the new migration
     ran. Diff the after-state against the before-state, row counts and the rows it must leave alone
  4. Run `migrate` again to prove it is a no-op, then drop the scratch database and delete the export
- A data backfill the query builder can express belongs in the migration, chunked with
  `eachById`: no models, no file access, no app code. This is the only shape that reaches a
  self-hosted install unaided. `2026_09_10_000000_convert_markdown_editor_custom_fields_to_rich_editor`
  is the worked example, and Spatie ships the same shape in `laravel-activitylog` UPGRADING.md
- A backfill that needs models, files, or another service is a command instead: it reports by
  default, writes only on `--force`, and re-runs after a partial failure. A migration can do
  none of that, because we never write `down()`
- A self-hosted upgrade is `docker compose pull && up -d`: migrations run, nothing else, so no
  command of ours ever runs there. Ship a change of storage shape behind a read-path shim that
  keeps the old shape working (`RichContentAttachments::getFileAttachmentUrl()` still serves a
  legacy bare-filename `data-id`), then queue the command from a migration, as
  `2026_09_15_150837_queue_rich_editor_attachment_backfill` does:
  `Artisan::queue($command, ['--force' => true])->onQueue('imports')->delay(now()->addMinutes(5))->afterCommit()`.
  Pgsql wraps every migration in a transaction, so without `afterCommit` a worker can start before
  the DDL lands. The delay holds the job until the deploy has restarted Horizon: a worker booted
  before the deploy does not know a new command, and on 2026-09-24 one failed
  `media:purge-unsafe-images` on both tries, so the purge never ran in production. `imports` is the long lane (300s, 2 tries, 256MB) where `default` allows 60s and one
  try, and `QUEUE_CONNECTION=sync` runs the command inline, so it stays chunked and idempotent
  either way. Never `Artisan::call()` in `up()`: the container entrypoint runs under `set -e`, so
  a throw there crash-loops the app and takes Horizon down with it. `tests/Arch/ConventionsTest.php`
  fails when a migration names a command that no longer exists, or queues one without a delay
- A migration that drops a column queued jobs still read or write ships with a deploy step:
  `php artisan horizon:pause` before `migrate`, `php artisan horizon:terminate` after. Otherwise a
  job still running the old code hits the missing column and fails with no retry.
  `2026_09_24_100100_backfill_agent_conversation_message_steps` is the precedent. A self-hosted
  upgrade needs nothing extra, because `up -d` stops the old container before migrating
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

## Quality Checks

The local loop is scoped to the change. GitHub CI (`.github/workflows/ci.yml`) is the
only full run: it executes lint, rector, PHPStan, type coverage, five test shards and
six Browser shards on every push to a pull request. A run takes about 4 minutes when
runners are free, and longer when several runs queue for them.

After each change, while iterating:

1. `vendor/bin/pint --dirty --format agent`: fix code style
2. `composer test:affected`: the tests the branch's changes reach, picked from the TIA
   baseline and run on four processes. `-- --plan` lists them and runs nothing. When it
   refuses, or names a file it cannot place, run `php artisan test --compact <paths>`
   over the test files you touched and the tests that exercise the classes you changed
   (`grep -rl 'ClassName' tests`). The Running the suite section of `testing.md` has
   both forms

Once, before pushing:

3. `vendor/bin/rector --dry-run`: if rector suggests changes, apply them with `vendor/bin/rector`
4. `vendor/bin/phpstan analyse`: ensure no new static analysis errors
5. `composer test:lint`: `--dirty` only covers uncommitted files, so a file committed
   earlier in the branch is checked here (`pint --test --parallel`, whole repo)
6. `composer test:arch`: `ArchTest` and `ConventionsTest` fail CI on more branches than
   any other test class, and the Arch suite runs in 30 seconds

After a push, open the pull request if the branch has none, and watch the `Tests`
workflow as a background task:
`gh run watch --exit-status $(gh run list --branch <branch> --workflow Tests --limit 1
--json databaseId --jq '.[0].databaseId')`. Never a `sleep` loop. Fix what it reports
and push again.

Never run `composer test:pest`, `composer test:pest:full`, `composer test:type-coverage`
or `composer test:browser` locally to confirm a commit or a push. CI runs all four on the
pushed commit, and a local run slows every other workspace on the machine: the full suite
takes 116s alone and 514s beside three other heavy jobs. Run one locally only to reproduce
a CI failure, scoped to the failing file.

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

## Code shape

The reference is the framework's own code and Spatie's packages. Measured on the installed
versions, nine of their methods in ten are under about 20 lines, and ninety-nine in a hundred
are under about 50. Each rule below names what fails when it is broken.

- A method stays within 60 lines. `MethodLengthRule` (PHPStan, `app/PHPStan/Rules/`) fails a
  longer one. Extract a step and name it for what it returns. `packages/SystemAdmin` is outside
  PHPStan, so the cap does not reach it.
- The methods that were already longer are listed in `phpstan-method-length.php`, and that list
  only shrinks. A listed method that grows fails. One that shrinks has its entry lowered, and one
  that fits has it removed. `tests/Arch/ConventionsTest.php` fails an entry whose method is gone.
- A class is named for its role where its directory carries one: `Command`, `Controller`,
  `Request`, `Resource`, `Mail`, `Observer`, `Policy`, `Tool`, and `Filter` and `Sort` under
  `app/Queries`. `tests/Arch/ConventionsTest.php` fails a class there without the suffix.
- Code reaches the network, the shell, and a wait through `Http`, `Process`, and `Sleep`. A test
  can fake each of them, and nothing can fake the raw call. `tests/Arch/ArchTest.php` fails a
  direct Guzzle client, `curl_*`, Symfony `Process` or `HttpClient`, `sleep()`, and `usleep()`.
- Before writing a helper, look for it in PHP, then in the framework, then in a package from
  `composer.json`, then in this codebase. Rector's Laravel sets rewrite the hand-rolled forms
  they know, and `composer test:refactor` fails until the rewrite is taken.
- A model or a job states its configuration as PHP attributes: `#[Fillable]`, `#[Table]`,
  `#[Unguarded]`, `#[Tries]`, `#[Timeout]`, `#[Backoff]`, `#[UniqueFor]`. Rector rewrites the
  property form, and `composer test:refactor` fails until the rewrite is taken. Code that needs
  the number at runtime reads a class constant the attribute also uses, as `SendEmailJob` does
  with `TIMEOUT_SECONDS`. `$this->timeout` no longer exists once the attribute replaces it.
- An accessor is `Attribute::get()` with a typed closure and a `@return Attribute<TGet, never>`
  docblock. Larastan reads the property type from that docblock, so PHPStan reports the
  attribute as an undefined property without it.

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
- Draft with comments if it helps you think. Before handing over the diff, re-read every
  `//` you added and delete any the code already says.

## Scheduling

- All scheduled commands go in `bootstrap/app.php` via `withSchedule()`, not in `routes/console.php`
