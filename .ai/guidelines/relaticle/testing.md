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
  `tia-warmup-worktrees-5x`: the fork's commits rebased onto upstream `5.x`. Stock Pest
  cannot fetch a baseline inside a git worktree (pestphp/pest#1914, still open) and has no
  `trustDefaultBranch()`. Return to `^5.0` when both ship.
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
- Every run's `Record Shard Timings` job rebuilds `tests/.pest/shards.json` from the JUnit
  reports of its test and Browser shards, red shards included. A cancelled run records nothing.
  The next run restores the newest recording from its own branch, then from main, before Pest
  splits the suite. A class with no recording yet is dealt round-robin, so a new test class is
  placed by time from the second run of its pull request.
  `tests/.pest/shards.json` is only the fallback for a run that finds no recording, so do not
  refresh it with a local `composer test:update-shards`: a laptop's timings skew the split.
  Refresh it with `php bin/shard-timings.php <dir>` over a main run's `junit-*` artifacts.
- `composer test:evals` runs the forty recorded asks in `tests/Evals` through the real assistant on
  real models, and scores them. It calls Anthropic and OpenAI, so it costs money and needs both
  keys in the process env or `.env`. A plain `pest` skips every case in seconds, and CI's `Tests`
  workflow never runs it. The nightly `rela-evals.yml` workflow does, one job per model, and
  uploads `storage/evals/*.json`. Committed baselines live in `tests/Evals/baselines/`, and its
  README lists each one.

## Keeping the suite fast

Every job of a pull request's `Tests` run finishes in under 3 minutes. A job takes its setup,
about 35s for a test shard and 75s for a Browser shard, plus its slowest lane. CI splits the
suite by class over nine test shards of four processes and eight Browser shards of three, and a
class runs in one process. One 379s class once held its shard at 451s while the other shards
finished in 150s.

- A test class stays under 130s in CI and a Browser class under 90s. The `Record Shard Timings`
  job fails a class past that: `php bin/shard-timings.php --over-budget`. Split the file. Move
  tests to a second file in the same directory and keep every name. `FilterBarTest` became four
  files that way.
- Helpers that two test files share are plain functions in `tests/Helpers/functions/`, and a
  dataset they share sits in a `Datasets` directory beside the tests. Pest loads both at boot. A
  function declared in two test files is a fatal error in any run that loads both.
- A Browser test signs in with `visitAs($user, $path, $width, $height)`, which acts as the user
  and opens the page at that size. The login form cost 0.71s a test in CI, 510s over the suite.
  `tests/Arch/ConventionsTest.php` fails a file under `tests/Browser` that calls
  `loginViaBrowser()`, outside `Auth`, `Onboarding` and the files that test lists.
- The browser plugin retries every page call in one-second attempts until its 30s timeout
  (`AwaitableWebpage::__call`). A `script()` whose promise takes over a second never finishes
  inside an attempt, so it runs about thirty times and returns 30 seconds late. Wait in PHP, then
  read: `$page->wait(1.2)->script($read)`. Two tests in
  `tests/Browser/CRM/FilterBarChipTypesTest.php` ran 64s and 34s before that change and 6s and 3s
  after. No test reads for this, so the class budget is what catches it.
- Every dataset case of a page test mounts the page again: about 0.6s in a test shard and 3s in
  a Browser shard. Give a case its own row when its failure needs its own name. Otherwise loop
  inside one test. No test reads for this.
- A static that outlives the application is reset in `Tests\TestCase::createApplication()`.
  Livewire's back-button cache flag is the example: a component that threw left it set, and the
  next test's response carried no-cache headers. Run the `ProposalPlanCardTest` case that refuses
  a client-set editing target before `tests/Feature/Media/PrivateDiskTest.php` in one process,
  and the second fails without the reset.
- To find where a slow run went, download its `junit-*` artifacts and sum `time` per class. The
  shard that holds the largest class is the slow one. A job's step timings show its setup.
