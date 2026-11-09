<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;
use Relaticle\SystemAdmin\Metrics\Money;

final class AiCreditTransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaction')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('type')->badge(),
                        TextEntry::make('workspace.name')
                            ->label(__('cloud::admin.credit_transactions.workspace'))
                            ->color('primary')
                            ->url(RecordLink::to(WorkspaceResource::class, 'workspace')),
                        TextEntry::make('user.name')
                            ->label(__('cloud::admin.credit_transactions.user'))
                            ->placeholder(Money::EMPTY)
                            ->color('primary')
                            ->url(RecordLink::to(UserResource::class, 'user')),
                        TextEntry::make('model'),
                        TextEntry::make('credits_charged')->numeric(),
                        TextEntry::make('input_tokens')->numeric(),
                        TextEntry::make('output_tokens')->numeric(),
                        TextEntry::make('conversation_id')->label(__('cloud::admin.credit_transactions.conversation_id'))->placeholder(Money::EMPTY)->copyable(),
                        TextEntry::make('idempotency_key')->placeholder(Money::EMPTY)->copyable(),
                    ]),
                Section::make('Metadata')
                    ->schema([
                        TextEntry::make('metadata')
                            ->label(__('cloud::admin.credit_transactions.metadata'))
                            ->state(fn (AiCreditTransaction $record): string => json_encode(
                                $record->metadata ?? [],
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            ) ?: '{}')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
