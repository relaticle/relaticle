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

## Running the suite

- `composer test:pest` is the normal local run (parallel, TIA enabled, excludes
  Browser). Pest records a coverage-backed dependency graph once, then replays
  unaffected tests instead of executing them.
- `composer test:pest:full` is the complete non-TIA merge gate.
- TIA is a local accelerator only. It replays a cached pass whenever a test's
  edges are unchanged, so it cannot see time-dependent failures (`travelTo`,
  expiring tokens), `.env` edits, or dynamic dispatch it did not trace while
  recording. Always confirm with `composer test:pest:full` before pushing.
- After changing test timings materially, refresh the CI shard balance with
  `composer test:update-shards` and commit `tests/.pest/shards.json`; a stale
  file silently drops new test classes out of time-balancing.
