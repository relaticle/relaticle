<?php

declare(strict_types=1);

namespace App\Queries\Tasks;

use App\Queries\Concerns\ListsEntity;
use App\Queries\Contracts\EntityQuery;

final readonly class TasksQuery implements EntityQuery
{
    use ListsEntity;

    public static function fields(): array
    {
        return ['id', 'title', 'creator_id', 'created_at', 'updated_at'];
    }

    public static function includes(): array
    {
        return [
            'creator',
            'assignees',
            'companies',
            'people',
            'opportunities',
            'assigneesCount',
            'companiesCount',
            'peopleCount',
            'opportunitiesCount',
        ];
    }
}
