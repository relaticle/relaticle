<?php

declare(strict_types=1);

namespace App\Queries\People;

use App\Queries\Concerns\ListsEntity;
use App\Queries\Contracts\EntityQuery;

final readonly class PeopleQuery implements EntityQuery
{
    use ListsEntity;

    public static function fields(): array
    {
        return ['id', 'name', 'company_id', 'creator_id', 'created_at', 'updated_at'];
    }

    public static function includes(): array
    {
        return [
            'creator',
            'company',
            'tasksCount',
            'notesCount',
        ];
    }
}
