<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\CompanyResource\RelationManagers;

use App\Models\Opportunity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Relaticle\SystemAdmin\Filament\Resources\OpportunityResource;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;

final class OpportunitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'opportunities';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-currency-dollar';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->opportunities()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->color('primary')
                    ->url(fn (Opportunity $record): string => OpportunityResource::getUrl('view', ['record' => $record])),
                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->sortable()
                    ->color('primary')
                    ->url(RecordLink::to(PeopleResource::class, 'contact')),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable()
                    ->color('primary')
                    ->url(RecordLink::to(UserResource::class, 'creator')),
                TextColumn::make('creation_source')
                    ->badge()
                    ->label('Source'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
