<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use App\Enums\CreationSource;
use App\Models\Opportunity;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\OpportunityResource\Pages\CreateOpportunity;
use Relaticle\SystemAdmin\Filament\Resources\OpportunityResource\Pages\EditOpportunity;
use Relaticle\SystemAdmin\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use Relaticle\SystemAdmin\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;

final class OpportunityResource extends Resource
{
    protected static ?string $model = Opportunity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Opportunity';

    protected static ?string $pluralModelLabel = 'Opportunities';

    protected static ?string $slug = 'opportunities';

    public static function getNavigationBadge(): ?string
    {
        $count = self::getModel()::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('team_id')
                    ->relationship('team', 'name')
                    ->searchable()
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('company_id')
                    ->relationship('company', 'name')
                    ->searchable(),
                Select::make('contact_id')
                    ->relationship('contact', 'name')
                    ->searchable(),
                Select::make('creation_source')
                    ->options(CreationSource::class)
                    ->default(CreationSource::WEB),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->sortable(),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('creation_source')
                    ->badge()
                    ->color(fn (CreationSource $state): string => match ($state) {
                        CreationSource::WEB => 'info',
                        CreationSource::SYSTEM => 'warning',
                        CreationSource::IMPORT => 'success',
                    })
                    ->label('Source')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('team')
                    ->relationship('team', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('company')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('creation_source')
                    ->label('Source')
                    ->options(CreationSource::class)
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    #[Override]
    public static function getRelations(): array
    {
        return [];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListOpportunities::route('/'),
            'create' => CreateOpportunity::route('/create'),
            'view' => ViewOpportunity::route('/{record}'),
            'edit' => EditOpportunity::route('/{record}/edit'),
        ];
    }
}
