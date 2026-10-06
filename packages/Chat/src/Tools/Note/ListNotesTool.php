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
use Relaticle\Chat\Tools\BaseReadListTool;

final class ListNotesTool extends BaseReadListTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'List notes with optional filters and pagination.';
    }

    protected function resourceClass(): string
    {
        return NoteResource::class;
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Note;
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
