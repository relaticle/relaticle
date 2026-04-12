<?php

declare(strict_types=1);

namespace App\Filament\Resources\PeopleResource\Pages;

use App\Filament\Pages\BaseRecordEmailsPage;
use App\Filament\Resources\PeopleResource;

final class PeopleEmailsPage extends BaseRecordEmailsPage
{
    protected static string $resource = PeopleResource::class;
}
