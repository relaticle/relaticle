<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Relaticle\Chat\Models\AiCreditTransaction;

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
                        TextEntry::make('team.name')->label('Team'),
                        TextEntry::make('user.name')->label('User')->placeholder('—'),
                        TextEntry::make('model'),
                        TextEntry::make('credits_charged')->numeric(),
                        TextEntry::make('input_tokens')->numeric(),
                        TextEntry::make('output_tokens')->numeric(),
                        TextEntry::make('conversation_id')->label('Conversation ID')->placeholder('—')->copyable(),
                        TextEntry::make('idempotency_key')->placeholder('—')->copyable(),
                    ]),
                Section::make('Metadata')
                    ->schema([
                        TextEntry::make('metadata')
                            ->label('Metadata')
                            ->state(fn (AiCreditTransaction $record): string => json_encode(
                                $record->metadata ?? [],
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            ) ?: '{}')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
