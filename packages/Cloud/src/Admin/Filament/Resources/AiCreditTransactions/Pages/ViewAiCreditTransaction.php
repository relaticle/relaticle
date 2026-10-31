<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\Pages;

use Filament\Resources\Pages\ViewRecord;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\AiCreditTransactionResource;

final class ViewAiCreditTransaction extends ViewRecord
{
    protected static string $resource = AiCreditTransactionResource::class;
}
