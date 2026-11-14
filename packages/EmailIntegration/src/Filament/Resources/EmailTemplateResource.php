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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Override;
use Relaticle\EmailIntegration\Actions\DeleteEmailTemplatesAction;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Schemas\EmailTemplateForm;
use Relaticle\EmailIntegration\Models\EmailTemplate;

final class EmailTemplateResource extends Resource
{
    use HasEmailFeatureFlag;

    protected static ?string $model = EmailTemplate::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $shouldRegisterNavigation = false;

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
            ->recordActions([
                self::editAction(),

                DeleteAction::make()
                    ->label(__('filament/resources/email-template.actions.delete.label'))
                    ->hiddenLabel()
                    ->tooltip(__('filament/resources/email-template.actions.delete.label'))
                    ->icon('lucide-trash')
                    ->visible(fn (EmailTemplate $record): bool => auth()->user()?->can('delete', $record) === true),
            ])
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

    private static function editAction(): EditAction
    {
        return EditAction::make()
            ->label(__('filament/resources/email-template.actions.edit.label'))
            ->schema(fn (Schema $schema): Schema => self::form($schema))
            ->hiddenLabel()
            ->tooltip(__('filament/resources/email-template.actions.edit.label'))
            ->icon('lucide-square-pen')
            ->visible(fn (EmailTemplate $record): bool => auth()->user()?->can('update', $record) === true);
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
