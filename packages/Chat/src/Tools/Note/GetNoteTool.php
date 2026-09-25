<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Note;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\CompanyResource;
use App\Http\Resources\V1\NoteResource;
use App\Http\Resources\V1\OpportunityResource;
use App\Http\Resources\V1\PeopleResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Relaticle\Chat\Tools\BaseReadShowTool;

final class GetNoteTool extends BaseReadShowTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'Get a single note by ID with full details.';
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Note;
    }

    protected function resourceClass(): string
    {
        return NoteResource::class;
    }

    /** @return array<string, class-string<JsonResource>> */
    protected function availableIncludes(): array
    {
        return [
            'companies' => CompanyResource::class,
            'people' => PeopleResource::class,
            'opportunities' => OpportunityResource::class,
        ];
    }
}
