<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Resources\SubscriptionResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Relaticle\Cloud\Admin\Filament\Resources\SubscriptionResource;

final class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;
}
