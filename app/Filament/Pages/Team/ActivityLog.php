<?php

declare(strict_types=1);

namespace App\Filament\Pages\Team;

use App\Enums\CrmEntity;
use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Models\ActivityLog\Activity;
use App\Models\Team;
use App\Models\User;
use App\Support\ActivityLog\ActivityChangeSummary;
use App\Support\ActivityLog\ActivityValue;
use App\Support\CanonicalRecordUrl;
use App\Support\LikePattern;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
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
     * Custom-field edits are logged under their own event name rather than the
     * trait's `updated`, but to an admin they are the same act — so they share a
     * label, and filtering on it has to match both.
     */
    private const array EVENT_ALIASES = [
        'updated' => ['updated', 'custom_field_changes'],
    ];

    private const string CUSTOM_FIELD_EVENT = 'custom_field_changes';

    /** Characters of each side of a diff the table shows before the title takes over. */
    private const int VALUE_LENGTH = 60;

    /** Characters of the full sentence the title carries before it, too, is clipped. */
    private const int TITLE_LENGTH = 240;

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

    /**
     * The search term belongs in the URL for the same reason the filters do, but
     * it cannot get there the same way: Filament's table trait declares
     * `$tableSearch` untyped, so redeclaring it here to carry a `#[Url]` is a
     * fatal property-composition conflict. Livewire's query-string map attaches
     * the same binding without touching the property.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function queryString(): array
    {
        return [
            'tableSearch' => ['as' => 'search', 'except' => ''],
        ];
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
            ->stackedOnMobile()
            ->recordUrl($this->subjectUrl(...))
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->searchPlaceholder(__('teams.activity.search_placeholder'))
            ->emptyStateIcon(fn (): Heroicon => $this->isFiltered() ? Heroicon::OutlinedMagnifyingGlass : Heroicon::OutlinedClock)
            ->emptyStateHeading(fn (): string => $this->isFiltered()
                ? __('teams.activity.no_results.heading')
                : __('teams.activity.empty.heading'))
            ->emptyStateDescription(fn (): string => $this->isFiltered()
                ? __('teams.activity.no_results.description')
                : __('teams.activity.empty.description'))
            ->emptyStateActions([
                Action::make('clearFilters')
                    ->label(__('teams.activity.no_results.action'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (): bool => $this->isFiltered())
                    ->action(function (): void {
                        $this->resetTableSearch();
                        $this->removeTableFilters();
                    }),
            ])
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('teams.activity.columns.created_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label(__('teams.activity.columns.causer'))
                    ->weight(FontWeight::Medium)
                    ->placeholder(__('teams.activity.system')),
                TextColumn::make('event')
                    ->label(__('teams.activity.columns.event'))
                    ->badge()
                    ->icon($this->eventIcon(...))
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        'restored' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing($this->eventLabel(...)),
                TextColumn::make('subject_type')
                    ->label(__('teams.activity.columns.subject_type'))
                    ->color('gray')
                    ->icon($this->typeIcon(...))
                    ->formatStateUsing($this->typeLabel(...)),
                TextColumn::make('subject_id')
                    ->label(__('teams.activity.columns.record'))
                    ->state($this->recordName(...))
                    ->weight(FontWeight::Medium)
                    ->color(fn (Activity $record): ?string => $this->liveSubject($record) instanceof Model ? null : 'gray')
                    ->tooltip($this->destroyedNotice(...))
                    ->searchable(query: $this->searchByRecordName(...))
                    ->wrap(),
                TextColumn::make('batch_uuid')
                    ->label(__('teams.activity.columns.changes'))
                    ->state(ActivityChangeSummary::for(...))
                    ->formatStateUsing($this->changeLine(...))
                    ->listWithLineBreaks()
                    ->limitList(1)
                    ->placeholder(ActivityValue::EMPTY)
                    ->action($this->viewChangesAction()),
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
                        ->when(filled($data['until'] ?? null), fn (Builder $q): Builder => $q->whereDate('activity_log.created_at', '<=', $data['until'])))
                    ->indicateUsing($this->dateIndicators(...)),
            ]);
    }

    /**
     * A save that moved one field is the common case and reads inline. Anything
     * longer would stretch the row and truncate the values that make it worth
     * reading, so the rest opens in a slide-over where the whole diff fits.
     */
    private function viewChangesAction(): Action
    {
        return Action::make('viewChanges')
            ->label(__('teams.activity.changes_modal.trigger'))
            ->slideOver()
            ->modalHeading(fn (Activity $record): string => $this->recordName($record))
            ->modalDescription($this->changesDescription(...))
            ->modalIcon(fn (Activity $record): Heroicon => $this->eventIcon($record->event))
            ->modalContent(fn (Activity $record): View => view('filament.pages.team.activity-changes', [
                'rows' => ActivityChangeSummary::for($record),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('teams.activity.changes_modal.close'))
            ->visible(fn (Activity $record): bool => count(ActivityChangeSummary::for($record)) > 1);
    }

    /**
     * The row's own timestamp is converted for the reader by the column, which
     * reads `FilamentTimezone` itself. Nothing does that for a string built by
     * hand, so the same instant printed here has to be converted here too.
     */
    private function changesDescription(Activity $record): string
    {
        $causer = $record->causer?->getAttribute('name');

        return implode(' · ', [
            $this->typeLabel($record->subject_type),
            $this->eventLabel($record->event),
            is_string($causer) && $causer !== '' ? $causer : __('teams.activity.system'),
            $record->created_at?->copy()->setTimezone(FilamentTimezone::get())->toDayDateTimeString() ?? ActivityValue::EMPTY,
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
     * A destroyed record survives only in its own payload, and a record whose
     * name never moved was never written into one — so neither source alone can
     * answer "show me every row about Acme". Both are searched.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function searchByRecordName(Builder $query, string $search): Builder
    {
        $term = '%'.LikePattern::escape($search).'%';

        $query->where(fn (Builder $logged): Builder => $logged
            ->whereRaw("activity_log.attribute_changes #>> '{attributes,name}' ilike ?", [$term])
            ->orWhereRaw("activity_log.attribute_changes #>> '{attributes,title}' ilike ?", [$term])
            ->orWhereRaw("activity_log.attribute_changes #>> '{old,name}' ilike ?", [$term])
            ->orWhereRaw("activity_log.attribute_changes #>> '{old,title}' ilike ?", [$term]));

        $tenant = Filament::getTenant();

        if (! $tenant instanceof Team) {
            return $query;
        }

        foreach (CrmEntity::cases() as $entity) {
            $query->orWhere(fn (Builder $live): Builder => $live
                ->where('activity_log.subject_type', $entity->value)
                ->whereIn('activity_log.subject_id', fn (QueryBuilder $records): QueryBuilder => $records
                    ->select('id')
                    ->from($entity->table())
                    ->where('team_id', $tenant->getKey())
                    ->where($entity->titleColumn(), 'ilike', $term)));
        }

        return $query;
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

    private function destroyedNotice(Activity $record): ?string
    {
        if ($this->liveSubject($record) instanceof Model) {
            return null;
        }

        return __('teams.activity.record_destroyed');
    }

    /**
     * Tasks and notes have no record page of their own, so their canonical URL
     * is the index deep link that opens the edit modal — the same URL the search
     * tools and digest emails publish, built by the same class.
     */
    private function subjectUrl(Activity $record): ?string
    {
        $entity = CrmEntity::tryFrom((string) $record->subject_type);
        $tenant = Filament::getTenant();

        if (! $entity instanceof CrmEntity || ! $tenant instanceof Team) {
            return null;
        }

        if (! $this->liveSubject($record) instanceof Model) {
            return null;
        }

        return (new CanonicalRecordUrl)->build($entity, (string) $record->subject_id, $tenant);
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

    /**
     * Reads as one sentence — field, what it was, what it became — with the
     * before struck through so the after is what the eye lands on. The title
     * carries the same sentence in plain text, which is what a reader gets back
     * when a long value wraps or is clipped. It is capped too: a rich-editor
     * body arrives here stripped but not shortened, and uncapped it would ship
     * the whole note into the title of every row on the page.
     *
     * @param  array{label: string, old: string, new: string}  $state
     */
    private function changeLine(array $state): HtmlString
    {
        return new HtmlString(view('filament.pages.team.activity-line', [
            'label' => $state['label'],
            'old' => Str::limit($state['old'], self::VALUE_LENGTH),
            'new' => Str::limit($state['new'], self::VALUE_LENGTH),
            'title' => Str::limit($state['label'].': '.$state['old'].' → '.$state['new'], self::TITLE_LENGTH),
        ])->render());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<Indicator>
     */
    private function dateIndicators(array $data): array
    {
        $indicators = [];

        foreach (['from', 'until'] as $field) {
            $value = $data[$field] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            $indicators[] = Indicator::make(__('teams.activity.filters.'.$field).': '.$value)
                ->removeField($field);
        }

        return $indicators;
    }

    /**
     * The indicator bar is already the answer to "is anything narrowing this
     * table": it unions the search term, the per-column searches and every
     * filter's own indicators, so the empty state cannot disagree with the
     * chips the reader can see above it.
     */
    private function isFiltered(): bool
    {
        return $this->getTable()->getFilterIndicators() !== [];
    }

    private function eventLabel(?string $state): string
    {
        if ($state === null) {
            return ActivityValue::EMPTY;
        }

        if ($state === self::CUSTOM_FIELD_EVENT) {
            return __('teams.activity.events.updated');
        }

        return $this->eventOptions()[$state] ?? Str::headline(str_replace('.', ' ', $state));
    }

    private function eventIcon(?string $state): Heroicon
    {
        return match ($state) {
            'created' => Heroicon::PlusCircle,
            'deleted' => Heroicon::Trash,
            'restored' => Heroicon::ArrowUturnLeft,
            default => Heroicon::PencilSquare,
        };
    }

    private function typeLabel(?string $state): string
    {
        if ($state === null) {
            return ActivityValue::EMPTY;
        }

        return $this->typeOptions()[$state] ?? Str::headline($state);
    }

    private function typeIcon(?string $state): ?Heroicon
    {
        return match (CrmEntity::tryFrom((string) $state)) {
            CrmEntity::Company => Heroicon::BuildingOffice,
            CrmEntity::People => Heroicon::User,
            CrmEntity::Opportunity => Heroicon::CurrencyDollar,
            CrmEntity::Task => Heroicon::ClipboardDocumentCheck,
            CrmEntity::Note => Heroicon::DocumentText,
            null => null,
        };
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
     * Keyed by morph alias, which is what `subject_type` holds.
     *
     * @return array<string, string>
     */
    private function typeOptions(): array
    {
        $labels = [];

        foreach (CrmEntity::cases() as $entity) {
            $labels[$entity->value] = __('teams.activity.types.'.$entity->value);
        }

        return $labels;
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
