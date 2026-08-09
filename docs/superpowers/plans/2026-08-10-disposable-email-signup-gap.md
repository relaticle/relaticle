# Disposable-Email Signup Gap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Block disposable-email registrations across all three caller-supplied-email paths, add a bot check to registration, and remove a dead public endpoint that returns 500.

**Architecture:** One shared rule class (`App\Rules\RegistrableEmail`) becomes the single source of truth for email policy and is applied at the Filament register page, social signup, and team invitations. Cloudflare Turnstile — already installed and configured for the contact form — is wired into the Filament register page through a `ViewField`, active only when a site key is configured. Fortify's unbound registration feature is removed.

**Tech Stack:** Laravel 13, PHP 8.4, Filament v5, Livewire v4, Pest v5, `propaganistas/laravel-disposable-email` ^2.5, `ryangjchandler/laravel-cloudflare-turnstile` (already present).

**Spec:** `docs/superpowers/specs/2026-08-10-disposable-email-signup-gap-design.md`

## Global Constraints

- PostgreSQL only — no SQLite/MySQL compatibility branches.
- Migrations have `up()` only. (No migrations in this plan.)
- All user-facing strings wrapped in `__()`. Two PHPStan rules enforce this.
- Every parameter and return type explicitly typed — `composer test:type-coverage` must stay at 100%.
- No new PHPStan ignores without approval.
- All write operations go through `app/Actions/` — enforced by `EloquentWriteOutsideActionRule`.
- Tests live only in `tests/{Arch,PHPStan,Smoke,Feature,Browser}`. No new top-level test directories, no new test files in this plan — extend existing ones.
- No AI attribution in commit messages.
- Pre-commit order: `vendor/bin/pint --dirty --format agent` → `vendor/bin/rector --dry-run` → `vendor/bin/phpstan analyse` → `composer test:type-coverage` → `php artisan test --compact --filter=...`.

## DECISION REQUIRED BEFORE TASK 1

The spec says the shared rule set is `['email:rfc,dns', 'indisposable']` applied to all three paths. **Verification during planning proved that breaks the suite**, so this plan implements a corrected version. Confirm before starting.

Measured facts:

| Address | `email` | `email:rfc` | `email:rfc,dns` |
|---|---|---|---|
| `test@example.com` | pass | pass | **FAIL** |
| `gdibbert@example.org` (UserFactory output) | pass | pass | **FAIL** |
| `burner@mailinator.com` | pass | pass | pass |
| `jane@gmail.com` | pass | pass | pass |

`fake()->safeEmail()` in `database/factories/UserFactory.php:34` emits reserved
`example.*` domains, and 102 fixture addresses across 21 test files use them.
Adding `dns` to the invitation and social paths would fail all of them. It would
also put a live DNS lookup in the invite and social-login request paths, and it
contributes **nothing** to this threat — every disposable provider publishes
valid MX records.

**This plan therefore parameterises the DNS check:**

```php
RegistrableEmail::rules()                 // ['email:rfc,dns', 'indisposable'] — registration
RegistrableEmail::rules(checkDns: false)  // ['email:rfc', 'indisposable']     — invite, social
```

One source of truth, no dual code path, registration keeps the DNS strictness it
has today, and no existing fixture breaks.

If you would rather drop `dns` everywhere — simpler, but it removes a control
registration has today — the edit is: in **Task 1 Step 4**, make `rules()` a
no-argument method returning `['email:rfc', 'indisposable']`; then drop the
`checkDns: false` argument in **Task 2 Step 3** and **Task 3 Step 3**. No test
in this plan changes, because none of them depend on the DNS check.

## File Structure

| File | Responsibility |
|---|---|
| `composer.json` / `composer.lock` | Add `propaganistas/laravel-disposable-email:^2.5` |
| `app/Rules/RegistrableEmail.php` | **Create.** Canonical email rule set |
| `app/Filament/Pages/Auth/Register.php` | Apply rule set; add Turnstile field |
| `app/Actions/Fortify/CreateNewSocialUser.php` | Apply rule set |
| `app/Actions/Jetstream/InviteTeamMember.php` | Apply rule set |
| `resources/views/filament/forms/components/turnstile.blade.php` | **Create.** Turnstile widget for a Filament `ViewField` |
| `config/fortify.php` | Remove `Features::registration()` |
| `bootstrap/app.php` | Schedule `disposable:update` weekly |
| `tests/Feature/Auth/RegistrationTest.php` | Disposable + Turnstile + route-shim tests |
| `tests/Feature/Auth/SocialiteLoginTest.php` | Disposable social test |
| `tests/Feature/Teams/InviteTeamMemberTest.php` | Disposable invitation test |

## Verified Facts (do not re-derive)

- Rule name is `indisposable`, registered via `Validator::extend` in the package service provider. Default message: `Disposable email addresses are not allowed.`
- Command is `disposable:update`.
- `DisposableDomains::getFromStorage()` falls back to the package's bundled `domains.json` when the storage file is absent, so **CI needs no seeding and no network**. Bundled list has 74,690 domains; `mailinator.com` is listed, `gmail.com` is not.
- `isDisposable()` returns `false` on an empty list — fails open, never a signup outage.
- `Turnstile::fake()` / `Turnstile::dummy()` exist (`vendor/ryangjchandler/laravel-cloudflare-turnstile/src/Testing/FakeClient.php`), precedent in `tests/Feature/ContactFormTest.php:19-59`.
- The `<x-turnstile>` Blade component has native Livewire support: passing `wire:model` makes it emit `wire:ignore`, a `data-callback`, and a script that calls `@this.set(...)`.
- Filament exposes a component's public methods to its custom view as closures (`ViewComponent.php:224`), so `$getStatePath()` and `$getFieldWrapperView()` are available.
- CI defines no `TURNSTILE_*` env vars (`phpunit.xml:44-59`), so a key-conditional field is inert in CI.
- `docs/` is covered by `.gitignore:43` (`/docs/*`); spec and plan files are committed with `git add -f`, matching 11 existing tracked specs.

---

### Task 1: Dependency, shared rule, register page, weekly schedule

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `app/Rules/RegistrableEmail.php`
- Modify: `app/Filament/Pages/Auth/Register.php:35-44`
- Modify: `bootstrap/app.php:114-128`
- Test: `tests/Feature/Auth/RegistrationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Rules\RegistrableEmail::rules(bool $checkDns = true): list<string>` — used by Tasks 2 and 3.

- [ ] **Step 1: Install the dependency**

```bash
composer require propaganistas/laravel-disposable-email:^2.5
```

The service provider is auto-discovered. Do **not** publish the config — defaults are correct.

- [ ] **Step 2: Write the failing test**

Add to the end of `tests/Feature/Auth/RegistrationTest.php`:

```php
it('rejects registration from a disposable email domain', function (): void {
    livewire(Register::class)
        ->fillForm([
            'name' => 'Burner User',
            'email' => 'burner@mailinator.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasFormErrors(['email']);

    expect(User::where('email', 'burner@mailinator.com')->exists())->toBeFalse();
});
```

The existing test `registers a new user without creating a team` (line 10, uses
`jane-test@gmail.com`) is the positive-case regression guard. Do not duplicate it.

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter="rejects registration from a disposable email domain"`
Expected: FAIL — the form has no errors and the user row is created.

- [ ] **Step 4: Create the rule class**

Create `app/Rules/RegistrableEmail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Rules;

/**
 * The canonical validation rule set for any caller-supplied email address that
 * can lead to an account.
 *
 * The DNS check is opt-out because it performs a live lookup: registration can
 * afford it, but the invitation and social paths sit in request paths where a
 * lookup buys nothing — every disposable provider publishes valid MX records.
 */
final readonly class RegistrableEmail
{
    /**
     * @return list<string>
     */
    public static function rules(bool $checkDns = true): array
    {
        return [
            $checkDns ? 'email:rfc,dns' : 'email:rfc',
            'indisposable',
        ];
    }
}
```

- [ ] **Step 5: Apply it to the register page**

In `app/Filament/Pages/Auth/Register.php`, add the import after the existing
`use App\Models\User;` line:

```php
use App\Rules\RegistrableEmail;
```

Then replace line 40:

```php
            ->rules(['email:rfc,dns'])
```

with:

```php
            ->rules(RegistrableEmail::rules())
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact --filter="rejects registration from a disposable email domain"`
Expected: PASS

- [ ] **Step 7: Run the whole registration file for regressions**

Run: `php artisan test --compact tests/Feature/Auth/RegistrationTest.php`
Expected: all 5 tests PASS. The four pre-existing tests must be untouched.

- [ ] **Step 8: Schedule the weekly list refresh**

In `bootstrap/app.php`, add after the `billing:process-trials` line (currently line 123):

```php
        $schedule->command('disposable:update')->weekly()->withoutOverlapping()->onOneServer();
```

- [ ] **Step 9: Verify the command is registered and runnable**

Run: `php artisan disposable:update`
Expected: exit 0, writes `storage/framework/disposable_domains.json`.

Run: `php artisan schedule:list | grep disposable`
Expected: one weekly entry.

- [ ] **Step 10: Quality gates**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
```

Expected: pint clean, rector no changes, phpstan no new errors, type coverage 100%.

If rector reports `phar://.../phpstan.phar/... is not a file`, the global tmp
cache holds stale paths from a deleted workspace: `rm -rf "$TMPDIR/cache"` and
re-run. That error masks real findings.

- [ ] **Step 11: Commit**

```bash
git add composer.json composer.lock app/Rules/RegistrableEmail.php \
        app/Filament/Pages/Auth/Register.php bootstrap/app.php \
        tests/Feature/Auth/RegistrationTest.php
git commit -m "feat(auth): block disposable email domains at registration"
```

---

### Task 2: Apply the rule to social signup

**Files:**
- Modify: `app/Actions/Fortify/CreateNewSocialUser.php:26-29`
- Test: `tests/Feature/Auth/SocialiteLoginTest.php`

**Interfaces:**
- Consumes: `App\Rules\RegistrableEmail::rules(bool $checkDns = true): list<string>` from Task 1.
- Produces: nothing.

- [ ] **Step 1: Write the failing test**

Add to the end of `tests/Feature/Auth/SocialiteLoginTest.php`. The file already
defines the `makeSocialiteUser()` helper (line 15) and imports
`SocialiteProvider`, `Socialite`, and `User`:

```php
test('callback from socialite provider rejects a disposable email address', function () {
    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('987654321', 'Burner User', 'burner@mailinator.com'),
    );

    expect(fn () => $this->get(route('auth.socialite.callback', [
        'provider' => SocialiteProvider::GOOGLE->value,
        'code' => 'test-code',
    ])))->toThrow(Illuminate\Validation\ValidationException::class);

    $this->assertDatabaseMissing('users', ['email' => 'burner@mailinator.com']);
    $this->assertGuest();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="rejects a disposable email address"`
Expected: FAIL — no exception thrown, and the user row is created.

- [ ] **Step 3: Apply the rule set**

In `app/Actions/Fortify/CreateNewSocialUser.php`, add the import after
`use App\Models\User;`:

```php
use App\Rules\RegistrableEmail;
```

Then replace the `email` line inside the `Validator::make` call (line 28):

```php
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
```

with:

```php
            'email' => ['required', 'string', ...RegistrableEmail::rules(checkDns: false), 'max:255', 'unique:users'],
```

DNS is off here: the address is already provider-verified, and a lookup in the
OAuth callback path buys nothing.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter="rejects a disposable email address"`
Expected: PASS

- [ ] **Step 5: Run the whole file for regressions**

Run: `php artisan test --compact tests/Feature/Auth/SocialiteLoginTest.php`
Expected: all tests PASS, including the existing `test@example.com` cases —
`example.com` is RFC-valid and not on the disposable list.

- [ ] **Step 6: Quality gates**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
composer test:type-coverage
```

- [ ] **Step 7: Commit**

```bash
git add app/Actions/Fortify/CreateNewSocialUser.php tests/Feature/Auth/SocialiteLoginTest.php
git commit -m "feat(auth): block disposable email domains on social signup"
```

---

### Task 3: Apply the rule to team invitations

**Files:**
- Modify: `app/Actions/Jetstream/InviteTeamMember.php:68-79`
- Test: `tests/Feature/Teams/InviteTeamMemberTest.php`

**Interfaces:**
- Consumes: `App\Rules\RegistrableEmail::rules(bool $checkDns = true): list<string>` from Task 1.
- Produces: nothing.

- [ ] **Step 1: Write the failing test**

Add to the end of `tests/Feature/Teams/InviteTeamMemberTest.php`. The file's
`beforeEach` (line 14) already fakes Mail, creates `$this->user` and
`$this->team`, and sets the Filament tenant:

```php
test('team members cannot be invited with a disposable email address', function () {
    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm([
            'email' => 'burner@mailinator.com',
            'role' => 'admin',
        ])
        ->call('addTeamMember', $this->team);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="cannot be invited with a disposable email address"`
Expected: FAIL — the invitation row is created, count is 1 not 0.

- [ ] **Step 3: Apply the rule set**

In `app/Actions/Jetstream/InviteTeamMember.php`, add the import after
`use App\Models\User;`:

```php
use App\Rules\RegistrableEmail;
```

Then in the `rules()` method, replace line 72:

```php
                'required', 'email',
```

with:

```php
                'required', ...RegistrableEmail::rules(checkDns: false),
```

DNS is off here for the same reason as Task 2, and additionally because the
existing suite invites `test@example.com`, which has no MX record.

- [ ] **Step 4: Update the rules() docblock**

The method's return type annotation on line 66 is
`@return array<string, list<Unique|Role|string>>`. The spread adds only strings,
so the annotation stays correct. Confirm PHPStan agrees in Step 7 rather than
editing it speculatively.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter="cannot be invited with a disposable email address"`
Expected: PASS

- [ ] **Step 6: Run the full Teams suite for regressions**

Run: `php artisan test --compact tests/Feature/Teams/`
Expected: all PASS. This is the highest-regression-risk step in the plan —
9 files in this directory use `@example.com` addresses. They pass because
`example.com` is RFC-valid and not disposable-listed.

- [ ] **Step 7: Quality gates**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
composer test:type-coverage
```

- [ ] **Step 8: Commit**

```bash
git add app/Actions/Jetstream/InviteTeamMember.php tests/Feature/Teams/InviteTeamMemberTest.php
git commit -m "feat(teams): block disposable email domains on invitations"
```

---

### Task 4: Turnstile on the register page

**Files:**
- Create: `resources/views/filament/forms/components/turnstile.blade.php`
- Modify: `app/Filament/Pages/Auth/Register.php`
- Test: `tests/Feature/Auth/RegistrationTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: form state key `cf_turnstile_response` on the register form.

- [ ] **Step 1: Write the failing tests**

Add to the end of `tests/Feature/Auth/RegistrationTest.php`, and add this import
at the top of the file alongside the existing ones:

```php
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;
```

```php
it('requires a passing turnstile challenge when a site key is configured', function (): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Turnstile::fake()->fail();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-turnstile@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => 'invalid-token',
        ])
        ->call('register')
        ->assertHasFormErrors(['cf_turnstile_response']);

    expect(User::where('email', 'jane-turnstile@gmail.com')->exists())->toBeFalse();
});

it('registers successfully when the turnstile challenge passes', function (): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Turnstile::fake();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-turnstile-ok@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => Turnstile::dummy(),
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'jane-turnstile-ok@gmail.com')->exists())->toBeTrue();
});

it('skips the turnstile challenge when no site key is configured', function (): void {
    config(['services.turnstile.key' => null]);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-no-turnstile@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'jane-no-turnstile@gmail.com')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="turnstile"`
Expected: the first two FAIL (no such field, no error raised). The third passes
already — it is the guard proving CI and self-hosted installs stay unaffected.

- [ ] **Step 3: Create the widget view**

Create `resources/views/filament/forms/components/turnstile.blade.php`:

```blade
<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <x-turnstile.scripts />

    <x-turnstile
        wire:model="{{ $getStatePath() }}"
        data-action="register"
        data-theme="auto"
    />
</x-dynamic-component>
```

`wire:model` is what activates the package's Livewire mode: it emits
`wire:ignore`, registers a `data-callback`, and pushes the token into Livewire
state via `@this.set(...)`.

- [ ] **Step 4: Add the field to the register form**

In `app/Filament/Pages/Auth/Register.php`, add these imports:

```php
use Filament\Forms\Components\ViewField;
use RyanChandler\LaravelCloudflareTurnstile\Rules\Turnstile as TurnstileRule;
```

Add this method to the class:

```php
    protected function getTurnstileFormComponent(): ViewField
    {
        return ViewField::make('cf_turnstile_response')
            ->hiddenLabel()
            ->view('filament.forms.components.turnstile')
            ->dehydrated(false)
            ->rules([new TurnstileRule])
            ->visible(fn (): bool => filled(config('services.turnstile.key')));
    }
```

`->dehydrated(false)` is correct here and is **not** the documented Filament
mistake: the token must never reach `User::create()`. It is validated by rule,
not persisted.

Then override the form schema so the field is rendered. Add:

```php
    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    protected function getFormSchema(): array
    {
        return [
            ...parent::getFormSchema(),
            $this->getTurnstileFormComponent(),
        ];
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="turnstile"`
Expected: all three PASS.

If `->visible()` prevents validation from running at all, swap it for
`->required(fn (): bool => filled(config('services.turnstile.key')))` combined
with `->rules(fn (): array => filled(config('services.turnstile.key')) ? [new TurnstileRule] : [])`
and re-run. Filament skips validation for hidden components, which is the
behaviour the third test pins down.

- [ ] **Step 6: Run the whole registration file**

Run: `php artisan test --compact tests/Feature/Auth/RegistrationTest.php`
Expected: all 8 tests PASS.

- [ ] **Step 7: Verify in a real browser**

The register page is a Filament page, so tests passing is not sufficient.

```bash
AB="turnstile-verify"
agent-browser --session "$AB" set viewport 1920 1080
agent-browser --session "$AB" open "https://bangalore.test/app/register"
agent-browser --session "$AB" screenshot "$(pwd)/.context/turnstile-register.png"
```

Expected: the Turnstile widget renders below the password fields, and
`agent-browser --session "$AB" errors` is empty. Local `.env` has both keys set
(24 and 35 chars), so the widget is live. Capture light and dark.

- [ ] **Step 8: Quality gates**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
```

- [ ] **Step 9: Commit**

```bash
git add resources/views/filament/forms/components/turnstile.blade.php \
        app/Filament/Pages/Auth/Register.php tests/Feature/Auth/RegistrationTest.php
git commit -m "feat(auth): add turnstile challenge to registration"
```

---

### Task 5: Remove the dead Fortify registration route

**Files:**
- Modify: `config/fortify.php:148-159`
- Test: `tests/Feature/Auth/RegistrationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

**Context:** `POST /register` currently returns 500 —
`Target [Laravel\Fortify\Contracts\CreatesNewUsers] is not instantiable`.
Nothing calls `Fortify::createUsersUsing()`. It is a public endpoint that only
throws. `GET /register` is served by the shim at `routes/web.php:43`, which is
independent of Fortify and must keep working.

- [ ] **Step 1: Write the failing test**

Add to the end of `tests/Feature/Auth/RegistrationTest.php`:

```php
it('does not expose a fortify registration endpoint', function (): void {
    $this->post('/register', [
        'name' => 'Burner User',
        'email' => 'burner-fortify@mailinator.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertNotFound();

    expect(User::where('email', 'burner-fortify@mailinator.com')->exists())->toBeFalse();
});

it('redirects the bare register path to the panel register page', function (): void {
    $this->get('/register')->assertRedirect(url()->getAppUrl('register'));
});
```

- [ ] **Step 2: Run the tests to verify the first fails**

Run: `php artisan test --compact --filter="fortify registration endpoint"`
Expected: FAIL — returns 500, not 404.

Run: `php artisan test --compact --filter="redirects the bare register path"`
Expected: PASS already. This is the guard that the removal does not break the shim.

- [ ] **Step 3: Remove the feature**

In `config/fortify.php`, delete this line from the `features` array (line 149):

```php
        Features::registration(),
```

Leave every other feature in place — `resetPasswords`, `emailVerification`,
`updateProfileInformation`, `updatePasswords`, and `twoFactorAuthentication` are
all live and in use.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="fortify registration endpoint"`
Expected: PASS

Run: `php artisan test --compact --filter="redirects the bare register path"`
Expected: PASS

- [ ] **Step 5: Confirm the route table**

```bash
php artisan config:clear
php artisan route:list --path=register
```

Expected: `POST /register` is gone. Two `GET` entries remain —
`filament.app.auth.register` at `app/register`, and the `register` shim closure.

- [ ] **Step 6: Run the auth and smoke suites**

```bash
php artisan test --compact tests/Feature/Auth/ tests/Smoke/
```

Expected: all PASS. `tests/Smoke/RouteTest.php` walks routes and would catch a
broken shim.

- [ ] **Step 7: Quality gates**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
composer test:type-coverage
```

- [ ] **Step 8: Commit**

```bash
git add config/fortify.php tests/Feature/Auth/RegistrationTest.php
git commit -m "fix(auth): remove dead fortify registration routes"
```

---

## Final Verification

Run after all five tasks, before opening the PR.

- [ ] **Re-run the original repro in a real browser**

```bash
AB="burner-reverify"
agent-browser --session "$AB" set viewport 1920 1080
agent-browser --session "$AB" open "https://bangalore.test/app/register"
agent-browser --session "$AB" eval '(() => {
  const set=(id,v)=>{const e=document.getElementById(id); e.value=v; e.dispatchEvent(new Event("input",{bubbles:true}));};
  set("form.name","Burner Recheck");
  set("form.email","burner-recheck@mailinator.com");
  set("form.password","Sup3rSecret!234");
  set("form.passwordConfirmation","Sup3rSecret!234");
  return "filled";
})()'
agent-browser --session "$AB" eval '(() => { const f=[...document.querySelectorAll("form")].find(x=>x.getAttribute("wire:submit")==="register"); f.requestSubmit(); return "submitted"; })()'
agent-browser --session "$AB" eval '(() => JSON.stringify({url:location.href, errs:[...document.querySelectorAll(".fi-fo-field-wrp-error-message")].map(e=>e.textContent.trim())}))()'
```

Expected: still on `/app/register`, and the errors array contains
`Disposable email addresses are not allowed.` Screenshot it.

Then confirm no row was created:

```bash
php artisan tinker --execute 'echo App\Models\User::query()->where("email","like","%mailinator%")->count()." mailinator users\n";'
```

Expected: `0 mailinator users`.

- [ ] **Full suite**

```bash
composer test:pest
```

Expected: green. Do not use `composer test:pest:tia` as the gate — it replays
cached passes and cannot see these changes reliably.

- [ ] **Arch suite** (needs raised memory locally)

```bash
php -d memory_limit=2G vendor/bin/pest tests/Arch
```

- [ ] **Full static analysis**

```bash
vendor/bin/pint --test --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
```

- [ ] **Review the diff before pushing**

```bash
git diff origin/main...HEAD
```

Show the diff and the test output to the user. Do not push or open a PR until
they have reviewed it.

## PR Body Requirements

The PR description must state plainly that **the trial-grant amplifier remains
open**. Required wording, from the spec's Out of Scope section:

> This blocks known disposable domains and adds a bot check, but does not cap
> total exposure. A custom domain still yields unlimited addresses that no
> blocklist carries, and each one is worth a fresh 2000-credit Pro trial because
> `StartProTrial` gates per-user on `pro_trial_used_at`. Follow-up issue:
> `<ISSUE_URL>`.

Open the follow-up issue **before** the PR and substitute its real URL for
`<ISSUE_URL>`; the PR must not ship with the placeholder. Per the repo's issue
conventions: set Roadmap project status to Todo, set issue type to Bug
(`IT_kwDOCuENls4BaQlU`), and **ask the user which milestone** before assigning
one.

Do not claim the gap is closed.
