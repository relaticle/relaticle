<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Override;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\SystemAdmin\Filament\Resources\ConnectedAccountResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;

final class MailboxesRelationManager extends RelationManager
{
    protected static string $relationship = 'connectedAccounts';

    protected static ?string $title = 'Mailboxes';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-envelope';

    #[Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return EmailIntegrationServiceProvider::enabled() && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->connectedAccounts()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email_address')
            ->columns([
                TextColumn::make('email_address')
                    ->searchable()
                    ->color('primary')
                    ->url(fn (ConnectedAccount $record): string => ConnectedAccountResource::getUrl('view', ['record' => $record])),
                TextColumn::make('provider')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('user.name')
                    ->label('Connected by')
                    ->color('primary')
                    ->url(RecordLink::to(UserResource::class, 'user')),
                TextColumn::make('last_synced_at')
                    ->label('Last mail sync')
                    ->dateTime()
                    ->sortable()
                    ->color(ConnectedAccountResource::syncColor(...))
                    ->placeholder('—'),
                TextColumn::make('last_error')
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Connected')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
