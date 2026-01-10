<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CustomFields\TaskField as TaskCustomField;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Task;
use App\Models\Team;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use League\CommonMark\Exception\InvalidArgumentException;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\BoardPage;
use Relaticle\Flowforge\Column;
use Relaticle\Flowforge\Components\CardFlex;
use Throwable;
use UnitEnum;

final class TasksBoard extends BoardPage
{
    protected static ?string $navigationLabel = 'Board';

    protected static ?string $title = 'Tasks';

    protected static ?string $navigationParentItem = 'Tasks';

    protected static string|null|UnitEnum $navigationGroup = 'Workspace';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-view-columns';

    /**
     * Configure the board using the new Filament V4 architecture.
     */
    public function board(Board $board): Board
    {
        $statusField = $this->statusCustomField();
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
            ->columnIdentifier('cfv.'.$valueColumn)
            ->positionIdentifier('order_column')
            ->searchable(['title'])
            ->columns($this->getColumns())
            ->cardSchema(function (Schema $schema) use ($customFields): Schema {
                $descriptionCustomField = $customFields->get('custom_fields.description')
                    ?->columnSpanFull()
                    ->visible(fn (?string $state): bool => filled($state))
                    ->formatStateUsing(fn (string $state): string => str($state)->stripTags()->limit()->toString());

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
                    ->components([
                        $descriptionCustomField,
                        CardFlex::make([
                            $priorityField,
                            $dueDateField,
                            ImageEntry::make('assignees.profile_photo_url')
                                ->hiddenLabel()
                                ->alignLeft()
                                ->imageHeight(24)
                                ->circular()
                                ->visible(fn (?string $state): bool => filled($state))
                                ->stacked(),
                        ])->align('center'),
                    ]);
            })
            ->columnActions([
                CreateAction::make()
                    ->label('Add Task')
                    ->icon('heroicon-o-plus')
                    ->iconButton()
                    ->modalWidth(Width::TwoExtraLarge)
                    ->slideOver(false)
                    ->model(Task::class)
                    ->schema(fn (Schema $schema): Schema => TaskForm::get($schema, ['status']))
                    ->using(function (array $data, array $arguments): Task {
                        /** @var Team $currentTeam */
                        $currentTeam = Auth::guard('web')->user()->currentTeam;

                        /** @var Task $task */
                        $task = $currentTeam->tasks()->create($data);

                        $statusField = $this->statusCustomField();
                        $task->saveCustomFieldValue($statusField, $arguments['column']);
                        $task->order_column = (float) $this->getBoardPositionInColumn((string) $arguments['column']);

                        return $task;
                    }),
            ])
            ->cardActions([
                Action::make('edit')
                    ->label('Edit')
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
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Task $record): void {
                        $record->delete();
                    }),
            ])
            ->filters([
                SelectFilter::make('assignees')
                    ->label('Assignee')
                    ->relationship('assignees', 'name')
                    ->multiple(),
            ])
            ->filtersFormWidth(Width::Medium);
    }

    /**
     * Move card to new position using DecimalPosition service.
     *
     * Overrides the default Flowforge implementation to properly handle
     * custom field columns which are stored in a polymorphic table.
     *
     * @throws Throwable
     */
    public function moveCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null
    ): void {
        $board = $this->getBoard();
        $query = $board->getQuery();

        if (! $query instanceof \Illuminate\Database\Eloquent\Builder) {
            throw new InvalidArgumentException('Board query not available');
        }

        /** @var Task|null $card */
        $card = (clone $query)->with(['assignees'])->find($cardId);
        if (! $card) {
            throw new InvalidArgumentException("Card not found: {$cardId}");
        }

        // Calculate new position using DecimalPosition (via v3 trait helper)
        $newPosition = $this->calculatePositionBetweenCards($afterCardId, $beforeCardId, $targetColumnId);

        // Use transaction for data consistency
        DB::transaction(function () use ($card, $board, $targetColumnId, $newPosition): void {
            $columnIdentifier = $board->getColumnIdentifierAttribute();
            $columnValue = $this->resolveStatusValue($card, $columnIdentifier, $targetColumnId);
            $positionIdentifier = $board->getPositionIdentifierAttribute();

            $card->update([$positionIdentifier => $newPosition]);

            $card->saveCustomFieldValue($this->statusCustomField(), $columnValue);
        });

        // Emit success event after successful transaction
        $this->dispatch('kanban-card-moved', [
            'cardId' => $cardId,
            'columnId' => $targetColumnId,
            'position' => $newPosition,
        ]);
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
     */
    private function formatDueDateBadge(?string $state): string
    {
        if (blank($state)) {
            return '';
        }

        $date = \Illuminate\Support\Facades\Date::parse($state);

        return match (true) {
            $date->isPast() => $date->format('M j, Y').' (Overdue)',
            $date->isToday() => 'Due Today',
            $date->isTomorrow() => 'Due Tomorrow',
            default => $date->format('M j, Y'),
        };
    }

    private function statusCustomField(): ?CustomField
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
        $field = $this->statusCustomField();

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

    public static function canAccess(): bool
    {
        return (new self)->statusCustomField() instanceof CustomField;
    }
}
