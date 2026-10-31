<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\Pages\ListAiCreditTransactions;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\Pages\ViewAiCreditTransaction;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\Schemas\AiCreditTransactionInfolist;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\Tables\AiCreditTransactionsTable;

final class AiCreditTransactionResource extends Resource
{
    protected static ?string $model = AiCreditTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'ai/credit-transactions';

    public static function getNavigationGroup(): string
    {
        return __('cloud::admin.navigation.ai');
    }

    public static function getModelLabel(): string
    {
        return __('cloud::admin.credit_transactions.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cloud::admin.credit_transactions.plural_model_label');
    }

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
