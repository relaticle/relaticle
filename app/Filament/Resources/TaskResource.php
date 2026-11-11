<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\CrmEntity;
use App\Enums\FilterCondition;
use App\Filament\Actions\CopyToClipboardAction;
use App\Filament\Actions\EditTaskAction;
use App\Filament\Components\Tables\EmptyListDescription;
use App\Filament\Components\Tables\FilteredEmptyState;
use App\Filament\Components\Tables\LinkedRecordsColumn;
use App\Filament\Components\Tables\RecordChipColumn;
use App\Filament\Concerns\RemembersViewMode;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Filament\Tables\BulkBar;
use App\Filament\Tables\FilterBar\ConditionsFilter;
use App\Filament\Tables\RowActions;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Relaticle\CustomFields\Contracts\ValueResolvers;

final class TaskResource extends Resource
{
    use RemembersViewMode;

    protected static ?string $model = Task::class;

    protected static ?string $navigationLabel = null;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('filament/resources/task.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/task.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament/resources/task.navigation_label');
    }

    public static function getNavigationIcon(): string
    {
        return CrmEntity::Task->icon();
    }

    public static function assignedToMeUrl(?Workspace $tenant = null): string
    {
        return self::getUrl('index', [
            'filters' => [ConditionsFilter::NAME => [
                'match' => 'all',
                'rules' => [['field' => 'assignees', 'condition' => FilterCondition::IsAnyOf->value, 'value' => ['@me']]],
            ]],
        ], panel: 'app', tenant: $tenant);
    }

    public static function form(Schema $schema): Schema
    {
        return TaskForm::get($schema);
    }

    public static function table(Table $table): Table
    {
        /** @var Collection<string, CustomField> $customFields */
        $customFields = CustomField::query()->with('options')->whereIn('code', ['status', 'priority'])->get()->keyBy('code');
        /** @var ValueResolvers $valueResolver */
        $valueResolver = resolve(ValueResolvers::class);

        return $table
            ->emptyStateDescription(EmptyListDescription::unlessFiltered(__('filament/resources/task.empty_state.description')))
            ->emptyStateHeading(FilteredEmptyState::heading())
            ->emptyStateActions([FilteredEmptyState::clearAction()])
            ->columns(self::columns())
            ->defaultSort('created_at', 'desc')
            ->groups(array_filter([
                ...collect(['status', 'priority'])->map(fn (string $fieldCode): ?\Filament\Tables\Grouping\Group => $customFields->has($fieldCode) ? self::makeCustomFieldGroup($fieldCode, $customFields, $valueResolver) : null
                )->filter()->toArray(),
            ]))
            ->recordActions([
                RowActions::withoutMenu([
                    EditTaskAction::make(),
                    ViewAction::make()
                        ->visible(fn (Task $record): bool => Gate::denies('update', $record)),
                ]),
            ])
            ->toolbarActions([
                BulkBar::make([
                    RestoreBulkAction::make(),
                ], more: [
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<int, Column>
     */
    private static function columns(): array
    {
        return [
            TextColumn::make('title')
                ->limit(50)
                ->weight('medium'),
            LinkedRecordsColumn::make('relations')
                ->toggleable(),
            RecordChipColumn::make('assignees.name')
                ->label(__('filament/resources/task.fields.assignees.label'))
                ->collapsed()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('creator.name')
                ->label(__('filament/resources/task.fields.creator.label'))
                ->sortable()
                ->toggleable()
                ->visibleFrom('sm')
                ->getStateUsing(fn (Task $record): string => $record->createdBy),
            TextColumn::make('created_at')
                ->dateTime()
                ->sortable()
                ->visibleFrom('sm')
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
        ];
    }

    public static function copyActions(): ActionGroup
    {
        return ActionGroup::make([
            CopyToClipboardAction::make('copyTaskUrl')
                ->label(__('filament/resources/task.actions.copy_url'))
                ->copies(
                    fn (Task $record): string => resolve(CanonicalRecordUrl::class)->buildOrFail(CrmEntity::Task, $record->getKey(), self::currentWorkspace()),
                    __('filament/record-page.notifications.url_copied'),
                ),
            CopyToClipboardAction::make('copyTaskId')
                ->label(__('filament/resources/task.actions.copy_id'))
                ->copies(fn (Task $record): string => $record->getKey(), __('filament/record-page.notifications.id_copied')),
        ])->dropdown(false);
    }

    private static function currentWorkspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTasks::route('/'),
            'board' => TasksBoard::route('/board'),
        ];
    }

    /**
     * @param  Collection<string, CustomField>  $customFields
     */
    private static function makeCustomFieldGroup(string $fieldCode, Collection $customFields, ValueResolvers $valueResolver): Group
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
                strtolower($direction) === 'desc' ? 'desc' : 'asc',
            ))
            ->getTitleFromRecordUsing(function (Task $record) use ($valueResolver, $field, $label): string {
                $value = $valueResolver->resolve($record, $field);

                return blank($value) ? "No {$label}" : $value;
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['assignees', 'companies.media', 'people', 'opportunities', 'customFieldValues.customField.options'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
