<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Actions\Task\UpdateTask;
use App\Enums\CustomFields\TaskField as TaskCustomField;
use App\Filament\Actions\CreateTaskAction;
use App\Filament\Components\Forms\WorkspaceMemberSelect;
use App\Filament\Components\Tables\Filters\RecordSelectFilter;
use App\Filament\Concerns\HasViewSwitcher;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Exception;
use Filament\Actions\Action;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\Column;
use Relaticle\Flowforge\Components\CardFlex;
use Relaticle\Flowforge\Exceptions\CardMoveRejectedException;
use Relaticle\Flowforge\Grouping;
use Relaticle\Flowforge\Lane;
use Relaticle\Flowforge\Modern\BoardResourcePage;

final class TasksBoard extends BoardResourcePage
{
    use HasViewSwitcher;

    protected static string $resource = TaskResource::class;

    public function getTitle(): string
    {
        return __('filament/pages/boards.tasks.title');
    }

    /**
     * Configure the board using the new Filament V4 architecture.
     */
    public function board(Board $board): Board
    {
        $statusField = self::statusCustomField();
        $valueColumn = $statusField->getValueColumn();

        $customFields = CustomFields::infolist()
            ->forModel(Task::class)
            ->only(['description', 'priority', 'due_date'])
            ->hiddenLabels()
            ->visibleWhenFilled()
            ->withoutSections()
            ->values()
            ->keyBy(fn (mixed $field): string => $field->getName());

        return $board
            ->query(
                Task::query()
                    ->leftJoin('custom_field_values as cfv', function (JoinClause $join) use ($statusField): void {
                        $join->on('tasks.id', '=', 'cfv.entity_id')
                            ->where('cfv.custom_field_id', '=', $statusField->getKey());
                    })
                    ->with(['assignees'])
                    ->select('tasks.*', 'cfv.'.$valueColumn)
            )
            ->recordTitleAttribute('title')
            ->columnIdentifier($valueColumn)
            ->positionIdentifier('order_column')
            ->searchable(['title'])
            ->columns($this->getColumns())
            ->cardSchema(function (Schema $schema) use ($customFields): Schema {
                $descriptionCustomField = $customFields->get('custom_fields.description')
                    ?->columnSpanFull()
                    ->visible(fn (?string $state): bool => filled($state))
                    ->formatStateUsing(fn (string $state): string => str($state)->stripTags()->limit()->toString())
                    ->extraAttributes(['class' => 'mb-4']);

                $priorityField = $customFields->get('custom_fields.priority')
                    ?->visible(fn (?string $state): bool => filled($state))
                    ->grow(false)
                    ->badge()
                    ->hiddenLabel()
                    ->icon('heroicon-o-flag');

                $dueDateField = $customFields->get('custom_fields.due_date')
                    ?->visible(fn (?string $state): bool => filled($state))
                    ->badge()
                    ->color('gray')
                    ->icon('heroicon-o-calendar')
                    ->grow(false)
                    ->hiddenLabel()
                    ->formatStateUsing(fn (?string $state): string => $this->formatDueDateBadge($state));

                return $schema
                    ->inline()
                    ->gap(false)
                    ->components([
                        $descriptionCustomField,
                        CardFlex::make([
                            $priorityField,
                            $dueDateField,
                            ImageEntry::make('assignees.profile_photo_url')
                                ->hiddenLabel()
                                ->alignLeft()
                                ->imageHeight(20)
                                ->circular()
                                ->visible(fn (mixed $state): bool => filled($state))
                                ->stacked(),
                        ])->align('center'),
                    ]);
            })
            ->createCardAction($this->cardCreation())
            ->cardAction('edit')
            ->cardActions([
                Action::make('edit')
                    ->authorize(fn (?Task $record): bool => $record instanceof Task && Gate::allows('update', $record))
                    ->label(__('filament/pages/boards.tasks.actions.edit'))
                    ->slideOver()
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->icon('heroicon-o-pencil-square')
                    ->schema(fn (Schema $schema): Schema => TaskForm::get($schema))
                    ->fillForm(fn (Task $record): array => [
                        'title' => $record->title,
                    ])
                    ->action(function (Task $record, array $data): void {
                        $record->update($data);
                    }),
                Action::make('delete')
                    ->authorize(fn (?Task $record): bool => $record instanceof Task && Gate::allows('delete', $record))
                    ->label(__('filament/pages/boards.tasks.actions.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Task $record): void {
                        $record->delete();
                    }),
            ])
            ->groupings([$this->assigneeGrouping()])
            ->filters([
                RecordSelectFilter::make('assignees')
                    ->label(__('filament/pages/boards.tasks.filters.assignee'))
                    ->relationship('assignees', 'name', WorkspaceMemberSelect::currentWorkspaceMembers())
                    ->searchable()
                    ->preload()
                    ->multiple(),
            ])
            ->filtersFormWidth(Width::Medium)
            ->headerToolbar();
    }

    protected function authorizeCardMove(Model $card, string $targetColumnId, ?string $toLaneId = null): void
    {
        if (Gate::denies('update', $card)) {
            throw CardMoveRejectedException::because(__('filament/pages/boards.move_denied'));
        }
    }

    protected function persistCardMove(Model $card, mixed $columnValue, string $position): void
    {
        $card->update([$this->getBoard()->getPositionIdentifierAttribute() => $position]);

        /** @var Task $card */
        $card->saveCustomFieldValue(self::statusCustomField(), $columnValue);
    }

    /**
     * Get columns for the board.
     *
     * @return array<Column>
     *
     * @throws Exception
     */
    private function getColumns(): array
    {
        return $this->statuses()->map(fn (array $status): Column => Column::make((string) $status['id'])
            ->color($status['color'])
            ->label($status['name'])
        )->toArray();
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
        return CreateTaskAction::make()
            ->authorize(fn (): bool => Gate::allows('create', Task::class))
            ->label(__('filament/pages/boards.tasks.actions.add'))
            ->icon('heroicon-o-plus')
            ->iconButton()
            ->modalWidth(Width::TwoExtraLarge)
            ->slideOver(false)
            ->model(Task::class)
            ->schema(fn (Schema $schema): Schema => TaskForm::get($schema, ['status']))
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
            $task->saveCustomFieldValue(self::statusCustomField(), $columnId);
            $task->order_column = (float) $this->getBoardPositionInColumn($columnId);
            $task->saveQuietly();
        }

        return $task;
    }

    private function ownsColumn(string $id): bool
    {
        return $this->statuses()->contains(fn (array $status): bool => (string) $status['id'] === $id);
    }

    private static function statusCustomField(): ?CustomField
    {
        /** @var CustomField|null */
        return once(fn () => CustomField::query()
            ->forEntity(Task::class)
            ->where('code', TaskCustomField::STATUS)
            ->first());
    }

    /**
     * @return Collection<int, array{id: mixed, custom_field_id: mixed, name: mixed, color: string}>
     */
    private function statuses(): Collection
    {
        $field = self::statusCustomField();

        if (! $field instanceof CustomField) {
            return collect();
        }

        // Check if color options are enabled for this field
        $colorsEnabled = $field->settings->enable_option_colors ?? false;

        return $field->options->map(fn (CustomFieldOption $option): array => [
            'id' => $option->getKey(),
            'custom_field_id' => $option->getAttribute('custom_field_id'),
            'name' => $option->getAttribute('name'),
            'color' => $colorsEnabled ? ($option->settings->color ?? 'gray') : 'gray',
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        return self::statusCustomField() instanceof CustomField;
    }
}
