---
paths:
  - 'tests/**'
---

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

## Browser tests

- Browser assertions (`assertCount`, `assertScript`, `assertSeeIn`, ...) auto-retry via
  `AwaitableWebpage::__call` for the whole Playwright timeout, so hand-rolled usleep poll
  loops are normally redundant, EXCEPT when the assertion is waiting on a Livewire/fetch
  round-trip fired immediately after another one: the retry loop hammers the CDP bridge
  with no sleep and the in-process test server can starve, never finishing the second
  request (verified 2026-08-22, TranscriptShapeTest back-to-back load-earlier: 4/4
  deterministic). In that case keep a `usleep(100_000)` poll loop with a comment naming
  this rule; the quiet windows let the shared event loop serve the pending request.
