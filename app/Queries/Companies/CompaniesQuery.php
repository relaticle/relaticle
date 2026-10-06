<?php

declare(strict_types=1);

namespace App\Queries\Companies;

use App\Queries\Concerns\ListsEntity;
use App\Queries\Contracts\EntityQuery;

final readonly class CompaniesQuery implements EntityQuery
{
    use ListsEntity;

    public static function fields(): array
    {
        return ['id', 'name', 'creator_id', 'account_owner_id', 'created_at', 'updated_at'];
    }

    public static function includes(): array
    {
        return [
            'creator',
            'accountOwner',
            'people',
            'opportunities',
            'peopleCount',
            'opportunitiesCount',
            'tasksCount',
            'notesCount',
        ];
    }
}
