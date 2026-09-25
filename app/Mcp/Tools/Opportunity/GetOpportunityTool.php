<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\OpportunityResource;
use App\Mcp\Tools\BaseShowTool;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Get Opportunity')]
#[Description('Get a single opportunity by ID with full details and relationships.')]
final class GetOpportunityTool extends BaseShowTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::Opportunity;
    }

    /** @return class-string<JsonResource> */
    protected function resourceClass(): string
    {
        return OpportunityResource::class;
    }

    /** @return array<int, string> */
    protected function allowedIncludes(): array
    {
        return ['creator', 'company', 'contact', 'tasks', 'notes', 'tasksCount', 'notesCount'];
    }
}
