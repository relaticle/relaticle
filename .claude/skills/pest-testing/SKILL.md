---
name: pest-testing
description: "Use this skill for Pest PHP testing in Laravel projects only. Trigger whenever any test is being written, edited, fixed, or refactored — including fixing tests that broke after a code change, adding assertions, converting PHPUnit to Pest, adding datasets, and TDD workflows. Always activate when the user asks how to write something in Pest, mentions test files or directories (tests/Feature, tests/Unit, tests/Browser), or needs browser testing, smoke testing multiple pages for JS errors, architecture tests, or faster test runs with Test Impact Analysis. Covers: test()/it()/expect() syntax, datasets, mocking, browser testing (visit/click/fill), smoke testing, arch(), Livewire component tests, RefreshDatabase, Tia (--tia), sharding, and all Pest 5 features. Do not use for factories, seeders, migrations, controllers, models, or non-test PHP code."
license: MIT
metadata:
  author: laravel
---

# Pest Testing 5

## Documentation

Use `search-docs` for detailed Pest 5 patterns and documentation.

## Basic Usage

### Creating Tests

All tests must be written using Pest. Use `php artisan make:test --pest {name}`.

The `{name}` argument should include only the path and test name, but should not include the test suite.
- Incorrect: `php artisan make:test --pest Feature/SomeFeatureTest` will generate `tests/Feature/Feature/SomeFeatureTest.php`
- Correct: `php artisan make:test --pest SomeControllerTest` will generate `tests/Feature/SomeControllerTest.php`
- Incorrect: `php artisan make:test --pest --unit Unit/SomeServiceTest` will generate `tests/Unit/Unit/SomeServiceTest.php`
- Correct: `php artisan make:test --pest --unit SomeServiceTest` will generate `tests/Unit/SomeServiceTest.php`

### Test Organization

- Unit/Feature tests: `tests/Feature` and `tests/Unit` directories.
- Browser tests: `tests/Browser/` directory.
- Do NOT remove tests without approval - these are core application code.

### Basic Test Structure

Pest supports both `test()` and `it()` functions. Before writing new tests, check existing test files in the same directory to match the project's convention. Use `test()` if existing tests use `test()`, or `it()` if they use `it()`.

<!-- Basic Pest Test Example -->
```php
it('is true', function () {
    expect(true)->toBeTrue();
});
```

### Running Tests

- Run minimal tests with filter before finalizing: `php artisan test --compact --filter=testName`.
- Run all tests: `php artisan test --compact`.
- Run file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- Run only tests affected by recent changes (Tia): `./vendor/bin/pest --parallel --tia`.

## Assertions

Use specific assertions (`assertSuccessful()`, `assertNotFound()`) instead of `assertStatus()`:

<!-- Pest Response Assertion -->
```php
it('returns all', function () {
    $this->postJson('/api/docs', [])->assertSuccessful();
});
```

| Use | Instead of |
|-----|------------|
| `assertSuccessful()` | `assertStatus(200)` |
| `assertNotFound()` | `assertStatus(404)` |
| `assertForbidden()` | `assertStatus(403)` |

## Mocking

Import mock function before use: `use function Pest\Laravel\mock;`

## Datasets

Use datasets for repetitive tests (validation rules, etc.):

<!-- Pest Dataset Example -->
```php
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
```

## Pest 5 Features

| Feature | Purpose |
|---------|---------|
| Tia (Test Impact Analysis) | Rerun only tests affected by recent changes |
| Time-Balanced Sharding | Split tests across CI shards by execution time |
| New Validation Expectations | `toBeEmail()`, `toBeUlid()`, `toBeIpAddress()`, and more |
| Browser Testing | Full integration tests in real browsers |
| Smoke Testing | Validate multiple pages quickly |
| Visual Regression | Compare screenshots for visual changes |
| Architecture Testing | Enforce code conventions |

### Tia (Test Impact Analysis)

Tia reruns only tests affected by recent changes and replays cached results for the rest, dramatically reducing suite duration:

<!-- Tia Example -->
```shell
./vendor/bin/pest --parallel --tia
```

- Replayed tests are not skipped — cached tests store everything they produced, including covered lines and branches.
- Detects Laravel, Symfony, Livewire, and Inertia automatically.

### New Validation Expectations

Pest 5 ships eight new validation matchers, all supporting `.not` negation:

<!-- Pest 5 Validation Expectations -->
```php
expect('nuno@pestphp.com')->toBeEmail();
expect('01ARZ3NDEKTSV4RRFFQ69G5FAV')->toBeUlid();
expect('192.168.1.1')->toBeIpAddress();
expect('00:1a:2b:3c:4d:5e')->toBeMacAddress();
expect('example.com')->toBeHostname();
expect('example.co.uk')->toBeDomain();
expect('Zm9vYmFy')->toBeBase64();
expect('deadbeef')->toBeHexadecimal();
```

### Time-Balanced Sharding

Distribute tests across CI shards by execution time rather than count:

<!-- Pest Sharding Example -->
```shell
./vendor/bin/pest --update-shards
./vendor/bin/pest --shard=1/4
```

Commit `tests/.pest/shards.json` to the repository so CI shards stay consistent.

### Browser Test Example

Browser tests run in real browsers for full integration testing:

- Browser tests live in `tests/Browser/`.
- Use Laravel features like `Event::fake()`, `assertAuthenticated()`, and model factories.
- Use `RefreshDatabase` for clean state per test.
- Interact with page: click, type, scroll, select, submit, drag-and-drop, touch gestures.
- Test on multiple browsers (Chrome, Firefox, Safari) if requested.
- Test on different devices/viewports (iPhone 14 Pro, tablets) if requested.
- Switch color schemes (light/dark mode) when appropriate.
- Take screenshots or pause tests for debugging.

<!-- Pest Browser Test Example -->
```php
it('may reset the password', function () {
    Notification::fake();

    $this->actingAs(User::factory()->create());

    $page = visit('/sign-in');

    $page->assertSee('Sign In')
        ->assertNoJavaScriptErrors()
        ->click('Forgot Password?')
        ->fill('email', 'nuno@laravel.com')
        ->click('Send Reset Link')
        ->assertSee('We have emailed your password reset link!');

    Notification::assertSent(ResetPassword::class);
});
```

### Smoke Testing

Quickly validate multiple pages have no JavaScript errors:

<!-- Pest Smoke Testing Example -->
```php
$pages = visit(['/', '/about', '/contact']);

$pages->assertNoJavaScriptErrors()->assertNoConsoleLogs();
```

### Visual Regression Testing

Capture and compare screenshots to detect visual changes.

### Architecture Testing

<!-- Architecture Test Example -->
```php
arch('controllers')
    ->expect('App\Http\Controllers')
    ->toExtendNothing()
    ->toHaveSuffix('Controller');
```

## Common Pitfalls

- Not importing `use function Pest\Laravel\mock;` before using mock
- Using `assertStatus(200)` instead of `assertSuccessful()`
- Forgetting datasets for repetitive validation tests
- Deleting tests without approval
- Forgetting `assertNoJavaScriptErrors()` in browser tests
- Prefixing `Feature/` or `Unit/` in `{name}` when using `make:test`
