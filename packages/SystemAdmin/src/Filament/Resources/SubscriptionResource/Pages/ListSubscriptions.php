<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource;

final class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;
}
