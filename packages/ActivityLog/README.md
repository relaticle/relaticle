# relaticle/activity-log

A reusable Filament v5 panel plugin that renders a unified chronological timeline for any Eloquent model, aggregating events from `spatie/laravel-activitylog` (own subject and related models), timestamp columns on related models, and custom sources.

## Requirements

- PHP 8.4+
- Laravel 12
- Filament 5
- spatie/laravel-activitylog ^4

## Installation

```bash
composer require relaticle/activity-log
```

Publish config (optional):

```bash
php artisan vendor:publish --tag=activity-log-config
```

## Database

The `activity_log` table should have this index for good performance:

```php
$table->index(['subject_type', 'subject_id', 'created_at']);
```

The plugin does not ship a migration because `activity_log` is owned by `spatie/laravel-activitylog`.

## Usage

### 1. Add the trait to a model

```php
use Relaticle\ActivityLog\Concerns\HasTimeline;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\ActivityLog\Timeline\Sources\RelatedModelSource;

class Person extends Model
{
    use HasTimeline, LogsActivity;

    public function timeline(): TimelineBuilder
    {
        return TimelineBuilder::make($this)
            ->fromActivityLog()
            ->fromActivityLogOf(['emails', 'notes', 'tasks'])
            ->fromRelation('emails', function (RelatedModelSource $s): void {
                $s->event('sent_at', 'email_sent', icon: 'heroicon-o-paper-airplane', color: 'primary')
                  ->event('received_at', 'email_received', icon: 'heroicon-o-inbox-arrow-down', color: 'info');
            });
    }
}
```

### 2. Register the plugin in your panel

```php
use Relaticle\ActivityLog\ActivityLogPlugin;
use App\Widgets\RecentPersonActivityWidget;
use App\Timeline\Renderers\EmailSentRenderer;

return $panel
    ->plugin(
        ActivityLogPlugin::make()
            ->widgets([RecentPersonActivityWidget::class])
            ->renderers([
                'email_sent' => EmailSentRenderer::class,
            ])
    );
```

### 3. Add the infolist component to a Filament resource

```php
use Relaticle\ActivityLog\Filament\Infolists\Components\ActivityLog;

public static function infolist(Schema $schema): Schema
{
    return $schema->components([
        ActivityLog::make('timeline')
            ->heading('Activity')
            ->groupByDate()
            ->perPage(20)
            ->columnSpanFull(),
    ]);
}
```

### 4. Dashboard widget

```php
use Relaticle\ActivityLog\Filament\Widgets\ActivityLogWidget;

class RecentPersonActivityWidget extends ActivityLogWidget
{
    protected function model(): ?string
    {
        return \App\Models\Person::class;
    }

    protected function perPage(): int
    {
        return 10;
    }

    public function getHeading(): string
    {
        return 'Recent customer activity';
    }
}
```

### 5. Relation manager

```php
use Relaticle\ActivityLog\Filament\RelationManagers\TimelineRelationManager;

public static function getRelations(): array
{
    return [TimelineRelationManager::class];
}
```

### 6. Register renderers

```php
use Relaticle\ActivityLog\Facades\Timeline;

// In a ServiceProvider
Timeline::registerRenderer('email_sent', \App\Timeline\Renderers\EmailSentRenderer::class);
Timeline::registerRenderer('note_added', 'my-app::timeline.note-added');
Timeline::registerRenderer('task_completed', fn ($entry) => new HtmlString('...'));
```

Resolution order: `$entry->renderer` -> `event` -> `type` -> `DefaultRenderer`.

## Tailwind

The plugin ships Blade views that use Tailwind utilities. Add them to your panel's theme source list:

```css
/* In your theme.css */
@source '../../../../vendor/relaticle/activity-log/resources/views/**/*';
```

## Caching

Opt-in per-call:

```php
$record->timeline()->cached(ttlSeconds: 300)->paginate();
```

Invalidate when data changes (consumer-driven):

```php
$record->forgetTimelineCache();
```

## Performance notes

- Every source batch-loads; no N+1 in the core path.
- Pagination uses a per-source cap of `perPage x (page + buffer)` (buffer defaults to 2, configurable).
- Widget aggregation capped by `activity-log.widget.max_subjects` (default 500).

## Testing

```bash
cd packages/ActivityLog
composer install
vendor/bin/pest
```
