<?php

declare(strict_types=1);

namespace App\Observers\Concerns;

use App\Models\Company;
use App\Models\Favorite;
use App\Models\Opportunity;
use App\Models\People;

trait ForgetsFavorites
{
    public function forceDeleted(Company|People|Opportunity $record): void
    {
        Favorite::query()->withoutGlobalScopes()->forRecord($record)->delete();
    }
}
