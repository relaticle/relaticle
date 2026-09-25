<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class SocialAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'socialAccounts';

    protected static ?string $title = 'Social Providers';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-key';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->socialAccounts()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('provider_name')
            ->columns([
                // Rendered from the stored string, not SocialiteProvider: rows predate
                // the enum and hold providers it no longer offers.
                TextColumn::make('provider_name')
                    ->label('Provider')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider_id')
                    ->label('Provider ID')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('created_at')
                    ->label('Linked')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No social providers linked');
    }
}
