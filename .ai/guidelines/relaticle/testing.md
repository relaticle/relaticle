# Testing

The suite follows the Testing Trophy. Every test file must live inside one of
these directories. They are the phpunit testsuites, and
`tests/Arch/TestSuiteIntegrityTest.php` fails if a `*Test.php` exists anywhere
else (files outside a declared suite silently never run):

| Layer | Directory | Scope |
|---|---|---|
| Architecture | `tests/Arch/` | structural rules, module boundaries |
| PHPStan rules | `tests/PHPStan/` | tests for the custom static-analysis rules |
| Smoke | `tests/Smoke/` | HTTP-level route smoke |
| Workflow | `tests/Feature/` | the bulk of the suite, through real entry points |
| Browser | `tests/Browser/` | critical paths only |

There is deliberately **no `tests/Unit/` suite**. Do not create new top-level
test directories; if one is ever needed, declare it in BOTH `phpunit.xml` and
`phpunit.ci.xml`. `TestSuiteIntegrityTest` enforces that the two stay in sync.

## Rules

- Do not write isolated unit tests for action classes, services, enums, or other
  internal code. Test them through their real entry points (API endpoints,
  Filament resources, Livewire components). Isolated unit tests of internals
  create maintenance burden without catching real bugs.
- Never weaken an assertion, delete a test, or special-case production code just
  to turn the suite green. If a test asserts a stale value, fix the assertion;
  if state leaks between tests, fix isolation in the test layer. Never push
  compensation into production code.
- Never write tests that assert on source code as text (reading a Blade/PHP file
  and checking it contains a string). They break on refactors and pass on broken
  behavior. Test the rendered/runtime behavior instead.
- Do not write tests for migrations, schema changes and backfills included. Rehearse them on
  anonymized production data instead, as the Database section of `core.md` describes.
  `tests/Arch/ConventionsTest.php` fails when a test outside `tests/Arch/` loads a migration file.
- `tests/Pest.php` binds `TestCase` + `LazilyRefreshDatabase` for the Feature,
  Smoke, and Browser suites. Don't repeat `uses(...)` per file there.
- Use `mutates(ClassName::class)` in test files to declare which source classes
  each test covers
- Run mutation testing per-class as a code-review tool (no CI gate):
  `php -d xdebug.mode=coverage vendor/bin/pest --mutate --class='App\MyClass' tests/path/`
- Use `$this->travelTo()` in tests that depend on day-of-week or weekly intervals
  to avoid flaky boundary failures
- Match test organization to existing conventions: before creating a test file,
  search `tests/` for files covering the same class or feature and extend those
- A negated arch expectation covers one layer: `expect($layer)->not->toUse(...)` inside a
  `foreach`. Pest fails `expect([$a, $b])->not->toUse(...)` only when every layer violates
  at once. Two module-boundary checks passed that way with 20 violations behind them.
  `tests/Arch/ConventionsTest.php` fails the multi-layer form. Plant a violation to prove a
  new arch check before trusting it

## Running the suite

- The normal local run is `composer test:affected`. Pest reads the baseline the
  `TIA Baseline` workflow records and lists the test files the branch's changes reach.
  `bin/test-affected.sh` runs them with TIA off on four processes, because a TIA run
  records coverage at 2.5 times the CPU. A seeded bug in `CreateOpportunity` broke 28
  tests in 9 files. This run caught all 28 in 50s. The files `grep` picked caught 19.
  The Quality Checks section of `core.md` lists the whole loop.
- The command refuses past 60 files: the change reaches most of the suite, or no
  baseline matches the checkout. A change to `composer.lock`, `phpunit.xml`,
  `pnpm-lock.yaml` or `vite.config.js` voids the baseline until the workflow records on
  it. Then the run is scoped by hand: `php artisan test --compact <paths>` over the test
  files you touched and the tests that exercise the classes you changed.
- The command names the files coverage cannot place: `config/`, `routes/`, `bootstrap/`,
  JavaScript and CSS. Pick their tests by hand. Coverage also records no edge for a file
  a test reads from disk, so `PestTiaRuntime::FILES_ARCH_TESTS_READ` maps those to
  `tests/Arch`. A new test that reads a file adds its path there.
- `pestphp/pest` installs from the `ManukMinasyan/pest` fork, branch
  `tia-warmup-worktrees`. Stock Pest cannot fetch a baseline inside a git worktree
  (pestphp/pest#1820) and has no `trustDefaultBranch()`. Return to `^5.0` when both ship.
- A hand-picked run over more than one file goes parallel on four processes:
  `php artisan test --compact tests/Feature/Api/V1 --parallel --processes=4`. Those 15
  files take 73s in one process and 28s in four. A parallel run accepts one path. For
  several files, pass the directory they share and name them:
  `--filter='(NotesApiTest|TasksApiTest)::'`. Keep the directory, because the same filter
  over the whole suite loads every test file and gains nothing. One file stays in one
  process: each worker migrates its own database first. Four processes beat one per core
  (33s) while other workspaces share the machine.
- The merge gate is the `Tests` workflow on GitHub, which runs the complete suite on
  every push to a pull request. After a push, watch it as a background task. Do not run
  the complete suite locally to confirm a push.
- `composer test:pest` (parallel, TIA enabled, excludes Browser) and
  `composer test:pest:full` (non-TIA) stay available for reproducing a CI failure that
  a scoped run cannot, and for recording a TIA graph. TIA replays a cached pass whenever
  a test's edges are unchanged, so it cannot see time-dependent failures (`travelTo`,
  expiring tokens), `.env` edits, or dynamic dispatch it did not trace while recording.
- After changing test timings materially, refresh the CI shard balance with
  `composer test:update-shards` and commit `tests/.pest/shards.json`; a stale
  file silently drops new test classes out of time-balancing.
