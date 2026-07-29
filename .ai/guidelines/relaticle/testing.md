# Testing

The suite follows the Testing Trophy. Every test file must live inside one of
these directories — they are the phpunit testsuites, and
`tests/Arch/TestSuiteIntegrityTest.php` fails if a `*Test.php` exists anywhere
else (files outside a declared suite silently never run):

| Layer | Directory | Scope |
|---|---|---|
| Architecture | `tests/Arch/` | structural rules, module boundaries |
| PHPStan rules | `tests/PHPStan/` | tests for the custom static-analysis rules |
| Smoke | `tests/Smoke/` | HTTP-level route smoke |
| Workflow | `tests/Feature/` | the bulk of the suite — test through real entry points |
| Browser | `tests/Browser/` | critical paths only |

There is deliberately **no `tests/Unit/` suite**. Do not create new top-level
test directories; if one is ever needed, declare it in BOTH `phpunit.xml` and
`phpunit.ci.xml` (kept in sync — also enforced by `TestSuiteIntegrityTest`).

## Rules

- Do not write isolated unit tests for action classes, services, enums, or other
  internal code — test them through their real entry points (API endpoints,
  Filament resources, Livewire components). Isolated unit tests of internals
  create maintenance burden without catching real bugs.
- Never weaken an assertion, delete a test, or special-case production code just
  to turn the suite green. If a test asserts a stale value, fix the assertion;
  if state leaks between tests, fix isolation in the test layer — don't push
  compensation into production code.
- Never write tests that assert on source code as text (reading a Blade/PHP file
  and checking it contains a string). They break on refactors and pass on broken
  behavior — test the rendered/runtime behavior instead.
- `tests/Pest.php` binds `TestCase` + `LazilyRefreshDatabase` for the Feature,
  Smoke, and Browser suites — don't repeat `uses(...)` per file there.
- Use `mutates(ClassName::class)` in test files to declare which source classes
  each test covers
- Run mutation testing per-class as a code-review tool (no CI gate):
  `php -d xdebug.mode=coverage vendor/bin/pest --mutate --class='App\MyClass' tests/path/`
- Use `$this->travelTo()` in tests that depend on day-of-week or weekly intervals
  to avoid flaky boundary failures
- Match test organization to existing conventions: before creating a test file,
  search `tests/` for files covering the same class or feature and extend those

## Running the suite

- `composer test:pest` — the normal local run (parallel, excludes Browser).
- `composer test:pest:tia` — Pest's **experimental** test-impact-analysis mode.
  Records a coverage-backed dependency graph once (~3x a normal run), then
  replays unaffected tests instead of executing them, so a no-op run drops from
  ~3 minutes to a few seconds. **Local accelerator only — never a merge gate.**
  It replays a cached *pass* whenever a test's edges are unchanged, so it cannot
  see time-dependent failures (`travelTo`, expiring tokens), `.env` edits, or
  dynamic dispatch it did not trace while recording. Always confirm with
  `composer test:pest` before pushing.
- After changing test timings materially, refresh the CI shard balance with
  `composer test:update-shards` and commit `tests/.pest/shards.json`; a stale
  file silently drops new test classes out of time-balancing.
