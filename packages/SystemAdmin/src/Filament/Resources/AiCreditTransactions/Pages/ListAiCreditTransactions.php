<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\Pages;

use Filament\Resources\Pages\ListRecords;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\AiCreditTransactionResource;

final class ListAiCreditTransactions extends ListRecords
{
    protected static string $resource = AiCreditTransactionResource::class;
}
