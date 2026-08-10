# Close the disposable/burner-email signup gap

**Date:** 2026-08-10
**Branch:** `ManukMinasyan/disposable-email-signup-gap`
**Status:** Shipped. Design approved with one amendment, confirmed and
implemented (see below).

> **Amendment (2026-08-10, during planning) — confirmed and shipped.** Component
> 1 originally specified the shared rule set as `['email:rfc,dns',
> 'indisposable']` for all three paths. Verification proved the `dns` check
> breaks the suite: `fake()->safeEmail()` emits reserved `example.*` domains
> with no MX record, and 102 fixture addresses across 21 test files use them.
> `dns` also adds a live lookup to the invite and social request paths while
> contributing nothing to this threat — every disposable provider publishes
> valid MX records. The parameterised form was confirmed and is what shipped:
> `rules()` keeps `dns` for registration, `rules(checkDns: false)` is used for
> invitations and social signup. Component 1 below reflects the shipped code.

## Summary

Relaticle accepts registrations from disposable-email providers. This was
reproduced end to end on the running app: `burner-audit@mailinator.com`
registered through the real Filament form, verified, created a workspace, and
landed inside the CRM at `/app/burner-workspace` with
`plan=pro credits_remaining=2000`.

The prize is not a junk account row. `CreateTeam` auto-starts a 14-day Pro trial
with 2000 AI credits and no card. The only guard is `StartProTrial.php:33`,
which checks `pro_trial_used_at` **per user** — so every new email address is a
fresh grant. Credits are billed per message (`CreditService.php:334`), Pro
unlocks Opus 4.7 at a 3.0 multiplier, so 2000 credits buys roughly 500-660 Opus
messages: on the order of **$50-70 of vendor inference per signup**, repeatable.

Rate limiting is a speed bump only. Filament's `rateLimit(2)` keys on
`component|method|IP` (`WithRateLimiting.php:26`) — 2/min from one address,
~2,880/day, and any proxy pool erases it.

This design blocks known disposable domains, puts a bot check in front of
registration, and removes a dead public endpoint. It deliberately does **not**
close the trial-grant amplifier; see [Out of scope](#out-of-scope).

## Reproduction (pre-fix baseline)

The validator, isolated:

```
burner@mailinator.com => PASSES    x@guerrillamail.com => PASSES
y@10minutemail.com    => PASSES    z@yopmail.com       => PASSES
```

`email:rfc,dns` does not stop these — disposable providers publish valid MX
records. Full walkthrough recorded in the investigation: register → verify →
create workspace → `plan=pro`, 2000 credits. Test data removed afterwards.

## Entry-point audit

| Path | File:line | Rule today | Action |
|---|---|---|---|
| Filament register page | `app/Filament/Pages/Auth/Register.php:40` | `email:rfc,dns` | **Fix** |
| Social signup | `app/Actions/Fortify/CreateNewSocialUser.php:28` | `email` | **Fix** |
| Team invitation | `app/Actions/Jetstream/InviteTeamMember.php:74` | `email` | **Fix** |
| Fortify `POST /register` | `RegisteredUserController@store` | none reached | **Delete** (dead) |
| SystemAdmin user create | `packages/SystemAdmin/.../UserResource.php:70` | `->email()` | Leave — trusted operator |
| Add team member | `app/Actions/Jetstream/AddTeamMember.php:64` | `exists:users` | Leave — creates no user |
| Invitation accept / join link | `AcceptTeamInvitationController`, `JoinTeamViaLinkController` | n/a | Leave — require auth, create no user |
| REST API | `routes/api.php` | n/a | Leave — no user-create endpoint |

Email verification is enforced (88 routes behind `verified`, including the whole
app panel) but is **not** a mitigation: mailinator inboxes are publicly
readable. Invited users skip it entirely — `Register.php:64` calls
`markEmailAsVerified()` when the address matches a pending invitation.

## Corrections to the original brief

Two premises in the task description did not survive verification.

1. **Fortify registration is dead, not a second unguarded door.** `POST /register`
   returns 500: `Target [Laravel\Fortify\Contracts\CreatesNewUsers] is not
   instantiable`. Nothing binds that contract — `createUsersUsing()` is never
   called. No validation runs and no user is created. `GET /register` is a
   redirect shim at `routes/web.php:43`, unrelated to Fortify's view.
2. **`propaganistas/laravel-phone` is a transitive dependency**, pulled in by
   `relaticle/custom-fields` (`composer.lock:9510`), not a deliberate adoption.
   The "repo already trusts this author" precedent is thinner than stated. The
   package is still the right choice on its own merits.

A third finding reshaped the design: **Turnstile and honeypot are already
installed and configured** (`ryangjchandler/laravel-cloudflare-turnstile`,
`spatie/laravel-honeypot`, keys in `config/services.php:60`), wired to the
contact form only. Adding Turnstile to registration is wiring, not adoption.

## Decisions taken

| Decision | Choice | Rationale |
|---|---|---|
| Scope | Disposable rule **+ Turnstile on registration** | Largest fix that is purely a security change; trial gating is a product call |
| Rule placement | **One shared rule set** used by all 3 paths | The three sites disagreeing about what a valid email is *is* the underlying defect |
| Fortify registration | **Remove** `Features::registration()` | A public route that 500s is a defect regardless of this work |
| Turnstile enforcement | Conditional on `config('services.turnstile.key')` being filled | Keeps CI and self-hosted instances working; hosted product is the only abuse target |
| Trial-grant amplifier | **Out of scope**, follow-up issue | Requires a conversion-vs-abuse tradeoff decision |

## Component 1 — dependency and shared email rule

Add the dependency that provides the `indisposable` rule:

```
composer require propaganistas/laravel-disposable-email:^2.5
```

The service provider is auto-discovered, but the config **is** published to
`config/disposable-email.php` — the package's shipped defaults are unsafe for
this threat (see "Blocklist configuration" below).

New `app/Rules/RegistrableEmail.php`. A `final readonly` class exposing the
canonical rule list, **not** a `ValidationRule` implementation — the set has to
compose with each call site's own uniqueness constraint. The DNS check is
opt-out because it performs a live lookup: registration can afford it, the
invitation and social paths sit in request paths where it buys nothing.

```php
final readonly class RegistrableEmail
{
    /** @return list<string> */
    public static function rules(bool $checkDns = true): array
    {
        return [
            $checkDns ? 'email:rfc,dns' : 'email:rfc',
            'indisposable',
        ];
    }
}
```

Applied at three sites, each keeping its existing uniqueness rule:

- `Register.php` — `rules()`, replaces `['email:rfc,dns']`, keeps `->unique()`
- `CreateNewSocialUser.php` — `rules(checkDns: false)`, replaces `'email'`, keeps `'unique:users'`
- `InviteTeamMember.php` — `rules(checkDns: false)`, replaces `'email'`, keeps the `Rule::unique` closure

OAuth identities are provider-verified, but a Google Workspace account can carry
any verified address, so the rule applies there too.

The rejection message is ours, not the vendor's: the `indisposable` key lives in
`lang/en/validation.php` and `lang/fr/validation.php`, which Laravel resolves
ahead of the package's hardcoded English fallback.

### Blocklist configuration

`config/disposable-email.php` is published and two defaults are overridden. Both
are load-bearing; leaving either at its shipped value defeats the feature.

**`include_subdomains => true`** (ships as `false`). With the default, only
exact-domain matches are blocked — but Mailinator and Yopmail run wildcard DNS,
so `burner@anything.mailinator.com` and `burner@sub.yopmail.com` deliver exactly
like the bare domain. Off, the entire blocklist is a one-character bypass.

**`whitelist => [...]`** (ships empty). Populated with `relaticle.com` and the
mainstream consumer providers (gmail, outlook, hotmail, live, yahoo, icloud, me,
proton.me, protonmail, fastmail, zoho, gmx, yandex, aol, msn). This is the
guardrail for Component 4's weekly `disposable:update`, which overwrites the
local list from a community GitHub repository at branch HEAD with no validation.
Without it, one bad upstream entry locks a mainstream provider out of
registration *and* team invitations product-wide. Whitelisted domains are
removed from the list at load, so they can never be treated as disposable.

## Component 2 — Turnstile on registration

Filament forms are Livewire; there is no form POST to carry
`cf-turnstile-response`. The widget renders through a `ViewField` whose JS
callback pushes the token into Livewire state, validated by `App\Rules\
TurnstileChallenge` — a thin wrapper over the package's `Rules\Turnstile`.

**The field renders and is required only when both
`config('services.turnstile.key')` and `config('services.turnstile.secret')` are
filled.** A site key without a secret cannot be verified server-side, so
requiring only the key would present a challenge that can never pass.

- CI defines no `TURNSTILE_*` vars (`phpunit.xml`), so the pre-existing
  registration tests keep passing unmodified
- Self-hosted instances without keys keep working, and are not the abuse target
  since the 2000 credits are a hosted cost
- Production has both, so it is enforced

Accepted risk: if production loses those env vars, bot protection disappears
silently. The alternative — hard-requiring them as `ContactRequest.php:21` does
— fails loudly but breaks self-hosters and forces `Turnstile::fake()` into every
test that creates a user. Conditional was chosen deliberately.

### Single-use tokens

Cloudflare tokens are single-use and Laravel validates every field on every
submit, so the token is spent on the first submit even when a *different* field
fails. Livewire keeps the spent token behind the widget's `wire:ignore`, and the
next submit replays it — `timeout-or-duplicate` beside a widget showing a green
tick, recoverable only by reloading and losing all typed input.

`Register::register()` therefore catches `ValidationException`, nulls
`data.cf_turnstile_response`, and rethrows. The vendor view already registers a
Livewire watcher that calls `window.turnstile.reset()` when the bound value goes
from set to empty, so blanking the state is all that is needed. Verified in a
real browser with Cloudflare's always-pass test credentials: with the fix,
`window.turnstile.reset()` fires once and the bound value transitions
`token → null → fresh token`; with it reverted, zero resets and the spent token
is retained.

### Cloudflare outages

`App\Rules\TurnstileChallenge` exists because the vendor client issues its
siteverify call through `Http::retry(3, 100)` with throwing left on — which makes
its own `if (! $response->ok()) return SiteverifyResponse::success()` fail-open
branch unreachable, and lets any Cloudflare hiccup escape as an unhandled
exception, i.e. a 500 on a public page. The wrapper catches
`Illuminate\Http\Client\RequestException` and `ConnectionException`, logs a
warning, and **fails closed** with `__('auth.turnstile.unavailable')`. Failing
open was rejected: it would hand an attacker a bypass they can trigger at will.

Residual: the vendor client sets no `->timeout()`, so a black-holed connection
can still take up to three default 30s attempts before the "try again" message
appears. Fixing that requires replacing the package's `ClientInterface` binding
and is left as a follow-up.

## Component 3 — remove the dead Fortify route

Delete `Features::registration()` from `config/fortify.php:149`. This removes
Fortify's `POST /register` (a public 500) and its `GET /register`. The
`routes/web.php:43` shim continues to serve `GET /register` → `/app/register`.

## Component 4 — scheduling

In `bootstrap/app.php`, matching the `onOneServer` convention at lines 121-128:

```php
$schedule->command('disposable:update')->weekly()->withoutOverlapping()->onOneServer();
```

`propaganistas/laravel-disposable-email` `^2.5.1` declares
`laravel/framework ^13.0`. It ships a bundled domain list, and `isDisposable()`
returns `false` on an empty list — a failed update degrades to a stale list,
never a signup outage. Validation is an offline array lookup; no request-path
network calls.

## Testing

All tests extend existing files. No new parallel test files, per the repo's
testing conventions.

`tests/Feature/Auth/RegistrationTest.php` (has `mutates(Register::class)`):

| Test | Asserts |
|---|---|
| Register with `@mailinator.com` | form error on `email`, zero `User` rows |
| Register with `@anything.mailinator.com`, `@sub.yopmail.com` | `indisposable` rule fails — subdomain bypass is closed |
| Register with an upstream-listed whitelisted domain | still succeeds — whitelist survives a bad list update |
| Disposable rejection under `fr` locale | the French message renders, not the vendor's English |
| Register with `@gmail.com` | still succeeds — regression guard on the pre-existing tests |
| Turnstile required when key configured | `config()` + `Turnstile::fake()->fail()` → form error |
| Turnstile absent when key unset, or secret unset | form submits, no error — protects CI and self-host |
| Turnstile token cleared after any validation failure | `data.cf_turnstile_response` is null, so the widget resets |
| Siteverify 500 and connection failure | no exception escapes; `auth.turnstile.unavailable` on the field; warning logged |
| `GET /register` redirects to `/app/register` | route removal did not break the shim |

`tests/Feature/Auth/SocialiteLoginTest.php` — social path: a disposable address
throws `ValidationException` and creates no user; a normal address still creates
one.

`tests/Feature/Teams/InviteTeamMemberTest.php` — invitation path: inviting a
disposable address fails validation with no `TeamInvitation` row; a normal
address still invites.

No new test files and no new test classes, but `RegistrationTest` roughly
tripled in size, so `tests/.pest/shards.json` is refreshed with
`composer test:update-shards` — a stale entry silently unbalances CI sharding.

`Turnstile::fake()` / `::dummy()` follow the precedent in
`tests/Feature/ContactFormTest.php:19-59`.

## Verification before the PR is claimed done

1. Re-run the step-2 repro — registering `@mailinator.com` through the real
   browser now fails validation, screenshot captured
2. `vendor/bin/pint --dirty --format agent`
3. `vendor/bin/rector --dry-run`
4. `vendor/bin/phpstan analyse` — no new errors, no new ignores
5. `composer test:type-coverage` — stays at 100%
6. `php artisan test --compact --filter=...` on the affected suites
7. `php artisan route:list` confirming `POST /register` is gone and
   `GET /register` still redirects
8. `git diff` reviewed before any commit

## Out of scope

The **trial-grant amplifier is not closed by this work**, and the PR body must
say so rather than implying the hole is shut.

A `$10/yr` custom domain yields unlimited addresses that no blocklist will ever
carry, and each one is worth a fresh 2000-credit Pro trial because
`StartProTrial.php:33` gates per-user. This change raises the cost of a burner
signup; it does not cap total exposure.

Follow-up issue to carry the investigation's cost numbers, with candidate
mitigations: card-on-file for trial activation, normalized-email dedupe
(strip Gmail dots and `+` aliases before the `pro_trial_used_at` check), a
lower trial credit grant, or per-ASN trial caps. Each trades signup conversion
for abuse resistance, which is a product decision.

Also deliberately untouched: `packages/SystemAdmin` user creation (trusted
operator) and the Filament profile email-change path (changing an existing
account's address grants no new trial, so it is not part of this threat).
