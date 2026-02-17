# Relaticle Code Review Guidelines

## Repository Context

Multi-tenant SaaS CRM with paying customers. Tenant isolation is the highest-priority concern — a single cross-tenant data leak is a critical security incident. The app uses Filament panels with a per-request `TeamScope` global scope applied via middleware, not in model boot. `Model::unguard()` is enabled globally.

Stack: PHP 8.4, Laravel 12, Filament 5, Livewire 4, Pest 4, Tailwind CSS 4.

CI enforces: PHPStan level 7, Pint formatting, Rector, 99.9% type coverage, full test suite. CI does **not** enforce: code coverage percentage, mutation testing, or required reviewer count.

## Tenant Isolation — Flag These

This app scopes data via `ApplyTenantScopes` middleware which registers `TeamScope` on: Company, People, Opportunity, Task, Note. Scoping happens per-request inside the Filament panel. Code running outside that middleware context (jobs, commands, API routes) has no automatic tenant filter.

Flag any of these patterns:

- `DB::table()` or `DB::select()` on a team-scoped table without a `WHERE team_id` clause
- `withoutGlobalScopes()` that removes all scopes instead of only `SoftDeletingScope`
- New Eloquent model on a team-scoped table missing the `HasTeam` trait
- New model with `HasTeam` not added to `ApplyTenantScopes` middleware
- New routes, Livewire components, or jobs that query team-scoped models without ensuring tenant context
- Queries using `->toBase()` which strips all Eloquent scopes

```php
// Correct: only strips soft delete scope, TeamScope remains active
return parent::getEloquentQuery()
    ->withoutGlobalScopes([SoftDeletingScope::class]);

// Dangerous: strips ALL scopes including TeamScope
return parent::getEloquentQuery()
    ->withoutGlobalScopes();
```

## Authorization — Flag These

Policies exist for Company, People, Opportunity, Task, Note. They check `$user->belongsToTeam($record->team)`.

- New controller action or Livewire method without `$this->authorize()` or policy check
- New Filament resource without a corresponding policy
- Livewire actions that modify records without verifying the record belongs to the current team
- Mass assignment from user-supplied request data — `Model::unguard()` is global, so `$fillable` is the only guard

```php
// Correct: authorize before acting
public function delete(string $id): void
{
    $record = Company::findOrFail($id);
    $this->authorize('delete', $record);
    $record->delete();
}

// Dangerous: no authorization, any authenticated user can delete any record
public function delete(string $id): void
{
    Company::findOrFail($id)->delete();
}
```

## Data Safety — Flag These

- Raw SQL with interpolated variables instead of parameter bindings (`?`)
- `DB::table()->upsert()` or `DB::table()->insert()` on team-scoped tables without a `team_id` in the data payload
- Export logic that doesn't filter data rows by `team_id`
- New public routes without auth middleware
- File uploads without MIME type and size validation
- `env()` calls outside of `config/` files

```php
// Correct: parameterized binding
DB::select('SELECT * FROM companies WHERE team_id = ?', [$teamId]);

// Dangerous: interpolated variable in SQL
DB::select("SELECT * FROM companies WHERE team_id = {$teamId}");
```

## Test Coverage — Flag These

New features or modified behavior should include corresponding Pest tests. There is no coverage gate in CI, so this must be caught in review.

- New Filament resource, Livewire component, or controller action without tests
- Modified business logic without updated tests
- Tests that only check the happy path — also cover validation failures and authorization denials
- Tests that create records without scoping to a specific team (relying on implicit scope rather than explicit `->for($team)`)

## PHP Standards

- Every file: `declare(strict_types=1);`
- Typed properties, explicit return types (including `void`), parameter type hints
- Constructor property promotion: `public function __construct(private UserService $service) {}`
- No empty zero-parameter constructors
- Short nullable: `?string` not `string|null`
- Always use curly braces, even single-line bodies
- PHPDoc over inline comments; only comment genuinely complex logic
- Enum keys: TitleCase (`FavoritePerson`, `Monthly`)

## Code Style

- Happy path last — handle errors first, return early
- Avoid `else` — use early returns
- String interpolation over concatenation
- Descriptive names: `$failedChecks` not `$checks` with a comment

## Laravel Conventions

- `Model::query()` not `DB::` for team-scoped data
- Eager load relationships to prevent N+1
- Form Request classes for validation, not inline
- `config()` not `env()` outside config files
- Named routes with `route()`
- Policies and gates for authorization
- Queued jobs with `ShouldQueue` for expensive operations
- `casts()` method, not `$casts` property
- Middleware in `bootstrap/app.php`, not a Kernel class

## Filament 5

- Form fields: `Filament\Forms\Components\`
- Layout (Grid, Section, Tabs): `Filament\Schemas\Components\`
- Infolist entries: `Filament\Infolists\Components\`
- Utilities (Get, Set): `Filament\Schemas\Components\Utilities\`
- All actions: `Filament\Actions\` — no `Filament\Tables\Actions\`
- Icons: `Heroicon` enum, not strings
- File visibility: `private` by default
- `Grid`, `Section`, `Fieldset` no longer span all columns by default
- `deferFilters()` is now the default table behavior

## Livewire 4

- `wire:model` is deferred by default; `wire:model.live` for real-time
- Namespace: `App\Livewire`
- Events: `$this->dispatch()`
- Validate and authorize in actions
- `wire:key` in loops

## Testing

- All tests use Pest with factories and factory states
- `mutates(ClassName::class)` to declare coverage intent
- Named assertions: `assertForbidden()` not `assertStatus(403)`
- Datasets for repetitive validation tests

## Tailwind CSS 4

- No deprecated utilities: `bg-opacity-*` is `bg-black/*`, `flex-shrink-*` is `shrink-*`
- Gap utilities for spacing, not margins between siblings
- `dark:` variants when existing components support dark mode
- CSS-first config with `@theme`, not `tailwind.config.js`
