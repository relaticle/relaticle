<?php

declare(strict_types=1);

namespace App\Filament\Resources\PeopleResource\Pages;

use App\Filament\Resources\PeopleResource;
use Relaticle\EmailIntegration\Filament\Pages\BaseRecordEmailsPage;

final class PeopleEmailsPage extends BaseRecordEmailsPage
{
    protected static string $resource = PeopleResource::class;
}
