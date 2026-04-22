<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Override;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;

final class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 5;

    protected static string|\UnitEnum|null $navigationGroup = 'Emails';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(100),

            TextInput::make('subject')
                ->required()
                ->maxLength(255),

            RichEditor::make('body_html')
                ->label('Body')
                ->required()
                ->mergeTags(EmailTemplateRenderService::MERGE_TAGS)
                ->activePanel('mergeTags')
                ->toolbarButtons([
                    'bold', 'italic', 'underline', 'strike',
                    'link', 'bulletList', 'orderedList',
                    'blockquote', 'h2', 'h3', 'undo', 'redo',
                ])
                ->columnSpanFull(),

            Toggle::make('is_shared')
                ->label('Share with team')
                ->helperText('When enabled, all team members can use this template.'),
        ]);
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
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subject')
                    ->limit(60)
                    ->placeholder('—'),

                IconColumn::make('is_shared')
                    ->label('Shared')
                    ->boolean(),

                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->date()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (EmailTemplate $record): bool => $record->created_by === auth()->id()),

                DeleteAction::make()
                    ->label('Delete')
                    ->visible(fn (EmailTemplate $record): bool => $record->created_by === auth()->id()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function (Collection $records): void {
                            $records
                                ->filter(fn (mixed $record): bool => $record instanceof EmailTemplate && $record->created_by === auth()->id())
                                ->each->delete();
                        }),
                ]),
            ]);
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
