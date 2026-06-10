<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource;
use Throwable;

final readonly class RecordReferenceResolver
{
    /**
     * @return array{id: string, type: string, url: string}|null
     */
    public function resolve(string $entityType, string $recordId): ?array
    {
        try {
            $url = match ($entityType) {
                'company' => CompanyResource::getUrl('view', ['record' => $recordId]),
                'people' => PeopleResource::getUrl('view', ['record' => $recordId]),
                'opportunity' => OpportunityResource::getUrl('view', ['record' => $recordId]),
                'task' => TaskResource::getUrl('index'),
                'note' => NoteResource::getUrl('index'),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        if ($url === null) {
            return null;
        }

        return [
            'id' => $recordId,
            'type' => $entityType,
            'url' => $url,
        ];
    }
}
