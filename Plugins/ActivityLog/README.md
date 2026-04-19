# relaticle/activity-log

<img src="art/activity-log-cover.jpg?v=1" alt="Activity Log" width="800">

A reusable Filament v5 plugin that renders a unified chronological timeline for any Eloquent model. It aggregates events from `spatie/laravel-activitylog` (both the model's own log and logs of its related models), timestamp columns on related models (e.g. `emails.sent_at`, `tasks.completed_at`), and any custom source you define.

The plugin ships an infolist component, a relation manager, a header action, a Filament plugin for registering custom renderers, and a facade for registering renderers outside a panel.

---

## Table of contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Quick start](#quick-start)
4. [Core concepts](#core-concepts)
5. [Data sources](#data-sources)
6. [Filtering, sorting, dedup](#filtering-sorting-dedup)
7. [Filament UI integrations](#filament-ui-integrations)
8. [Custom renderers](#custom-renderers)
9. [Caching](#caching)
10. [Configuration reference](#configuration-reference)
11. [Tailwind](#tailwind)
12. [Performance notes](#performance-notes)
13. [Testing](#testing)

---

## Requirements

- PHP 8.4+
- Laravel 12
- Filament 5
- `spatie/laravel-activitylog` ^5

## Installation

### 1. Require the package

```bash
composer require relaticle/activity-log
```

The service provider (`Relaticle\ActivityLog\ActivityLogServiceProvider`) is auto-discovered. It registers:

- Config file (`config/activity-log.php`)
- Blade views namespaced as `activity-log::*`
- Translations (under the `activity-log::messages.*` namespace)
- `RendererRegistry` and `TimelineCache` singletons
- A Livewire component registered as `activity-log`
- The built-in `activity_log` renderer

### 2. Publish the config (optional)

```bash
php artisan vendor:publish --tag=activity-log-config
```

### 3. Ensure the `activity_log` table is indexed

The plugin does not ship a migration (the table is owned by `spatie/laravel-activitylog`). For good performance on timeline queries, add this compound index:

```php
$table->index(['subject_type', 'subject_id', 'created_at']);
```

### 4. Add the Tailwind source (for custom panel themes)

If your panel uses a custom `theme.css`, add the plugin's views to its source list so Tailwind compiles the utilities used by the Blade templates:

```css
/* resources/css/filament/{panel}/theme.css */
@source '../../../../vendor/relaticle/activity-log/resources/views/**/*';
```

---

## Quick start

### 1. Mark the model as timeline-capable

Implement the `HasTimeline` contract, use the `InteractsWithTimeline` trait for the helper methods, and define a `timeline(): TimelineBuilder` method:

```php
use Illuminate\Database\Eloquent\Model;
use Relaticle\ActivityLog\Concerns\InteractsWithTimeline;
use Relaticle\ActivityLog\Contracts\HasTimeline;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\ActivityLog\Timeline\Sources\RelatedModelSource;
use Spatie\Activitylog\Traits\LogsActivity;

class Person extends Model implements HasTimeline
{
    use InteractsWithTimeline;
    use LogsActivity;

    public function timeline(): TimelineBuilder
    {
        return TimelineBuilder::make($this)
            ->fromActivityLog()
            ->fromActivityLogOf(['emails', 'notes', 'tasks'])
            ->fromRelation('emails', function (RelatedModelSource $source): void {
                $source
                    ->event('sent_at', 'email_sent', icon: 'heroicon-o-paper-airplane', color: 'primary')
                    ->event('received_at', 'email_received', icon: 'heroicon-o-inbox-arrow-down', color: 'info')
                    ->title(fn ($email): string => $email->subject ?? 'Email')
                    ->causer(fn ($email) => $email->from->first());
            });
    }
}
```

### 2. Render the timeline on the resource's view page

```php
use Filament\Schemas\Schema;
use Relaticle\ActivityLog\Filament\Infolists\Components\ActivityLog;

public static function infolist(Schema $schema): Schema
{
    return $schema->components([
        ActivityLog::make('activity')
            ->heading('Activity')
            ->groupByDate()
            ->perPage(20)
            ->columnSpanFull(),
    ]);
}
```

That's the minimum setup. The sections below cover customization.

---

## Core concepts

| Concept | What it represents |
| --- | --- |
| **`TimelineBuilder`** | Fluent builder that composes **sources**, applies **filters**, and returns paginated `TimelineEntry` collections. Built per-record via `$record->timeline()`. |
| **`TimelineSource`** | Produces `TimelineEntry` objects from a specific origin (spatie log, related timestamps, custom closure). Implementations: `ActivityLogSource`, `RelatedActivityLogSource`, `RelatedModelSource`, `CustomEventSource`. |
| **`TimelineEntry`** | Immutable value object describing a single event: `event`, `occurredAt`, `title`, `description`, `icon`, `color`, `subject`, `causer`, `relatedModel`, `properties`, plus an optional `renderer` key. |
| **`TimelineRenderer`** | Converts a `TimelineEntry` into a Blade `View` or `HtmlString`. The default renderer handles every entry; you register custom renderers per `event` or `type`. |
| **Priority** | Each source carries a priority. When two entries share a `dedupKey`, the higher-priority one wins. Defaults: `activity_log`=10, `related_activity_log`=10, `related_model`=20, `custom`=30. |

---

## Data sources

All sources are registered fluently on `TimelineBuilder`. You can mix any number of them in one timeline.

### `fromActivityLog()` — the record's own spatie log

```php
TimelineBuilder::make($this)->fromActivityLog();
```

Reads rows from `activity_log` where `subject_type` + `subject_id` match `$this`. Entry `event` = the spatie `event` column (or `description` as fallback).

### `fromActivityLogOf(array $relations)` — related models' spatie logs

```php
TimelineBuilder::make($this)->fromActivityLogOf(['emails', 'notes', 'tasks']);
```

For each named relation, reads `activity_log` rows whose subject matches any related record. Useful for "show me everything that happened to anything attached to this person."

### `fromRelation(string $relation, Closure $configure)` — timestamp columns

Turns rows on a related model into timeline entries keyed by a timestamp column. Ideal when related records already carry canonical timestamps (`sent_at`, `completed_at`, `created_at`) and you don't need spatie-style change logs.

```php
->fromRelation('tasks', function (RelatedModelSource $source): void {
    $source
        ->event('completed_at', 'task_completed', icon: 'heroicon-o-check-circle', color: 'success')
        ->event('created_at', 'task_created', icon: 'heroicon-o-plus-circle')
        ->with(['creator', 'assignee'])                               // eager loads
        ->using(fn ($query) => $query->whereNull('archived_at'))      // extra constraints
        ->title(fn ($task): string => $task->title ?? 'Task')
        ->description(fn ($task): ?string => $task->summary)
        ->causer('creator');                                           // relation name or Closure
})
```

`RelatedModelSource` API:

| Method | Purpose |
| --- | --- |
| `event(string $column, string $event, ?string $icon, ?string $color, ?Closure $when)` | Register one event per timestamp column. `when` is an optional row-level filter (return `bool`). |
| `with(array $relations)` | Eager-loads relations on every event query — prevents N+1 in renderers. |
| `using(Closure $modifier)` | Arbitrary query modifier (scope injection, tenant scoping, etc.). |
| `title(Closure)` / `description(Closure)` | Per-row resolver for display fields. |
| `causer(Closure\|string)` | Resolves the actor. `string` is a relation name on the row; closure returns a `Model` (or `null`). |

### `fromCustom(Closure $resolver)` — anything else

When the data isn't in `activity_log` and isn't a relation (e.g. entries coming from an external API), yield your own `TimelineEntry` objects:

```php
->fromCustom(function (Model $subject, Window $window): iterable {
    foreach (ExternalApi::events($subject, $window->from, $window->to, $window->cap) as $row) {
        yield new TimelineEntry(
            id: 'external:'.$row['id'],
            type: 'custom',
            event: $row['event'],
            occurredAt: CarbonImmutable::parse($row['at']),
            dedupKey: 'external:'.$row['id'],
            sourcePriority: 30,
            title: $row['title'],
        );
    }
})
```

### `addSource(TimelineSource $source)` — drop-in custom sources

For reusable sources, implement `Relaticle\ActivityLog\Contracts\TimelineSource` and pass it directly. Useful when the resolution logic warrants its own class.

---

## Filtering, sorting, dedup

All methods are chainable on `TimelineBuilder`:

```php
$record->timeline()
    ->between(now()->subMonth(), now())            // CarbonInterface|null on each side
    ->ofType(['related_model', 'activity_log'])     // allow-list
    ->exceptType(['custom'])                        // deny-list
    ->ofEvent(['email_sent', 'task_completed'])
    ->exceptEvent(['draft_saved'])
    ->sortByDateDesc()                              // default; use sortByDateAsc() for ascending
    ->deduplicate(false)                            // default: true
    ->dedupKeyUsing(fn ($entry) => $entry->type.':'.$entry->event.':'.$entry->occurredAt->toDateString())
    ->paginate(perPage: 20, page: 1);
```

Dedup behaviour: entries sharing a `dedupKey` collapse to the highest `sourcePriority` (first occurrence wins on ties). Override the key with `dedupKeyUsing()` if the default identity isn't right for your use case.

### Methods that run the query

| Method | Returns |
| --- | --- |
| `get()` | `Collection<int, TimelineEntry>` — all entries up to the internal 10 000 cap. |
| `paginate(?int $perPage, int $page = 1)` | `LengthAwarePaginator<int, TimelineEntry>`. Uses `activity-log.default_per_page` if `$perPage` is null. |
| `count()` | `int` (runs `get()`). |

---

## Filament UI integrations

### Infolist component

One infolist entry is shipped. It calls `$record->timeline()` and requires `HasTimeline`.

```php
use Relaticle\ActivityLog\Filament\Infolists\Components\ActivityLog;

ActivityLog::make('activity')
    ->heading('Activity')
    ->groupByDate()                  // group by today / yesterday / this week / last week / this month / older (default: true)
    ->perPage(20)                    // per-page for the Livewire component (default: 3)
    ->emptyState('No activity yet.') // custom empty-state message
    ->infiniteScroll(true)           // true = wire:intersect (default); false = "Load more" button
    ->columnSpanFull();
```

### Pagination UX: `infiniteScroll(bool)`

The `infiniteScroll()` fluent flag switches the bottom control:

- `true` (default) — renders a `wire:intersect` sentinel; the next page loads automatically as the user scrolls (Livewire 4).
- `false` — renders a `Load more` button the user clicks.

### Relation manager

A read-only relation manager renders the activity log as a tab on the resource's view/edit page:

```php
use Relaticle\ActivityLog\Filament\RelationManagers\ActivityLogRelationManager;

public static function getRelations(): array
{
    return [ActivityLogRelationManager::class];
}
```

`canViewForRecord()` always returns `true`. It declares a dummy `HasOne` relationship so it doesn't write to the DB — the page just hosts the Livewire component.

The relation manager carries a `protected static bool $infiniteScroll = true` that is forwarded to the Livewire component. Flip it from a service provider if you want the opposite UX:

```php
ActivityLogRelationManager::$infiniteScroll = false;
```

### Header action

Show the activity log in a slide-over modal from any resource table or page header:

```php
use Relaticle\ActivityLog\Filament\Actions\ActivityLogAction;

protected function getHeaderActions(): array
{
    return [
        ActivityLogAction::make(),
    ];
}
```

The action opens a 2XL slide-over with the Livewire component. Customize label/icon/modal width as with any Filament action.

---

## Custom renderers

Out of the box, entries from spatie's activity log render via the built-in `ActivityLogRenderer` (which understands `updated`/`created`/etc. events and renders field diffs), and everything else falls back to `DefaultRenderer` (title, description, causer, relative time, colored icon). For branded output per event type, register a custom renderer.

### Registering via the panel plugin

```php
use Relaticle\ActivityLog\Filament\ActivityLogPlugin;

$panel->plugin(
    ActivityLogPlugin::make()->renderers([
        'email_sent'   => \App\Timeline\Renderers\EmailSentRenderer::class,
        'note_added'   => 'my-app::timeline.note-added',          // view name
        'task_done'    => fn ($entry) => new HtmlString('...'),   // closure
    ]),
);
```

### Registering via the facade (e.g., from a service provider)

```php
use Relaticle\ActivityLog\Facades\Timeline;

Timeline::registerRenderer('email_sent', \App\Timeline\Renderers\EmailSentRenderer::class);
Timeline::registerRenderer('note_added', 'my-app::timeline.note-added');
Timeline::registerRenderer('task_done', fn ($entry) => new HtmlString('...'));
```

### Registering via config

```php
// config/activity-log.php
'renderers' => [
    'email_sent' => \App\Timeline\Renderers\EmailSentRenderer::class,
],
```

### Renderer resolution order

For each `TimelineEntry`, the registry checks:

1. `$entry->renderer` (an explicit override the source set)
2. `bindings[$entry->event]`
3. `bindings[$entry->type]`
4. `DefaultRenderer` fallback

### Renderer forms

A renderer binding can be any of:

- **Class string** implementing `Relaticle\ActivityLog\Contracts\TimelineRenderer`
- **Closure** `fn (TimelineEntry $entry): View|HtmlString => ...`
- **View name** (e.g., `'my-app::timeline.email-sent'`) — receives `$entry` in scope

```php
final class EmailSentRenderer implements \Relaticle\ActivityLog\Contracts\TimelineRenderer
{
    public function render(\Relaticle\ActivityLog\Timeline\TimelineEntry $entry): \Illuminate\Contracts\View\View
    {
        return view('app.timeline.email-sent', ['entry' => $entry]);
    }
}
```

---

## Caching

Opt-in per call — disabled by default.

```php
$record->timeline()->cached(ttlSeconds: 300)->paginate();
```

Invalidate when mutations occur (consumer-driven; the plugin doesn't observe your models):

```php
$record->forgetTimelineCache();
```

Configure the cache store and key prefix in `config/activity-log.php` under `cache`.

---

## Configuration reference

```php
// config/activity-log.php
return [
    // Default page size when ->perPage() isn't called.
    'default_per_page' => 20,

    // Per-source over-fetch buffer: cap = perPage * (page + buffer).
    // Higher = safer dedup/filtering at higher pages; more DB work.
    'pagination_buffer' => 2,

    // Whether dedup is on by default (builder->deduplicate(bool) overrides).
    'deduplicate_by_default' => true,

    // Per-source priority. Higher wins on dedup collisions.
    'source_priorities' => [
        'activity_log'         => 10,
        'related_activity_log' => 10,
        'related_model'        => 20,
        'custom'               => 30,
    ],

    // Labels the infolist component uses when ->groupByDate() is enabled.
    'date_groups' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'older'],

    // Event-or-type → renderer binding. Merged with bindings from the plugin/facade.
    'renderers' => [
        // 'email_sent' => \App\Timeline\Renderers\EmailSentRenderer::class,
    ],

    'cache' => [
        'store'       => null,           // null = default cache store
        'ttl_seconds' => 0,              // 0 = no caching (use ->cached() per call)
        'key_prefix'  => 'activity-log',
    ],
];
```

---

## Tailwind

The plugin's Blade views use Tailwind utilities. If your panel has a compiled theme, include the plugin's views in its source list:

```css
/* resources/css/filament/{panel}/theme.css */
@source '../../../../vendor/relaticle/activity-log/resources/views/**/*';
```

---

## Performance notes

- Every source batch-loads; no N+1 in the core path. Use `->with([...])` on `RelatedModelSource` if your renderer/title resolver reads relations.
- Pagination over-fetches by `perPage × (page + pagination_buffer)` per source so dedup/filtering stays correct at higher pages. Tune `pagination_buffer` if your sources rarely collide.
- `get()` is capped at 10 000 entries. For unbounded history, paginate.
- Add the `['subject_type', 'subject_id', 'created_at']` compound index on `activity_log`.

---

## Testing

```bash
cd Plugins/ActivityLog
composer install
vendor/bin/pest
```

The package ships fixtures (`Person`, `Email`, `Note`, `Task`) in `tests/Fixtures/` and uses Orchestra Testbench for isolation.
