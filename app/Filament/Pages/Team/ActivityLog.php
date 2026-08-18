<?php

declare(strict_types=1);

namespace App\Filament\Pages\Team;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Models\ActivityLog\Activity;
use App\Models\Team;
use App\Models\User;
use App\Support\ActivityLog\ActivityChangeSummary;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Override;

/**
 * The workspace audit trail: who changed or deleted which record, and when.
 *
 * A record's own timeline disappears with the record on a permanent delete, so
 * this page reads the activity rows directly — they outlive their subject, and
 * the name captured in the delete payload still identifies what was destroyed.
 */
final class ActivityLog extends Page implements HasTable
{
    use HasWorkspaceSettingsNavigation;
    use InteractsWithTable;

    /**
     * Only the record types with a view page can be linked to; tasks and notes
     * are managed from their list screens and have no record route.
     */
    private const array SUBJECT_RESOURCES = [
        'company' => CompanyResource::class,
        'people' => PeopleResource::class,
        'opportunity' => OpportunityResource::class,
    ];

    /**
     * Custom-field edits are logged under their own event name rather than the
     * trait's `updated`, but to an admin they are the same act — so they share a
     * label, and filtering on it has to match both.
     */
    private const array EVENT_ALIASES = [
        'updated' => ['updated', 'custom_field_changes'],
    ];

    private const string CUSTOM_FIELD_EVENT = 'custom_field_changes';

    private const string NOTHING = '—';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $slug = 'team/activity';

    protected string $view = 'filament.pages.team.activity-log';

    /**
     * Filament binds these on resource list pages but not on a plain page, so a
     * filtered audit view could not be reloaded, bookmarked, or handed to a
     * colleague. Declared here to match every other table in the app.
     *
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    /** @var array<string, Model>|null */
    private ?array $liveSubjects = null;

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Team) {
            return false;
        }

        $user = auth()->user();

        return $user instanceof User && $user->hasTeamRoleForTeamId($tenant->getKey(), 'admin');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public static function getLabel(): string
    {
        return __('teams.tabs.activity');
    }

    public function getTitle(): string
    {
        return __('teams.tabs.activity');
    }

    public function getSubheading(): string
    {
        return __('teams.activity.description');
    }

    /**
     * One save writes the trait's own event plus one `custom_field_changes` row
     * per custom field that moved, all sharing a `batch_uuid`. Reporting them
     * all put a phantom update next to every create, so they collapse onto a
     * single surviving row — the native event when there is one, otherwise the
     * first custom-field row — which carries every sibling payload with it.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(
                Activity::query()
                    ->select('activity_log.*')
                    ->addSelect(['batch_custom_field_properties' => $this->sameSave(DB::table('activity_log', 'sibling'))
                        ->selectRaw('json_agg(sibling.properties order by sibling.id)')
                        ->where('sibling.event', self::CUSTOM_FIELD_EVENT),
                    ])
                    ->whereNot(fn (Builder $query): Builder => $query
                        ->where('event', self::CUSTOM_FIELD_EVENT)
                        ->whereNotNull('batch_uuid')
                        ->whereExists(fn (QueryBuilder $sibling): QueryBuilder => $this->sameSave($sibling)
                            ->whereRaw(
                                '(case when sibling.event = ? then 1 else 0 end, sibling.id) < (1, activity_log.id)',
                                [self::CUSTOM_FIELD_EVENT],
                            )))
                    ->with('causer')
            )
            ->defaultSort('created_at', 'desc')
            ->recordUrl($this->subjectUrl(...))
            ->emptyStateHeading(__('teams.activity.empty.heading'))
            ->emptyStateDescription(__('teams.activity.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedClock)
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('teams.activity.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label(__('teams.activity.columns.causer'))
                    ->placeholder(__('teams.activity.system')),
                TextColumn::make('event')
                    ->label(__('teams.activity.columns.event'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        'restored' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing($this->eventLabel(...)),
                TextColumn::make('subject_type')
                    ->label(__('teams.activity.columns.subject_type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing($this->typeLabel(...)),
                TextColumn::make('subject_id')
                    ->label(__('teams.activity.columns.record'))
                    ->state($this->recordName(...))
                    ->wrap(),
                TextColumn::make('batch_uuid')
                    ->label(__('teams.activity.columns.changes'))
                    ->state(ActivityChangeSummary::for(...))
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder(self::NOTHING)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('teams.activity.filters.event'))
                    ->options($this->eventOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereIn('event', self::EVENT_ALIASES[$data['value']] ?? [$data['value']])
                        : $query),
                SelectFilter::make('subject_type')
                    ->label(__('teams.activity.filters.subject_type'))
                    ->options($this->typeOptions()),
                SelectFilter::make('causer')
                    ->label(__('teams.activity.filters.causer'))
                    ->options($this->causerOptions(...))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('causer_type', 'user')->where('causer_id', $data['value'])
                        : $query),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('teams.activity.filters.from')),
                        DatePicker::make('until')->label(__('teams.activity.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $q): Builder => $q->whereDate('activity_log.created_at', '>=', $data['from']))
                        ->when(filled($data['until'] ?? null), fn (Builder $q): Builder => $q->whereDate('activity_log.created_at', '<=', $data['until']))),
            ]);
    }

    /**
     * Rows written for one record inside one request. The batch is stamped per
     * request, not per save, so a request touching several records shares it —
     * the subject columns are what keep one record's payload off another's row.
     *
     * Only ever used to fold a `custom_field_changes` row into its native
     * sibling. Collapsing the batch itself would swallow a genuine event, since
     * a single request can legitimately create and then delete a record.
     */
    private function sameSave(QueryBuilder $sibling): QueryBuilder
    {
        return $sibling
            ->from('activity_log', 'sibling')
            ->whereColumn('sibling.batch_uuid', 'activity_log.batch_uuid')
            ->whereColumn('sibling.team_id', 'activity_log.team_id')
            ->whereColumn('sibling.subject_type', 'activity_log.subject_type')
            ->whereColumn('sibling.subject_id', 'activity_log.subject_id');
    }

    /**
     * The name the record carried at the time of the event wins. Reading the
     * live record instead would rewrite history on every rename — and there is
     * no live record left to read once it has been destroyed.
     */
    private function recordName(Activity $record): string
    {
        $changes = $record->attribute_changes?->toArray() ?? [];

        foreach (['attributes', 'old'] as $side) {
            $values = $changes[$side] ?? null;

            if (! is_array($values)) {
                continue;
            }

            $name = $values['name'] ?? $values['title'] ?? null;

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        $subject = $this->liveSubject($record);

        if ($subject instanceof Model) {
            $name = $subject->getAttribute('name') ?? $subject->getAttribute('title');

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return '#'.$record->subject_id;
    }

    private function subjectUrl(Activity $record): ?string
    {
        $resource = self::SUBJECT_RESOURCES[$record->subject_type] ?? null;

        if ($resource === null || ! $this->liveSubject($record) instanceof Model) {
            return null;
        }

        return $resource::getUrl('view', ['record' => $record->subject_id]);
    }

    /**
     * Resolved for the whole page at once rather than through `subject`, because
     * a morph alias whose model has since been removed from the codebase — a
     * stale row from a retired feature — makes eager loading the relation throw
     * and takes the entire audit log down with it. Unknown aliases simply have
     * no live record; the row still renders from its own payload.
     */
    private function liveSubject(Activity $record): ?Model
    {
        $this->liveSubjects ??= $this->resolveLiveSubjects();

        return $this->liveSubjects[$record->subject_type.':'.$record->subject_id] ?? null;
    }

    /**
     * @return array<string, Model>
     */
    private function resolveLiveSubjects(): array
    {
        $this->liveSubjects = [];

        $morphMap = Relation::morphMap();
        $idsByType = [];

        $records = $this->getTableRecords();

        foreach ($records instanceof Collection ? $records->all() : $records->items() as $row) {
            $type = (string) $row->getAttribute('subject_type');

            if (isset($morphMap[$type])) {
                $idsByType[$type][] = $row->getAttribute('subject_id');
            }
        }

        $resolved = [];

        foreach ($idsByType as $type => $ids) {
            /** @var class-string<Model> $model */
            $model = $morphMap[$type];

            $subjects = $model::query()
                ->withoutGlobalScopes([SoftDeletingScope::class])
                ->whereKey(array_values(array_unique($ids)))
                ->get();

            foreach ($subjects as $subject) {
                $resolved[$type.':'.$subject->getKey()] = $subject;
            }
        }

        return $resolved;
    }

    private function eventLabel(?string $state): string
    {
        if ($state === null) {
            return '—';
        }

        if ($state === 'custom_field_changes') {
            return __('teams.activity.events.updated');
        }

        return $this->eventOptions()[$state] ?? Str::headline(str_replace('.', ' ', $state));
    }

    private function typeLabel(?string $state): string
    {
        if ($state === null) {
            return '—';
        }

        return $this->typeOptions()[$state] ?? Str::headline($state);
    }

    /**
     * @return array<string, string>
     */
    private function eventOptions(): array
    {
        return [
            'created' => __('teams.activity.events.created'),
            'updated' => __('teams.activity.events.updated'),
            'deleted' => __('teams.activity.events.deleted'),
            'restored' => __('teams.activity.events.restored'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function typeOptions(): array
    {
        return [
            'company' => __('teams.activity.types.company'),
            'people' => __('teams.activity.types.people'),
            'opportunity' => __('teams.activity.types.opportunity'),
            'task' => __('teams.activity.types.task'),
            'note' => __('teams.activity.types.note'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function causerOptions(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Team) {
            return [];
        }

        return $tenant->allUsers()
            ->sortBy('name')
            ->mapWithKeys(fn (User $user): array => [(string) $user->getKey() => $user->name])
            ->all();
    }
}
