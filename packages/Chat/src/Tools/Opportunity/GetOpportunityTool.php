<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Opportunity;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\NoteResource;
use App\Http\Resources\V1\OpportunityResource;
use App\Http\Resources\V1\TaskResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Relaticle\Chat\Tools\BaseReadShowTool;

final class GetOpportunityTool extends BaseReadShowTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'Get a single opportunity/deal by ID with full details.';
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Opportunity;
    }

    protected function resourceClass(): string
    {
        return OpportunityResource::class;
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
