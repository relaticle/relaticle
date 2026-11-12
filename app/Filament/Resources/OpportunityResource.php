<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\CrmEntity;
use App\Filament\Actions\ImportExportActionGroup;
use App\Filament\Actions\PreviewRecordAction;
use App\Filament\Components\Tables\EmptyListDescription;
use App\Filament\Components\Tables\FilteredEmptyState;
use App\Filament\Components\Tables\RecordChipColumn;
use App\Filament\Exports\OpportunityExporter;
use App\Filament\Resources\OpportunityResource\Forms\OpportunityForm;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\OpportunityResource\RelationManagers\EmailsRelationManager;
use App\Filament\Resources\OpportunityResource\RelationManagers\MeetingsRelationManager;
use App\Filament\Resources\OpportunityResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\OpportunityResource\RelationManagers\TasksRelationManager;
use App\Filament\Tables\BulkBar;
use App\Filament\Tables\RowActions;
use App\Models\Opportunity;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;
use Relaticle\ActivityLog\Filament\RelationManagers\ActivityLogRelationManager;
use Relaticle\ImportWizard\Filament\Pages\ImportOpportunities;

final class OpportunityResource extends Resource
{
    protected static ?string $model = Opportunity::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = null;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return OpportunityForm::get($schema);
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public static function collectionActions(): array
    {
        return [
            ImportExportActionGroup::make([
                Action::make('import')
                    ->label(__('filament/resources/opportunity.pages.list.actions.import.label'))
                    ->icon('lucide-upload')
                    ->url(ImportOpportunities::getUrl())
                    ->visible(ImportOpportunities::canAccess(...)),
                ExportAction::make()->exporter(OpportunityExporter::class)->authorize('exportAny', Opportunity::class),
            ])->label(__('filament/resources/opportunity.pages.list.actions.import_export.label')),
            CreateAction::make()
                ->icon('lucide-plus')
                ->authorize(self::canCreate(...))
                ->size(Size::Small)
                ->model(Opportunity::class)
                ->schema(fn (Schema $schema): Schema => self::form($schema->columns(2))),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateDescription(EmptyListDescription::unlessFiltered(__('filament/resources/opportunity.empty_state.description')))
            ->emptyStateHeading(FilteredEmptyState::heading())
            ->emptyStateActions([FilteredEmptyState::clearAction()])
            ->columns([
                RecordChipColumn::make('name')
                    ->previewable(),
                TextColumn::make('creator.name')
                    ->label(__('filament/resources/opportunity.fields.creator.label'))
                    ->sortable()
                    ->toggleable()
                    ->visibleFrom('sm')
                    ->getStateUsing(fn (Opportunity $record): string => $record->created_by),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->visibleFrom('sm')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                RowActions::withoutMenu([
                    PreviewRecordAction::make()->page(ViewOpportunity::class),
                ]),
            ])
            ->toolbarActions([
                BulkBar::make([
                    ExportBulkAction::make()
                        ->authorize('exportAny', Opportunity::class)
                        ->exporter(OpportunityExporter::class),
                    RestoreBulkAction::make(),
                ], more: [
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ActivityLogRelationManager::class,
            TasksRelationManager::class,
            NotesRelationManager::class,
            EmailsRelationManager::class,
            MeetingsRelationManager::class,
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListOpportunities::route('/'),
            'board' => OpportunitiesBoard::route('/board'),
            'view' => ViewOpportunity::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return __('filament/resources/opportunity.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/opportunity.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament/resources/opportunity.navigation_label');
    }

    public static function getNavigationIcon(): string
    {
        return CrmEntity::Opportunity->icon();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customFieldValues.customField.options'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
