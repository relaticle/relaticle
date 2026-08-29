<?php

declare(strict_types=1);

namespace App\Livewire\App\AccessTokens;

use App\Actions\Mcp\RevokeOAuthConnector;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * Lists the OAuth clients (Claude, ChatGPT, any MCP client) this user has authorized,
 * so the revocation the privacy policy promises actually exists.
 */
final class ManageOAuthConnectors extends BaseLivewireComponent implements HasTable
{
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        $userId = $this->authUser()->getKey();

        return $table
            ->query(fn (): Builder => Passport::client()->newQuery()
                ->whereIn('id', fn (QueryBuilder $query) => $query
                    ->select('client_id')
                    ->from('oauth_access_tokens')
                    ->where('user_id', $userId)
                    ->where('revoked', false)
                    ->where('expires_at', '>', now()))
                ->addSelect(['bound_team_id' => DB::table('oauth_access_tokens')
                    ->select('team_id')
                    ->whereColumn('client_id', 'oauth_clients.id')
                    ->where('user_id', $userId)
                    ->where('revoked', false)
                    ->latest('created_at')
                    ->limit(1),
                ])
                ->withCount([
                    'tokens as active_tokens_count' => fn (Builder $query) => $query
                        ->where('user_id', $userId)
                        ->where('revoked', false)
                        ->where('expires_at', '>', now()),
                ]))
            ->columns([
                TextColumn::make('name')->label(__('access-tokens.connectors.columns.name')),
                TextColumn::make('bound_team_id')
                    ->label(__('access-tokens.connectors.columns.team'))
                    ->formatStateUsing(fn (?string $state): ?string => $state === null
                        ? null
                        : Team::query()->whereKey($state)->value('name'))
                    ->placeholder(__('access-tokens.table.placeholders.no_team')),
                TextColumn::make('active_tokens_count')
                    ->label(__('access-tokens.connectors.columns.active_tokens'))
                    ->badge(),
                TextColumn::make('created_at')
                    ->label(__('access-tokens.table.columns.created_at'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('access-tokens.connectors.actions.revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->iconButton()
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('access-tokens.connectors.modals.revoke.title'))
                    ->modalDescription(__('access-tokens.connectors.modals.revoke.description'))
                    ->action(function (Client $record, RevokeOAuthConnector $revoke): void {
                        $revoke->execute($this->authUser(), (string) $record->getKey());

                        $this->sendNotification(
                            title: __('access-tokens.connectors.notifications.revoked'),
                        );
                    }),
            ])
            ->emptyStateHeading(__('access-tokens.connectors.empty_state.heading'))
            ->emptyStateDescription(__('access-tokens.connectors.empty_state.description'))
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.app.access-tokens.manage-oauth-connectors');
    }
}
