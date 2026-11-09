<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\Pages;

use Filament\Resources\Pages\ListRecords;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\AiCreditTransactionResource;

final class ListAiCreditTransactions extends ListRecords
{
    protected static string $resource = AiCreditTransactionResource::class;
}
