<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\CreationSource;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Contracts\ValueResolvers;
use Throwable;

final class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $navigationLabel = 'Tasks';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-circle';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 3;

    protected static string|\UnitEnum|null $navigationGroup = 'Workspace';

    public static function form(Schema $schema): Schema
    {
        return TaskForm::get($schema);
    }

    public static function table(Table $table): Table
    {
        /** @var Collection<string, CustomField> $customFields */
        $customFields = CustomField::query()->whereIn('code', ['status', 'priority'])->get()->keyBy('code');
        /** @var ValueResolvers $valueResolver */
        $valueResolver = resolve(ValueResolvers::class);

        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    ->limit(50)
                    ->weight('medium'),
                TextColumn::make('assignees.name')
                    ->label('Assignee')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->getStateUsing(fn (Task $record): string => $record->createdBy)
                    ->color(fn (Task $record): string => $record->isSystemCreated() ? 'secondary' : 'primary'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->toggledHiddenByDefault(),
            ])
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->paginated([10, 25, 50])
            ->filters([
                Filter::make('assigned_to_me')
                    ->label('Assigned to me')
                    ->query(fn (Builder $query): Builder => $query->whereHas('assignees', function (Builder $query): void {
                        $query->where('users.id', auth()->id());
                    }))
                    ->toggle(),
                SelectFilter::make('assignees')
                    ->multiple()
                    ->relationship('assignees', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('creation_source')
                    ->label('Creation Source')
                    ->options(CreationSource::class)
                    ->multiple(),
                TrashedFilter::make(),
            ])
            ->groups(array_filter([
                ...collect(['status', 'priority'])->map(fn (string $fieldCode): ?\Filament\Tables\Grouping\Group => $customFields->has($fieldCode) ? self::makeCustomFieldGroup($fieldCode, $customFields, $valueResolver) : null
                )->filter()->toArray(),
            ]))
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->using(function (Task $record, array $data): Task {
                            try {
                                DB::beginTransaction();

                                $record->update($data);

                                /** @var Collection<int, User> $assignees */
                                $assignees = $record->assignees;

                                // TODO: Improve the logic to check if the task is already assigned to the user
                                // Send notifications to assignees if they haven't been notified about this task yet
                                if ($assignees->isNotEmpty()) {
                                    $assignees->each(function (User $recipient) use ($record): void {
                                        // Check if a notification for this task already exists for this user
                                        $notificationExists = $recipient->notifications()
                                            ->where('data->viewData->task_id', $record->id)
                                            ->exists();

                                        // Only send notification if one doesn't already exist
                                        if (! $notificationExists) {
                                            Notification::make()
                                                ->title('New Task Assignment: '.$record->title)
                                                ->actions([
                                                    Action::make('view')
                                                        ->button()
                                                        ->label('View Task')
                                                        ->url(ManageTasks::getUrl(['record' => $record]))
                                                        ->markAsRead(),
                                                ])
                                                ->icon('heroicon-o-check-circle')
                                                ->iconColor('primary')
                                                ->viewData(['task_id' => $record->id]) // Store task ID in notification data
                                                ->sendToDatabase($recipient);
                                        }
                                    });
                                }

                                DB::commit();
                            } catch (Throwable $e) {
                                DB::rollBack();
                                throw $e;
                            }

                            return $record;
                        }),
                    RestoreAction::make(),
                    DeleteAction::make(),
                    ForceDeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTasks::route('/'),
        ];
    }

    /**
     * @param  SupportCollection<string, CustomField>  $customFields
     */
    private static function makeCustomFieldGroup(string $fieldCode, SupportCollection $customFields, ValueResolvers $valueResolver): Group
    {
        $field = $customFields[$fieldCode];
        $label = ucfirst($fieldCode);

        return Group::make("{$fieldCode}_group")
            ->label($label)
            ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query->orderBy(
                $field->values()
                    ->select($field->getValueColumn())
                    ->whereColumn('custom_field_values.entity_id', 'tasks.id')
                    ->limit(1)
                    ->getQuery(),
                $direction
            ))
            ->getTitleFromRecordUsing(function (Task $record) use ($valueResolver, $field, $label): string {
                $value = $valueResolver->resolve($record, $field);

                return empty($value) ? "No {$label}" : $value;
            })
            ->getKeyFromRecordUsing(function (Task $record) use ($field): string {
                $fieldValue = $record->customFieldValues->firstWhere('custom_field_id', $field->id);
                $rawValue = $fieldValue?->getValue();

                return $rawValue ? (string) $rawValue : '0';
            })
            ->scopeQueryByKeyUsing(function (Builder $query, string $key) use ($field): Builder {
                if ($key === '0') {
                    return $query->whereDoesntHave('customFieldValues', function (Builder $query) use ($field): void {
                        $query->where('custom_field_id', $field->id);
                    });
                }

                return $query->whereHas('customFieldValues', function (Builder $query) use ($field, $key): void {
                    $query->where('custom_field_id', $field->id)
                        ->where($field->getValueColumn(), $key);
                });
            });
    }

    /**
     * @return Builder<Task>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
