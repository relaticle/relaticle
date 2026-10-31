<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Resources;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Override;
use Relaticle\EmailIntegration\Actions\DeleteEmailTemplatesAction;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Schemas\EmailTemplateForm;
use Relaticle\EmailIntegration\Models\EmailTemplate;

final class EmailTemplateResource extends Resource
{
    use HasEmailFeatureFlag;

    protected static ?string $model = EmailTemplate::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'workspace/email/templates';

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return __('filament/resources/email-template.navigation_label');
    }

    public static function form(Schema $schema): Schema
    {
        return EmailTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(
                fn (Builder $q): Builder => $q
                    ->where('created_by', auth()->id())
                    ->orWhere('is_shared', true)
            ))
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament/resources/email-template.columns.name.label'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subject')
                    ->label(__('filament/resources/email-template.columns.subject.label'))
                    ->limit(60)
                    ->placeholder(__('filament/resources/email-template.columns.subject.placeholder')),

                IconColumn::make('is_shared')
                    ->label(__('filament/resources/email-template.columns.is_shared.label'))
                    ->boolean(),

                TextColumn::make('creator.name')
                    ->label(__('filament/resources/email-template.columns.creator.label'))
                    ->placeholder(__('filament/resources/email-template.columns.creator.placeholder')),

                TextColumn::make('created_at')
                    ->label(__('filament/resources/email-template.columns.created_at.label'))
                    ->date()
                    ->sortable(),
            ])
            ->recordActions(self::recordActions())
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('filament/resources/email-template.actions.bulk_delete.label'))
                        ->action(function (Collection $records, DeleteEmailTemplatesAction $deleteEmailTemplatesAction): void {
                            /** @var User $user */
                            $user = auth()->user();

                            $deleteEmailTemplatesAction->execute($user, $records);
                        }),
                ]),
            ]);
    }

    /**
     * @return list<EditAction|DeleteAction>
     */
    private static function recordActions(): array
    {
        return [
            // The edit schema is set explicitly rather than inferred from the
            // resource: this table is also rendered outside a resource page
            // (the Templates tab hosted by TemplatesTable), where there is no
            // resource context to infer it from.
            EditAction::make()
                ->schema(fn (Schema $schema): Schema => self::form($schema))
                ->label(__('filament/resources/email-template.actions.edit.label'))
                ->hiddenLabel()
                ->tooltip(__('filament/resources/email-template.actions.edit.label'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn (EmailTemplate $record): bool => auth()->user()?->can('update', $record) === true),

            DeleteAction::make()
                ->label(__('filament/resources/email-template.actions.delete.label'))
                ->hiddenLabel()
                ->tooltip(__('filament/resources/email-template.actions.delete.label'))
                ->icon(Heroicon::OutlinedTrash)
                ->visible(fn (EmailTemplate $record): bool => auth()->user()?->can('delete', $record) === true),
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageEmailTemplates::route('/'),
        ];
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(fn (Builder $q): Builder => $q
                ->where('created_by', auth()->id())
                ->orWhere('is_shared', true)
            );
    }
}
