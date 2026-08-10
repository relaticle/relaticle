<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Http\Resources\V1\OpportunityResource;
use App\Mcp\Tools\BaseShowTool;
use App\Models\Opportunity;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get a single opportunity by ID with full details and relationships.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetOpportunityTool extends BaseShowTool
{
    protected function modelClass(): string
    {
        return Opportunity::class;
    }

    /** @return class-string<JsonResource> */
    protected function resourceClass(): string
    {
        return OpportunityResource::class;
    }

    protected function entityLabel(): string
    {
        return 'Opportunity';
    }

    /** @return array<int, string> */
    protected function allowedIncludes(): array
    {
        return ['creator', 'company', 'contact', 'tasksCount', 'notesCount'];
    }
}
