<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\SystemAdmin\Filament\Resources\ConnectedAccountResource\Pages\ListConnectedAccounts;
use Relaticle\SystemAdmin\Filament\Resources\ConnectedAccountResource\Pages\ViewConnectedAccount;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;

final class ConnectedAccountResource extends Resource
{
    protected static ?string $model = ConnectedAccount::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|\UnitEnum|null $navigationGroup = 'Email';

    protected static ?string $modelLabel = 'Mailbox';

    protected static ?string $pluralModelLabel = 'Mailboxes';

    protected static ?string $slug = 'email/mailboxes';

    #[Override]
    public static function canAccess(): bool
    {
        return EmailIntegrationServiceProvider::enabled() && parent::canAccess();
    }

    /**
     * @return Builder<ConnectedAccount>
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['workspace', 'user']);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextEntry::make('email_address'),
                    TextEntry::make('provider')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('capabilities')
                        ->state(fn (ConnectedAccount $record): string => $record->capabilitiesLabel()),
                    TextEntry::make('workspace.name')
                        ->label('Workspace')
                        ->color('primary')
                        ->url(RecordLink::to(WorkspaceResource::class, 'workspace')),
                    TextEntry::make('user.name')
                        ->label('Connected by')
                        ->color('primary')
                        ->url(RecordLink::to(UserResource::class, 'user')),
                    IconEntry::make('sync_inbox')->label('Syncs inbox')->boolean(),
                    IconEntry::make('sync_sent')->label('Syncs sent mail')->boolean(),
                    TextEntry::make('last_error')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])->columnSpanFull()->columns(2),
                Section::make('Sync')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('last_synced_at')
                            ->label('Last mail sync')
                            ->dateTime()
                            ->color(self::syncColor(...))
                            ->placeholder('—'),
                        TextEntry::make('last_calendar_synced_at')
                            ->label('Last calendar sync')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('initial_sync_imported')
                            ->label('Emails imported at first sync')
                            ->numeric(),
                        TextEntry::make('initial_sync_estimated')
                            ->label('Emails estimated at first sync')
                            ->numeric()
                            ->placeholder('—'),
                        TextEntry::make('token_expires_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('calendar_push_expires_at')
                            ->label('Calendar push expires')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Connected')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email_address')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->searchable()
                    ->sortable()
                    ->color('primary')
                    ->url(RecordLink::to(WorkspaceResource::class, 'workspace')),
                TextColumn::make('user.name')
                    ->label('Connected by')
                    ->searchable()
                    ->color('primary')
                    ->url(RecordLink::to(UserResource::class, 'user')),
                TextColumn::make('last_synced_at')
                    ->label('Last mail sync')
                    ->dateTime()
                    ->sortable()
                    ->color(self::syncColor(...))
                    ->placeholder('—'),
                TextColumn::make('last_error')
                    ->limit(60)
                    ->tooltip(fn (ConnectedAccount $record): ?string => $record->last_error)
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Connected')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('needs_attention')
                    ->label('Needs attention')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->needingAttention()),
                SelectFilter::make('status')
                    ->options(EmailAccountStatus::class)
                    ->multiple(),
                SelectFilter::make('provider')
                    ->options(EmailProvider::class),
                SelectFilter::make('workspace')
                    ->relationship('workspace', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function syncColor(ConnectedAccount $record): ?string
    {
        return $record->isStale() ? 'danger' : null;
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListConnectedAccounts::route('/'),
            'view' => ViewConnectedAccount::route('/{record}'),
        ];
    }
}
