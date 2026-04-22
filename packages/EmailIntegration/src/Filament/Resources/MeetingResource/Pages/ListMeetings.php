<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Resources\MeetingResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Relaticle\EmailIntegration\Filament\Resources\MeetingResource;

final class ListMeetings extends ListRecords
{
    protected static string $resource = MeetingResource::class;
}
