<?php

declare(strict_types=1);

namespace App\Queries\Opportunities;

use App\Queries\Concerns\ListsEntity;
use App\Queries\Contracts\EntityQuery;

final readonly class OpportunitiesQuery implements EntityQuery
{
    use ListsEntity;

    public static function fields(): array
    {
        return ['id', 'name', 'company_id', 'contact_id', 'creator_id', 'created_at', 'updated_at'];
    }

    public static function includes(): array
    {
        return [
            'creator',
            'company',
            'contact',
            'tasksCount',
            'notesCount',
        ];
    }
}
