<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\Pages;

use Filament\Resources\Pages\ViewRecord;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\AiCreditTransactionResource;

final class ViewAiCreditTransaction extends ViewRecord
{
    protected static string $resource = AiCreditTransactionResource::class;
}
