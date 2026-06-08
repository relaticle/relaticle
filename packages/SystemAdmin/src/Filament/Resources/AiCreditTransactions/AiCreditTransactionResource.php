<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\Pages\ListAiCreditTransactions;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\Pages\ViewAiCreditTransaction;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\Schemas\AiCreditTransactionInfolist;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\Tables\AiCreditTransactionsTable;
use UnitEnum;

final class AiCreditTransactionResource extends Resource
{
    protected static ?string $model = AiCreditTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Credit Transaction';

    protected static ?string $pluralModelLabel = 'Credit Transactions';

    protected static ?string $slug = 'ai/credit-transactions';

    public static function infolist(Schema $schema): Schema
    {
        return AiCreditTransactionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiCreditTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiCreditTransactions::route('/'),
            'view' => ViewAiCreditTransaction::route('/{record}'),
        ];
    }
}
