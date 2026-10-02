<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Opportunity;

use App\Actions\Opportunity\ListOpportunities;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\NoteResource;
use App\Http\Resources\V1\OpportunityResource;
use App\Http\Resources\V1\TaskResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Relaticle\Chat\Tools\BaseReadListTool;

final class ListOpportunitiesTool extends BaseReadListTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'List opportunities/deals with optional filters and pagination.';
    }

    protected function actionClass(): string
    {
        return ListOpportunities::class;
    }

    protected function resourceClass(): string
    {
        return OpportunityResource::class;
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Opportunity;
    }

    /** @return array<string, class-string<JsonResource>> */
    protected function availableIncludes(): array
    {
        return [
            'notes' => NoteResource::class,
            'tasks' => TaskResource::class,
        ];
    }
}
