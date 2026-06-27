# Activity Log Plugin — Design

A reusable Filament v5 panel plugin that renders a unified, chronological timeline UI for any Eloquent model. The plugin aggregates events from three sources — the subject model's own `spatie/laravel-activitylog` entries, activity-log entries from related models, and timestamp-based events from related models directly — normalizes every source output into a single DTO, deduplicates, sorts, paginates, and renders through a pluggable renderer registry.

## Scope

- **Consumer context:** spec-first, publishable package with **no in-app consumer yet**. Developed in-monorepo under `packages/ActivityLog/`, wired via a composer `path` repository. Tested against fixture models under the plugin's own test suite.
- **Filament version:** v5. All Filament APIs used target v5 namespaces and conventions.
- **Source abstraction:** hard dependency on `spatie/laravel-activitylog ^4.x`. The plugin reads the `activity_log` table directly. An internal `TimelineSource` contract exists for the plugin's own built-in sources and for `CustomEventSource`, but activity-log support is not behind an interface swap.
- **All surfaces ship in this plan:** infolist component, dashboard widget, relation manager. All activity-log modes (own subject + related models). All source types (`ActivityLog`, `RelatedActivityLog`, `RelatedModel`, `Custom`). Full builder API with deduplication, date grouping, pagination, eager loading, filtering, and optional caching.

## Package identity

| Item              | Value                                        |
| ----------------- | -------------------------------------------- |
| Composer package  | `relaticle/activity-log`                     |
| PHP namespace     | `Relaticle\ActivityLog\`                     |
| Package directory | `packages/ActivityLog/`                      |
| View namespace    | `activity-log::`                             |
| Plugin class      | `Relaticle\ActivityLog\ActivityLogPlugin`    |
| Service provider  | `Relaticle\ActivityLog\ActivityLogServiceProvider` (extends `Spatie\LaravelPackageTools\PackageServiceProvider`) |
| PHP               | ^8.4                                         |
| Laravel           | ^12.0                                        |
| Filament          | ^5.0                                         |
| Runtime deps      | `spatie/laravel-activitylog ^4.x`, `spatie/laravel-package-tools`, `filament/filament ^5.0` |
| Dev deps          | `orchestra/testbench`, `pestphp/pest`, `pestphp/pest-plugin-laravel`, `pestphp/pest-plugin-livewire` |

Root `composer.json` gains a `path` repository entry for `./packages/ActivityLog` and `"relaticle/activity-log": "*"` in `require-dev`. No autoload-level changes to the root — the package owns its own PSR-4 map.

## Architecture overview

**Data flow:**

1. Consumer model uses `HasTimeline` trait and implements `timeline(): TimelineBuilder`.
2. Caller invokes `$model->timeline()->paginate($perPage, $page)` (directly or via a Filament surface).
3. Builder derives a `Window` (date bounds + per-source cap = `perPage × (page + buffer)` + filter passthrough) and asks each registered `TimelineSource` to resolve entries within it.
4. Sources return `iterable<TimelineEntry>`, each source batch-loading relations to avoid N+1.
5. Builder merges, sorts descending by `occurredAt`, runs source-priority deduplication keyed by `TimelineEntry::$dedupKey`, slices into a `LengthAwarePaginator`.
6. Filament surface renders the page via the shared Livewire component `TimelineListLivewire`, resolving each entry's renderer through `RendererRegistry`.
7. Renderer emits a Blade partial; a shared wrapper view applies date-group headings to the current page.

## Package layout

```
packages/ActivityLog/
├── composer.json                                 # relaticle/activity-log
├── README.md
├── config/
│   └── activity-log.php
├── resources/
│   └── views/
│       ├── infolist-component.blade.php
│       ├── widget.blade.php
│       ├── relation-manager.blade.php
│       ├── timeline.blade.php
│       └── entries/
│           ├── default.blade.php
│           └── activity-log.blade.php
├── src/
│   ├── ActivityLogPlugin.php                     # implements Filament\Contracts\Plugin
│   ├── ActivityLogServiceProvider.php
│   ├── Facades/
│   │   └── Timeline.php
│   ├── Concerns/
│   │   └── HasTimeline.php
│   ├── Contracts/
│   │   ├── TimelineSource.php
│   │   └── TimelineRenderer.php
│   ├── Timeline/
│   │   ├── TimelineBuilder.php
│   │   ├── TimelineEntry.php
│   │   ├── Window.php
│   │   ├── TimelineCache.php
│   │   └── Sources/
│   │       ├── AbstractTimelineSource.php
│   │       ├── ActivityLogSource.php
│   │       ├── RelatedActivityLogSource.php
│   │       ├── RelatedModelSource.php
│   │       └── CustomEventSource.php
│   ├── Renderers/
│   │   ├── RendererRegistry.php
│   │   ├── DefaultRenderer.php
│   │   └── ActivityLogRenderer.php
│   └── Filament/
│       ├── Infolists/Components/ActivityLog.php
│       ├── Widgets/ActivityLogWidget.php
│       ├── RelationManagers/TimelineRelationManager.php
│       └── Livewire/TimelineListLivewire.php
└── tests/
    ├── Pest.php
    ├── TestCase.php
    ├── Fixtures/
    │   ├── Models/{Person.php,Email.php,Note.php,Task.php}
    │   ├── database/migrations/
    │   └── database/factories/
    └── Feature/
        ├── TimelineEntryTest.php
        ├── ActivityLogSourceTest.php
        ├── RelatedActivityLogSourceTest.php
        ├── RelatedModelSourceTest.php
        ├── CustomEventSourceTest.php
        ├── BuilderDedupTest.php
        ├── BuilderPaginationTest.php
        ├── BuilderFilteringTest.php
        ├── RendererRegistryTest.php
        ├── InfolistComponentTest.php
        ├── WidgetTest.php
        └── RelationManagerTest.php
```

## `TimelineEntry` DTO

Final, immutable, `readonly`. Every source produces this shape; every renderer consumes it.

```php
final readonly class TimelineEntry
{
    public function __construct(
        public string $id,                      // "{sourceKey}:{recordId}:{event}"
        public string $type,                    // 'activity_log' | 'related_model' | 'custom'
        public string $event,                   // 'created' | 'email_sent' | 'task_completed' | ...
        public \Carbon\CarbonImmutable $occurredAt,
        public string $dedupKey,                // '{related_class}:{related_id}:{timestamp_to_second}'
        public int $sourcePriority,             // dedup tiebreaker; higher wins
        public ?\Illuminate\Database\Eloquent\Model $subject = null,
        public ?\Illuminate\Database\Eloquent\Model $causer = null,
        public ?\Illuminate\Database\Eloquent\Model $relatedModel = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $icon = null,
        public ?string $color = null,
        public ?string $renderer = null,        // explicit renderer key; bypasses registry
        /** @var array<string, mixed> */
        public array $properties = [],
    ) {}
}
```

Rationale:

- `readonly` + `final` — nothing mutates an entry after a source returns it.
- `dedupKey` + `sourcePriority` on the entry — dedup pass is stateless: group by `dedupKey`, keep max `sourcePriority`, ties broken by source registration order.
- `CarbonImmutable` — immutability through the whole pipeline.
- `type` = small fixed vocabulary for coarse filtering; `event` = open-ended, drives renderer resolution.
- `renderer` lets a source force a specific renderer, bypassing registry lookup.
- `properties` — plain array (shape varies by event); no nested DTO.

## `TimelineBuilder`

```php
final class TimelineBuilder
{
    public static function make(\Illuminate\Database\Eloquent\Model $subject): self;

    // Source registration (priorities default to config)
    public function fromActivityLog(int $priority = 10): self;
    public function fromActivityLogOf(array $relations, int $priority = 10): self;
    public function fromRelation(string $relation, \Closure $configure, int $priority = 20): self;
    public function fromCustom(\Closure $resolver, int $priority = 30): self;
    public function addSource(TimelineSource $source): self;                        // escape hatch

    // Filters (passed to each source via Window where possible)
    public function between(?\Carbon\CarbonInterface $from, ?\Carbon\CarbonInterface $to): self;
    public function ofType(array $types): self;
    public function exceptType(array $types): self;
    public function ofEvent(array $events): self;
    public function exceptEvent(array $events): self;

    // Post-processing
    public function deduplicate(bool $enabled = true): self;
    public function dedupKeyUsing(\Closure $resolver): self;
    public function sortByDateDesc(): self;                                         // default
    public function sortByDateAsc(): self;

    // Caching
    public function cached(int $ttlSeconds): self;

    // Terminal
    /** @return \Illuminate\Support\Collection<int, TimelineEntry> */
    public function get(): \Illuminate\Support\Collection;
    public function paginate(?int $perPage = null, int $page = 1): \Illuminate\Pagination\LengthAwarePaginator;
    public function count(): int;
}
```

### `RelatedModelSource` configuration

```php
$builder->fromRelation('emails', function (RelatedModelSource $s): void {
    $s->with(['user'])
      ->event('created_at',  'email_created',  icon: 'heroicon-o-envelope')
      ->event('sent_at',     'email_sent',     icon: 'heroicon-o-paper-airplane', color: 'primary')
      ->event('received_at', 'email_received', icon: 'heroicon-o-inbox-arrow-down', color: 'info',
              when: fn (Email $e): bool => $e->received_at !== null);
});
```

### Key builder behaviors

- `paginate()` is the primary entry point. Each source is asked for at most `perPage × (page + buffer)` rows via `Window`; merge + dedup + slice in memory.
- `get()` is for tests and small data; emits a debug-mode warning if result set exceeds 500.
- Default source priorities (higher wins dedup): `ActivityLog=10`, `RelatedActivityLog=10`, `RelatedModel=20`, `Custom=30`.
- `dedupKeyUsing()` overrides the per-entry `dedupKey` — applied after sources emit, before the dedup pass.
- `cached()` caches the final paginated slice keyed by `(subject class, subject id, filter hash, page, perPage)`. Invalidation is consumer-driven via `$model->forgetTimelineCache()`; the plugin does not auto-invalidate on writes.

## Sources

### `TimelineSource` contract

```php
interface TimelineSource
{
    public function priority(): int;
    /** @return iterable<TimelineEntry> */
    public function resolve(\Illuminate\Database\Eloquent\Model $subject, Window $window): iterable;
}

final readonly class Window
{
    public function __construct(
        public ?\Carbon\CarbonImmutable $from,
        public ?\Carbon\CarbonImmutable $to,
        public int $cap,
        public ?array $typeAllow,
        public ?array $typeDeny,
        public ?array $eventAllow,
        public ?array $eventDeny,
    ) {}
}
```

Each source honors `Window` filters in SQL where possible. The builder reapplies the same filters after merge as a safety net.

### `ActivityLogSource` (own subject)

- Query: `activity_log WHERE subject_type = ? AND subject_id = ?`, with `between` applied to `created_at`, `cap` applied, `ORDER BY created_at DESC`.
- Emits `type='activity_log'`, `event=activity.event ?? activity.description`, `causer` resolved via `(causer_type, causer_id)`, `properties=activity.properties->toArray()`, `dedupKey='{subject_type}:{subject_id}:{created_at_to_second}'`.
- Default renderer: `ActivityLogRenderer`.

### `RelatedActivityLogSource` (`fromActivityLogOf`)

- For each named relation: one `->pluck('id')` to get related keys, then one batched `activity_log WHERE (subject_type, subject_id) IN (...)` query total.
- `relatedModel` batch-loaded once per source call (an in-memory map built upfront).
- `dedupKey='{related_type}:{related_id}:{created_at_to_second}'` — matches `RelatedModelSource` keys for the same record at the same second.

### `RelatedModelSource` (`fromRelation`)

- One query per configured `(relation, column)` pair, with `when: Closure` applied post-fetch in PHP.
- `with()` eager-loads applied.
- Emits `type='related_model'`, `event=<configured>`, `occurredAt=row.{column}`, `relatedModel=row`, `dedupKey='{related_class}:{row.id}:{occurredAt_to_second}'`.

### `CustomEventSource` (`fromCustom`)

- Wraps `fn (Model $subject, Window $w): iterable<TimelineEntry>`.
- Full consumer responsibility for `dedupKey` + `sourcePriority` (defaults applied if absent). `cap` is advisory.
- Non-`TimelineEntry` return values throw immediately with the offending type.

## Renderer registry

```php
final class RendererRegistry
{
    public function register(string $eventOrType, string|\Closure $renderer): void;
    public function unregister(string $eventOrType): void;
    public function resolve(TimelineEntry $entry): TimelineRenderer;
}

interface TimelineRenderer
{
    public function render(TimelineEntry $entry): \Illuminate\Contracts\View\View|\Illuminate\Support\HtmlString;
}
```

Resolution order for a given entry:

1. `$entry->renderer` set → use it, bypass registry.
2. Registry lookup by `$entry->event`.
3. Registry lookup by `$entry->type`.
4. Fall back to `DefaultRenderer`.

All three input forms (class string, Closure, view name) are normalized to `TimelineRenderer` internally. Class strings are resolved from the container and must implement `TimelineRenderer`.

Registration surfaces:

- Service provider, via facade: `Timeline::registerRenderer('email_sent', EmailSentRenderer::class)`.
- Plugin fluent API, per panel: `ActivityLogPlugin::make()->renderers(['email_sent' => EmailSentRenderer::class])`.

Built-in renderers:

- `DefaultRenderer` — renders `activity-log::entries.default`. Shows icon, humanized event as title, timestamp, optional description.
- `ActivityLogRenderer` — renders `activity-log::entries.activity-log`. Interprets `properties` (attributes/old) for diff-style display.

## Filament surfaces

All three surfaces render the same shared Livewire component `TimelineListLivewire`, which owns `$page`, `$perPage`, `$typeFilter`, and method `setPage/resetFilters`.

### Infolist component — `ActivityLog::make('timeline')`

```php
use Relaticle\ActivityLog\Filament\Infolists\Components\ActivityLog;

ActivityLog::make('timeline')
    ->heading('Activity')
    ->groupByDate()
    ->collapsible()
    ->perPage(20)
    ->using(fn (Model $record) => $record->timeline())  // default: $record->timeline()
    ->emptyState('No activity yet.')
    ->columnSpanFull();
```

- Subclass of `Filament\Infolists\Components\Entry`, view `activity-log::infolist-component`.
- Embeds `TimelineListLivewire` for pagination + filter interactivity without page reload.
- Date grouping applies to the current page only; no cross-page grouping.

### Widget — `ActivityLogWidget`

```php
use Relaticle\ActivityLog\Filament\Widgets\ActivityLogWidget;

class RecentPersonActivityWidget extends ActivityLogWidget
{
    protected static ?string $model = Person::class;
    protected static int $perPage = 10;
    protected static int $sort = 2;
    protected static string $heading = 'Recent customer activity';
}
```

- Extends `Filament\Widgets\Widget`.
- Aggregates across all records of `$model`: runs each subject's builder with `cap = perPage × 2`, merges, dedups, sorts desc, slices to `perPage`. Bounded by `widget.max_subjects` config (default 500).
- Registered via plugin: `ActivityLogPlugin::make()->widgets([RecentPersonActivityWidget::class])`.
- No query-string filter sync (dashboard context).

### Relation manager — `TimelineRelationManager`

- Extends `Filament\Resources\RelationManagers\RelationManager`.
- Not a real Eloquent relation — renders a custom view (`activity-log::relation-manager`) that embeds `TimelineListLivewire`. Provides a dedicated tab with Filament's tab chrome.
- Configured per use via `configureUsing(Closure)` hook (title, icon, `perPage`, `groupByDate`).

### Filter UI

Built into `TimelineListLivewire`, enabled/disabled by surface config:

- Event-type pill filter, built from distinct `event` values in the current result set.
- Optional date-range filter, mapped to builder `between()`.
- Filters are query-string synced on the infolist + relation manager; not synced on the widget.

## `HasTimeline` trait

```php
namespace Relaticle\ActivityLog\Concerns;

trait HasTimeline
{
    public function timeline(): TimelineBuilder
    {
        throw new \LogicException(
            static::class.' uses HasTimeline but does not implement timeline(): TimelineBuilder.'
        );
    }

    public function paginateTimeline(?int $perPage = null, int $page = 1): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->timeline()->paginate($perPage, $page);
    }

    public function forgetTimelineCache(): void
    {
        app(TimelineCache::class)->forget($this);
    }
}
```

The trait contributes the contract (`timeline()` exists) + two helpers. The model owns what goes in the builder.

## Config file — `config/activity-log.php`

```php
return [
    'default_per_page' => 20,
    'pagination_buffer' => 2,
    'deduplicate_by_default' => true,

    'source_priorities' => [
        'activity_log' => 10,
        'related_activity_log' => 10,
        'related_model' => 20,
        'custom' => 30,
    ],

    'date_groups' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'older'],

    'renderers' => [
        // 'email_sent' => \App\Timeline\Renderers\EmailSentRenderer::class,
    ],

    'cache' => [
        'store' => null,
        'ttl_seconds' => 0,
        'key_prefix' => 'activity-log',
    ],

    'widget' => [
        'max_subjects' => 500,
    ],
];
```

## Error handling & edge cases

- **Missing `timeline()` method** — trait's default throws `LogicException` naming the class.
- **Unknown relation in `fromRelation` / `fromActivityLogOf`** — `InvalidArgumentException` from `AbstractTimelineSource::resolveRelation()`, naming model and missing relation.
- **`CustomEventSource` returns non-`TimelineEntry`** — throws immediately with the offending type.
- **Empty results** — `paginate()` returns an empty paginator at the requested page; surfaces render `emptyState`.
- **Unsaved subject model** — `fromActivityLog` + friends throw `DomainException`.
- **Large `properties` arrays** — renderers truncate display; entries carry the full payload.
- **Timezones** — display timestamps are normalized to `CarbonImmutable` in the app's configured timezone at source boundary; `dedupKey` timestamps always use UTC in `Y-m-d\TH:i:s` form to keep keys stable across DST boundaries and request-locale changes.
- **Clock-skew dedup** — acknowledged limitation when two sources emit one second apart for the same record; consumers tune via `dedupKeyUsing()`.

## Performance requirements

- **No N+1 anywhere in core.** Every source batch-loads.
- `ActivityLogSource` / `RelatedActivityLogSource` require the index `(subject_type, subject_id, created_at)` on the `activity_log` table. README instructs consumers to verify/add this index; the plugin does not ship a migration because the table isn't owned by it.
- `RelatedModelSource` queries are bounded by `Window::cap` and expect the configured date column to be indexed.
- Merge + dedup + slice operate on at most `sources × cap` entries — bounded by pagination.
- `->cached(ttl)` keys by `(subject class, subject id, filter hash, page, perPage)`.
- Widget aggregation capped by `widget.max_subjects` config.

## Testing strategy

### Harness

- `tests/TestCase.php` extends `Orchestra\Testbench\TestCase`, registers `ActivityLogServiceProvider` + `Spatie\Activitylog\ActivitylogServiceProvider`, uses in-memory SQLite.
- Fixture models under `tests/Fixtures/Models/`: `Person` (uses `HasTimeline` + `LogsActivity`), `Email`, `Note`, `Task` (all use `LogsActivity`).
- Fixture migrations under `tests/Fixtures/database/migrations/`, loaded via `TestCase::defineDatabaseMigrations()`.
- Factories for all fixtures.

### Coverage — one feature test per concern

1. `TimelineEntryTest` — DTO construction, immutability, default `dedupKey` shape.
2. `ActivityLogSourceTest` — own-subject query, causer resolution, properties pass-through, `Window` honored.
3. `RelatedActivityLogSourceTest` — batched query across multiple relations, `relatedModel` populated.
4. `RelatedModelSourceTest` — multi-event per relation, `when:` filter, eager loads applied.
5. `CustomEventSourceTest` — closure emission, type validation error on non-`TimelineEntry` return.
6. `BuilderDedupTest` — activity_log `created` vs related_model `email_created` at same second → RelatedModel wins by priority; priority override swaps winner; `dedupKeyUsing()` override.
7. `BuilderPaginationTest` — per-source cap math, page 1 vs page 5, bounded memory, correct `total`.
8. `BuilderFilteringTest` — `between`, `ofType`, `exceptEvent`, combined.
9. `RendererRegistryTest` — resolution chain (event → type → default), all three input forms, `$entry->renderer` bypass.
10. `InfolistComponentTest` — Filament + Livewire test: renders, paginates, filters, empty state.
11. `WidgetTest` — cross-subject aggregation, heading, `max_subjects` bound.
12. `RelationManagerTest` — tab renders, Livewire list works.

No isolated unit tests for internal helpers; everything tested through real entry points.

## Implementation order

In dependency order, each step independently verifiable:

1. Package skeleton — `composer.json`, `ActivityLogServiceProvider`, `ActivityLogPlugin`, config file, root `composer.json` path repository wiring.
2. Testbench harness — `TestCase`, fixture migrations + models + factories.
3. `TimelineEntry` DTO + `Window` DTO.
4. `TimelineBuilder` scaffold — sources registration, filters, sort, `get()` without source integration.
5. `ActivityLogSource` (own subject) + test.
6. `RelatedActivityLogSource` + test.
7. `RelatedModelSource` + test.
8. `CustomEventSource` + test.
9. Builder dedup + priority + `dedupKeyUsing()` + test.
10. Builder pagination (per-source cap math) + test.
11. Builder filter passthrough to `Window` + test.
12. `HasTimeline` trait + test.
13. `RendererRegistry`, `DefaultRenderer`, `ActivityLogRenderer`, `Timeline` facade + test.
14. `TimelineListLivewire` — shared rendering, filter UI, grouping.
15. Infolist component `ActivityLog` + default view + test.
16. Dashboard `ActivityLogWidget` + default view + test.
17. `TimelineRelationManager` + default view + test.
18. `TimelineCache` + `->cached()` integration + forget helper + test.
19. README (installation, trait usage, builder API, renderer registration, surface registration, performance tuning, Tailwind `@source` instruction).

## Coding standards

- PSR-12.
- `declare(strict_types=1);` at the top of every PHP file.
- Typed properties and return types everywhere.
- `final` on DTOs and value objects.
- `readonly` on DTOs.
- No facades inside the core `Timeline\` namespace — inject dependencies.
- Docblocks only where types can't express intent (generic collection types).
- Consumer-facing APIs documented with examples in PHPDoc.

## Assets

No compiled JS or CSS. Views use Filament design tokens (CSS variables) + Tailwind utilities. Consumers add to their panel's theme CSS:

```css
@source '../../../../packages/ActivityLog/resources/views/**/*';
```

(Or the `vendor/relaticle/activity-log/...` path once installed via composer.)

Livewire interactivity is provided by the Livewire runtime already present in Filament; the plugin does not ship Livewire itself.
