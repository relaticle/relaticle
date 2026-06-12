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
