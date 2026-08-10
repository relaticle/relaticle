# Architecture

Relaticle is a modular monolith: the CRM core lives in `app/`, and self-contained
subsystems live in `packages/<Name>/` with the `Relaticle\<Name>` namespace
(autoloaded from `packages/<Name>/src` via the root composer.json — packages have
no composer.json of their own). Service providers are registered in
`bootstrap/providers.php`.

| Location | Owns |
|---|---|
| `app/` | CRM domain: models, actions, Filament app panel, API, MCP server |
| `packages/Chat` | AI assistant (agents, chat tools, credit system, streaming) |
| `packages/SystemAdmin` | Internal admin panel (separate Filament panel) |
| `packages/ImportWizard` | CSV import flows |
| `packages/Documentation` | Public docs pages |
| `packages/OnboardSeed` | Demo/onboarding data seeding |
| `packages/EmailIntegration` | Email + calendar sync (Gmail/Microsoft Graph), sharing, privacy |

Create a new package only for a genuinely separable subsystem with its own panel,
routes, or lifecycle — not for a new CRM entity (those go in `app/`). Package
anatomy mirrors a Laravel app: `src/`, `config/`, `routes/`, `resources/`,
`database/`.

## Module boundaries (enforced by tests/Arch/ArchTest.php)

- `App` must not depend on `Relaticle\SystemAdmin`; `Relaticle\SystemAdmin` may
  only reach back into `App\Models`, `App\Enums`, `App\Rules`
- Never use the custom-fields package models directly — use the `App\Models\CustomField*`
  subclasses (runtime model swapping is configured in `AppServiceProvider`)
- `packages/SystemAdmin` is excluded from PHPStan — when adding or removing enum
  cases, manually sweep SystemAdmin for `match` expressions over that enum (this
  exclusion already caused a production `UnhandledMatchError`)

## Actions (the write path)

All write operations (create, update, delete) go through action classes in
`app/Actions/<Domain>/` — never inline business logic in controllers, MCP tools,
Livewire components, or Filament resources. Actions are the single source of
truth for business logic and side effects (notifications, syncs, etc.).

The canonical shape — `final readonly`, a single `execute()` method, authorization
and tenant-ownership checks inside the action itself:

```php
final readonly class CreateOpportunity
{
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): Opportunity
    {
        abort_unless($user->can('create', Opportunity::class), 403);

        TenantFkValidator::assertOwned($user, $data, [...]);
        // ...
    }
}
```

- Enforced by `EloquentWriteOutsideActionRule` (PHPStan): Eloquent writes in
  controllers, MCP tools, Livewire components, Filament classes, or chat tools
  fail analysis. Pre-existing violations are grandfathered per-file in
  `phpstan.neon` — when you refactor one, remove its ignore entry
- Filament CRUD may use native `CreateAction`/`EditAction` when the operation is a
  plain `Model::create()`/`->update()` with no extra logic — but side effects
  (e.g., notifications) must still be triggered via `->after()` hooks calling the
  appropriate action
- When reviewing or refactoring code, extract inline business logic into action classes
- Use `App\Data` (spatie/laravel-data) objects for structured payloads where they
  already exist; don't introduce new patterns
- Name domain concepts plainly (`Plan`, not `AiPlan`) — context comes from the
  namespace. Never store the same fact in two places; pick one source of truth

## i18n enforcement

Two custom PHPStan rules (`app/PHPStan/Rules/`) forbid hardcoded user-facing
strings: `HardcodedUserFacingStringRule` (guarded methods like `label()`,
`heading()`, `title()`) and `HardcodedStaticPropertyRule` (guarded static
properties like `$navigationLabel`). Wrap user-facing strings in `__()`.
Some paths are deferred via explicit ignores in `phpstan.neon` — don't add new
ignores without approval.
