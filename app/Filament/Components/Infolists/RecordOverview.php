<?php

declare(strict_types=1);

namespace App\Filament\Components\Infolists;

use App\Enums\CrmEntity;
use App\Enums\CustomFields\NoteField;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Support\PlainText;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Relaticle\ActivityLog\Filament\Livewire\ActivityLogLivewire;

final readonly class RecordOverview
{
    public const string NEXT_TASK = 'next_task';

    public const string LAST_INTERACTION = 'last_interaction_at';

    public const string STAGE_PROGRESS = 'stage_progress';

    public const string EDIT_TASK_ACTION = 'editTask';

    public const string EDIT_NOTE_ACTION = 'editNote';

    private const int LISTED_ITEMS = 3;

    /**
     * @param  array<int, Entry>  $nativeEntries
     * @param  array<int, string>  $highlights
     * @param  array{activity: ?string, notes: ?string, tasks: ?string, emails: ?string}  $tabKeys
     * @param  array{notes: ?string, tasks: ?string, emails: ?string}  $counts
     * @return array<int, Component>
     */
    public static function components(Company|People|Opportunity $record, array $nativeEntries, array $highlights, array $tabKeys, array $counts): array
    {
        $openTasks = self::openTasks($record);

        return [
            Group::make([
                RecordDetails::highlights(self::highlightEntries($record, $nativeEntries, $highlights, $openTasks)),
                self::activity($record, $tabKeys['activity']),
                View::make('filament.app.record-overview-related')->viewData([
                    'sections' => self::relatedSections($record, $tabKeys, $counts, $openTasks),
                ]),
            ])
                ->model($record)
                ->extraAttributes(['class' => 'fi-record-overview']),
        ];
    }

    /**
     * @param  array<int, Entry>  $nativeEntries
     * @param  array<int, string>  $names
     * @param  Collection<int, array{id: string, title: string, due: ?CarbonInterface, assignee: ?User}>  $openTasks
     * @return array<int, Entry>
     */
    private static function highlightEntries(Company|People|Opportunity $record, array $nativeEntries, array $names, Collection $openTasks): array
    {
        $entries = RecordDetails::entries($record, $nativeEntries);

        return collect($names)
            ->map(fn (string $name): ?Entry => match ($name) {
                self::NEXT_TASK => self::nextTaskEntry($openTasks->first()),
                self::LAST_INTERACTION => self::lastInteractionEntry()->state($record->getAttribute(self::LAST_INTERACTION)),
                self::STAGE_PROGRESS => $record instanceof Opportunity ? self::stageEntry($record) : null,
                default => self::withRecordState($entries->get($name), $record),
            })
            ->filter()
            ->values()
            ->all();
    }

    private static function withRecordState(?Entry $entry, Company|People|Opportunity $record): ?Entry
    {
        if (! $entry instanceof Entry || str_starts_with($entry->getName(), 'custom_fields.')) {
            return $entry;
        }

        return $entry->state(data_get($record, $entry->getName()));
    }

    /**
     * @param  array{id: string, title: string, due: ?CarbonInterface, assignee: ?User}|null  $task
     */
    private static function nextTaskEntry(?array $task): ViewEntry
    {
        return ViewEntry::make(self::NEXT_TASK)
            ->label(__('filament/record-page.overview.next_task'))
            ->beforeLabel(Icon::make(CrmEntity::Task->icon()))
            ->view('filament.app.record-overview-next-task', [
                'task' => $task,
                'due' => $task === null || $task['due'] === null ? null : self::due($task['due']),
                'open' => $task === null ? null : self::mountRecordAction(self::EDIT_TASK_ACTION, ['task' => $task['id']]),
            ]);
    }

    private static function lastInteractionEntry(): TextEntry
    {
        return TextEntry::make(self::LAST_INTERACTION)
            ->label(__('filament/record-page.overview.last_interaction'))
            ->beforeLabel(Icon::make('lucide-messages-square'))
            ->since()
            ->dateTimeTooltip()
            ->placeholder(__('filament/record-page.overview.no_interaction'));
    }

    private static function stageEntry(Opportunity $record): ?Entry
    {
        $field = CustomField::query()
            ->forEntity(Opportunity::class)
            ->where('code', OpportunityField::STAGE->value)
            ->with('options')
            ->first();

        if (! $field instanceof CustomField) {
            return null;
        }

        $current = (string) $record->getCustomFieldValue($field);
        $colorsEnabled = $field->settings->enable_option_colors ?? false;

        return ViewEntry::make(self::STAGE_PROGRESS)
            ->label($field->name)
            ->beforeLabel(Icon::make('lucide-chart-no-axes-column'))
            ->view('filament.app.record-overview-stage', [
                'stages' => $field->options->map(fn (CustomFieldOption $option): array => [
                    'name' => (string) $option->getAttribute('name'),
                    'color' => $colorsEnabled ? ($option->settings->color ?? null) : null,
                    'current' => (string) $option->getKey() === $current,
                ])->values()->all(),
            ]);
    }

    private static function activity(Company|People|Opportunity $record, ?string $tabKey): Section
    {
        return Section::make(__('filament/record-page.overview.activity'))
            ->contained(false)
            ->headerActions(array_filter([
                $tabKey === null ? null : Action::make('viewAllActivity')
                    ->label(__('filament/record-page.overview.view_all'))
                    ->link()
                    ->color('gray')
                    ->alpineClickHandler(self::selectTab($tabKey)),
            ]))
            ->schema([
                Group::make([
                    Livewire::make(ActivityLogLivewire::class, [
                        'subjectClass' => $record::class,
                        'subjectKey' => $record->getKey(),
                        'groupByDate' => false,
                        'perPage' => self::LISTED_ITEMS,
                        'infiniteScroll' => false,
                    ])->key("record-overview-activity-{$record->getKey()}"),
                ])->extraAttributes(['class' => 'fi-record-overview-card fi-record-overview-activity']),
            ])
            ->extraAttributes(['class' => 'fi-record-overview-section']);
    }

    /**
     * @param  array{activity: ?string, notes: ?string, tasks: ?string, emails: ?string}  $tabKeys
     * @param  array{notes: ?string, tasks: ?string, emails: ?string}  $counts
     * @param  Collection<int, array{id: string, title: string, due: ?CarbonInterface, assignee: ?User}>  $openTasks
     * @return array<int, array<string, mixed>>
     */
    private static function relatedSections(Company|People|Opportunity $record, array $tabKeys, array $counts, Collection $openTasks): array
    {
        $sections = [
            self::section(__('filament/record-page.overview.notes'), $counts['notes'], $tabKeys['notes'], self::noteItems($record), Gate::allows('create', Note::class) ? 'createNote' : null, __('filament/record-page.actions.new_note')),
            self::section(__('filament/record-page.overview.tasks'), $counts['tasks'], $tabKeys['tasks'], self::taskItems($openTasks), Gate::allows('create', Task::class) ? 'createTask' : null, __('filament/record-page.actions.new_task')),
        ];

        if ($tabKeys['emails'] !== null) {
            $sections[] = self::section(__('filament/record-page.overview.emails'), $counts['emails'], $tabKeys['emails']);
        }

        return $sections;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private static function section(string $label, ?string $count, ?string $tabKey, array $items = [], ?string $createAction = null, string $createLabel = ''): array
    {
        return [
            'label' => $label,
            'count' => $count ?? '0',
            'viewAll' => $tabKey === null || (int) $count === 0 ? null : self::selectTab($tabKey),
            'create' => $createAction === null ? null : self::mountQuickAction($createAction),
            'createLabel' => $createLabel,
            'items' => $items,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function noteItems(Company|People|Opportunity $record): array
    {
        $bodyField = CustomField::query()->forEntity(Note::class)->where('code', NoteField::BODY->value)->first();

        return $record->notes()
            ->with(['customFieldValues.customField', 'creator'])
            ->latest('notes.created_at')
            ->limit(self::LISTED_ITEMS)
            ->get()
            ->map(fn (Note $note): array => [
                'title' => (string) $note->title,
                'snippet' => $bodyField instanceof CustomField ? Str::limit(PlainText::fromHtml((string) $note->getCustomFieldValue($bodyField)), 120) : null,
                'icon' => CrmEntity::Note->icon(),
                'author' => $note->creator,
                'assignee' => null,
                'due' => null,
                'time' => $note->created_at?->diffForHumans(),
                'open' => self::mountRecordAction(self::EDIT_NOTE_ACTION, ['note' => (string) $note->getKey()]),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, array{id: string, title: string, due: ?CarbonInterface, assignee: ?User}>  $openTasks
     * @return array<int, array<string, mixed>>
     */
    private static function taskItems(Collection $openTasks): array
    {
        return $openTasks
            ->take(self::LISTED_ITEMS)
            ->map(fn (array $task): array => [
                'title' => $task['title'],
                'snippet' => null,
                'icon' => 'lucide-circle',
                'author' => null,
                'assignee' => $task['assignee'],
                'due' => $task['due'] === null ? null : self::due($task['due']),
                'time' => null,
                'open' => self::mountRecordAction(self::EDIT_TASK_ACTION, ['task' => $task['id']]),
            ])
            ->all();
    }

    /**
     * @return array{label: string, tone: string}
     */
    private static function due(CarbonInterface $due): array
    {
        $local = $due->clone()->setTimezone(FilamentTimezone::get());
        $date = $local->isoFormat($local->isCurrentYear() ? 'MMM D' : 'MMM D, YYYY');

        return match (true) {
            $local->isPast() => ['label' => $date, 'tone' => 'overdue'],
            $local->isToday() => ['label' => __('filament/record-page.overview.today'), 'tone' => 'today'],
            $local->isTomorrow() => ['label' => __('filament/record-page.overview.tomorrow'), 'tone' => 'upcoming'],
            default => ['label' => $date, 'tone' => 'upcoming'],
        };
    }

    private static function selectTab(string $tabKey): string
    {
        return '$wire.set(\'activeRelationManager\', '.Js::from($tabKey).')';
    }

    private static function mountQuickAction(string $name): string
    {
        return '$wire.mountAction('.Js::from($name).', {}, '.Js::from(['schemaComponent' => 'infolist.quickActions']).')';
    }

    /**
     * @param  array<string, string>  $arguments
     */
    private static function mountRecordAction(string $name, array $arguments): string
    {
        return '$wire.mountAction('.Js::from($name).', '.Js::from($arguments).')';
    }

    /**
     * @return Collection<int, array{id: string, title: string, due: ?CarbonInterface, assignee: ?User}>
     */
    private static function openTasks(Company|People|Opportunity $record): Collection
    {
        $fields = CustomField::query()
            ->forEntity(Task::class)
            ->whereIn('code', [TaskField::DUE_DATE->value, TaskField::STATUS->value])
            ->with('options')
            ->get()
            ->keyBy('code');

        $dueField = $fields->get(TaskField::DUE_DATE->value);
        $statusField = $fields->get(TaskField::STATUS->value);
        $doneOptionId = $statusField?->options->firstWhere('name', TaskField::DONE_STATUS)?->getKey();

        return $record->tasks()
            ->with(['customFieldValues.customField', 'assignees'])
            ->when($statusField instanceof CustomField && $doneOptionId !== null, fn (Builder $tasks): Builder => $tasks->whereDoesntHave(
                'customFieldValues',
                fn (Builder $value): Builder => $value->whereBelongsTo($statusField)->where('string_value', $doneOptionId),
            ))
            ->when($dueField instanceof CustomField, fn (Builder $tasks): Builder => $tasks->orderBy(
                CustomFieldValue::query()
                    ->select('datetime_value')
                    ->whereBelongsTo($dueField)
                    ->whereColumn('entity_id', 'tasks.id')
                    ->where('entity_type', (new Task)->getMorphClass()),
            ))
            ->orderBy('tasks.id')
            ->limit(self::LISTED_ITEMS)
            ->get()
            ->map(fn (Task $task): array => [
                'id' => (string) $task->getKey(),
                'title' => (string) $task->title,
                'due' => self::dueDate($task, $dueField),
                'assignee' => $task->assignees->first(),
            ]);
    }

    private static function dueDate(Task $task, ?CustomField $dueField): ?CarbonInterface
    {
        if (! $dueField instanceof CustomField) {
            return null;
        }

        $due = $task->getCustomFieldValue($dueField);

        return $due instanceof CarbonInterface ? $due : null;
    }
}
