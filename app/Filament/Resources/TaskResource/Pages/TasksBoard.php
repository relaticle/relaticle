<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Actions\Task\UpdateTask;
use App\Contracts\OrdersBoardStages;
use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\TaskField as TaskCustomField;
use App\Filament\Actions\CreateTaskAction;
use App\Filament\Actions\EditTaskAction;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Concerns\HasBoardCards;
use App\Filament\Concerns\HasBoardStageOrder;
use App\Filament\Concerns\HasBoardViews;
use App\Filament\Concerns\HasViewSwitcher;
use App\Filament\Concerns\KeepsOpenTaskInUrl;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Filament\Tables\Concerns\CreatesTableAttribute;
use App\Filament\Tables\FilterBar\ConditionsFilter;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ChecklistHtml;
use App\Support\PlainText;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\Exceptions\CardMoveRejectedException;
use Relaticle\Flowforge\Grouping;
use Relaticle\Flowforge\Lane;
use Relaticle\Flowforge\Modern\BoardResourcePage;
use WeakMap;

final class TasksBoard extends BoardResourcePage implements OrdersBoardStages
{
    use CreatesTableAttribute;
    use HasBoardCards;
    use HasBoardStageOrder;
    use HasBoardViews;
    use HasViewSwitcher;
    use KeepsOpenTaskInUrl;

    protected static string $resource = TaskResource::class;

    /** @var WeakMap<Task, array{preview: string, progress: array{checked: int, total: int}|null}>|null */
    private ?WeakMap $cardSummaries = null;

    public function getTitle(): string
    {
        return __('filament/pages/boards.tasks.title');
    }

    protected function getHeaderActions(): array
    {
        return $this->prefillingFirstVisibleColumn(TaskResource::collectionActions());
    }

    public function board(Board $board): Board
    {
        $columnField = $this->boardColumnField();
        $card = $this->boardCardComponents();

        return $board
            ->query($this->boardColumnQuery(Task::query()->with($this->cardEagerLoads()), $columnField))
            ->recordTitleAttribute('title')
            ->columnIdentifier($this->boardColumnIdentifier())
            ->positionIdentifier('order_column')
            ->columns($this->boardColumns())
            ->cardSchema(fn (Schema $schema): Schema => $schema->gap(false)->components($card))
            ->createCardAction($this->cardCreation())
            ->cardAction('edit')
            ->cardActions($this->cardActions())
            ->groupings([$this->assigneeGrouping()])
            ->filters([ConditionsFilter::make($this->healedFilterRules(...))])
            ->deferFilters(false)
            ->filtersLayout(FiltersLayout::Hidden)
            ->headerToolbar();
    }

    /**
     * @return list<string>
     */
    protected function blockCardAttributes(): array
    {
        return ['description'];
    }

    protected function cardEntry(string $key, ?Entry $customEntry): ?Component
    {
        return match ($key) {
            'description' => $this->descriptionPreview(),
            'checklist' => $this->checklistProgressEntry(),
            'assignees' => $this->assigneeAvatarsEntry(),
            'custom_fields.'.TaskCustomField::PRIORITY->value => $this->priorityBadge($customEntry),
            'custom_fields.'.TaskCustomField::DUE_DATE->value => $this->dueDateBadge($customEntry),
            default => null,
        };
    }

    private function priorityBadge(?Entry $entry): ?TextEntry
    {
        return $entry instanceof TextEntry
            ? $entry
                ->visible(fn (?string $state): bool => filled($state))
                ->grow(false)
                ->badge()
                ->hiddenLabel()
                ->icon('lucide-flag')
            : null;
    }

    private function dueDateBadge(?Entry $entry): ?TextEntry
    {
        return $entry instanceof TextEntry
            ? $entry
                ->visible(fn (?string $state): bool => filled($state))
                ->badge()
                ->color('gray')
                ->icon('lucide-calendar')
                ->grow(false)
                ->hiddenLabel()
                ->formatStateUsing(fn (?string $state): string => $this->formatDueDateBadge($state))
            : null;
    }

    protected function derivedCardAttributeIsEmpty(Model $record, string $key): bool
    {
        if (! $record instanceof Task) {
            return false;
        }

        $summary = $this->cardSummary($record);

        return match ($key) {
            'description' => blank($summary['preview']),
            'checklist' => $summary['progress'] === null,
            default => false,
        };
    }

    private function assigneeAvatarsEntry(): ImageEntry
    {
        return ImageEntry::make('assignees.profile_photo_url')
            ->hiddenLabel()
            ->alignLeft()
            ->imageHeight(20)
            ->circular()
            ->visible(fn (mixed $state): bool => filled($state))
            ->stacked();
    }

    private function descriptionPreview(): TextEntry
    {
        return TextEntry::make('description')
            ->hiddenLabel()
            ->columnSpanFull()
            ->html(false)
            ->state(fn (Task $record): string => $this->cardSummary($record)['preview'])
            ->visible(fn (?string $state): bool => filled($state))
            ->extraAttributes(['class' => 'mb-4']);
    }

    /** @return array{preview: string, progress: array{checked: int, total: int}|null} */
    private function cardSummary(Task $record): array
    {
        $this->cardSummaries ??= new WeakMap;

        return $this->cardSummaries[$record] ??= $this->summarizeCard($record);
    }

    /** @return array{preview: string, progress: array{checked: int, total: int}|null} */
    private function summarizeCard(Task $record): array
    {
        $field = $this->descriptionCustomField();
        $description = $field instanceof CustomField ? $record->getCustomFieldValue($field) : null;
        $description = is_string($description) ? $description : '';

        return [
            'preview' => Str::limit(PlainText::fromHtmlWithoutTaskMarkers($description)),
            'progress' => ChecklistHtml::progress($description),
        ];
    }

    private function checklistProgressEntry(): TextEntry
    {
        return TextEntry::make('checklist_progress')
            ->label(__('filament/pages/boards.tasks.checklist_progress'))
            ->hiddenLabel()
            ->badge()
            ->color(fn (Task $record): string => $this->checklistIsDone($record) ? 'success' : 'gray')
            ->icon('lucide-circle-check')
            ->grow(false)
            ->extraAttributes(['data-checklist-progress' => ''])
            ->state(function (Task $record): ?string {
                $progress = $this->cardSummary($record)['progress'];

                return $progress === null ? null : "{$progress['checked']}/{$progress['total']}";
            })
            ->tooltip(function (Task $record): ?string {
                $progress = $this->cardSummary($record)['progress'];

                return $progress === null ? null : trans_choice('filament/pages/boards.tasks.checklist_progress_tooltip', $progress['total'], $progress);
            })
            ->visible(fn (?string $state): bool => filled($state));
    }

    private function checklistIsDone(Task $record): bool
    {
        $progress = $this->cardSummary($record)['progress'];

        return $progress !== null && $progress['checked'] === $progress['total'];
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    private function cardActions(): array
    {
        return [
            TaskResource::copyActions(),
            EditTaskAction::make('edit')
                ->authorize('update')
                ->label(__('filament/pages/boards.tasks.actions.edit'))
                ->slideOver()
                ->modalWidth(Width::ThreeExtraLarge)
                ->icon('lucide-square-pen')
                ->schema(fn (Schema $schema): Schema => TaskForm::get($schema)),
            MoveCardAction::make(),
            Action::make('delete')
                ->authorize(fn (?Task $record): bool => $record instanceof Task && Gate::allows('delete', $record))
                ->label(__('filament/pages/boards.tasks.actions.delete'))
                ->icon('lucide-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (Task $record): void {
                    $record->delete();
                }),
        ];
    }

    protected function authorizeCardMove(Model $card, string $targetColumnId, ?string $toLaneId = null): void
    {
        $this->refuseReorderWhileSorted($card, $targetColumnId);

        if (Gate::denies('update', $card)) {
            throw CardMoveRejectedException::because(__('filament/pages/boards.move_denied'));
        }
    }

    protected function persistCardMove(Model $card, mixed $columnValue, string $position): void
    {
        $card->update([$this->getBoard()->getPositionIdentifierAttribute() => $position]);

        /** @var Task $card */
        $card->saveCustomFieldValue($this->boardColumnField(), $this->columnValueFor((string) $columnValue));
    }

    /**
     * Format a due date value for badge display.
     *
     * Shows relative dates (Today/Tomorrow) for immediate items,
     * and full dates with year for all other cases.
     *
     * "Today" and "tomorrow" are questions about the viewer's calendar, so the stored
     * UTC value is moved into their zone first: isToday()/isTomorrow() compare against
     * now in the instance's own timezone, and left in UTC they bucket a late-evening
     * due date a day early for anyone far enough east. isPast() compares instants and
     * is unaffected either way.
     */
    private function formatDueDateBadge(?string $state): string
    {
        if (blank($state)) {
            return '';
        }

        $date = Date::parse($state)->setTimezone(FilamentTimezone::get());

        return match (true) {
            $date->isPast() => $date->format('M j, Y').' (Overdue)',
            $date->isToday() => 'Due Today',
            $date->isTomorrow() => 'Due Tomorrow',
            default => $date->format('M j, Y'),
        };
    }

    private function cardCreation(): CreateTaskAction
    {
        return CreateTaskAction::make('addCard')
            ->authorize(fn (): bool => Gate::allows('create', Task::class))
            ->label(__('filament/pages/boards.tasks.actions.add'))
            ->icon('lucide-plus')
            ->iconButton()
            ->modalWidth(Width::TwoExtraLarge)
            ->slideOver(false)
            ->model(Task::class)
            ->schema(fn (Schema $schema): Schema => TaskForm::get($schema, [$this->boardColumnField()->code]))
            ->using($this->createCard(...));
    }

    private function assigneeGrouping(): Grouping
    {
        return Grouping::make('assignee')
            ->label(__('filament/pages/boards.tasks.groupings.assignee'))
            ->lanes(fn (Builder $boardQuery): array => User::query()
                ->memberOf($this->currentUser()->currentWorkspace)
                ->whereIn('users.id', DB::table('task_user')->whereIn('task_id', (clone $boardQuery)->select('tasks.id'))->select('user_id'))
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): Lane => Lane::make($user->getKey())
                    ->label($user->name)
                    ->avatar($user->profile_photo_url)
                    ->query(fn (Builder $query): Builder => $query->whereHas('assignees', fn (Builder $assignees): Builder => $assignees->whereKey($user->getKey()))))
                ->all())
            ->emptyLane(
                __('filament/pages/boards.tasks.groupings.unassigned'),
                fn (Builder $query): Builder => $query->whereDoesntHave('assignees'),
            )
            ->moveUsing($this->moveToAssigneeLane(...));
    }

    private function moveToAssigneeLane(Task $record, Lane $from, Lane $to): void
    {
        $assigneeIds = $record->assignees()->pluck('users.id')
            ->push($to->getName())
            ->reject(fn (string $id): bool => in_array($id, [$from->getName(), Grouping::EMPTY_LANE], true))
            ->unique()
            ->values()
            ->all();

        resolve(UpdateTask::class)->execute($this->currentUser(), $record, ['assignee_ids' => $assigneeIds]);
    }

    private function currentUser(): User
    {
        /** @var User */
        return Auth::guard('web')->user();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCard(array $data, CreateTaskAction $action): Task
    {
        $columnId = $action->getArguments()['column'] ?? null;

        abort_unless(blank($columnId) || $this->ownsColumn((string) $columnId), 422);

        /** @var Workspace $currentWorkspace */
        $currentWorkspace = Auth::guard('web')->user()->currentWorkspace;

        /** @var Task $task */
        $task = $currentWorkspace->tasks()->create($data);

        if (filled($columnId)) {
            if ($columnId !== BoardStateData::NO_VALUE_COLUMN) {
                $task->saveCustomFieldValue($this->boardColumnField(), $columnId);
            }

            $this->placeNewCard($task, $columnId);
        }

        return $task;
    }

    private function descriptionCustomField(): ?CustomField
    {
        return self::cardCustomFields()->get(TaskCustomField::DESCRIPTION->value);
    }

    private static function statusCustomField(): ?CustomField
    {
        return self::cardCustomFields()->get(CrmEntity::Task->boardFieldCode());
    }

    /** @return Collection<string, CustomField> */
    private static function cardCustomFields(): Collection
    {
        /** @var Collection<string, CustomField> */
        return once(fn (): Collection => CustomField::query()
            ->forEntity(Task::class)
            ->whereIn('code', [CrmEntity::Task->boardFieldCode(), TaskCustomField::DESCRIPTION])
            ->with('options')
            ->get()
            ->keyBy('code'));
    }

    protected function defaultBoardColumnField(): ?CustomField
    {
        return self::statusCustomField();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        return self::statusCustomField() instanceof CustomField;
    }
}
