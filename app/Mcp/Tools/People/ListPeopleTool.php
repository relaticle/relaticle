<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Actions\People\ListPeople;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\PeopleResource;
use App\Mcp\Tools\BaseListTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('List People')]
#[Description('List people (contacts) in the CRM with optional filters and pagination.')]
final class ListPeopleTool extends BaseListTool
{
    protected function actionClass(): string
    {
        return ListPeople::class;
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::People;
    }

    protected function resourceClass(): string
    {
        return PeopleResource::class;
    }
}
