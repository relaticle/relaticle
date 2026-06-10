<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\People;

use App\Http\Resources\V1\PeopleResource;
use App\Models\People;
use Relaticle\Chat\Tools\BaseReadShowTool;

final class GetPersonTool extends BaseReadShowTool
{
    public function description(): string
    {
        return 'Get a single person/contact by ID with full details.';
    }

    protected function modelClass(): string
    {
        return People::class;
    }

    protected function resourceClass(): string
    {
        return PeopleResource::class;
    }

    protected function entityLabel(): string
    {
        return 'Person';
    }
}
