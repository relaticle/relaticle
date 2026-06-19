<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Throwable;

final readonly class RecordReferenceResolver
{
    /**
     * @param  array<int|string, mixed>  $ids
     * @return list<array{id: string, type: string, url: string, label: string|null}>
     */
    public function resolveMany(string $entityType, array $ids, int $cap = 10): array
    {
        $refs = [];

        foreach (array_slice($ids, 0, $cap) as $id) {
            if (! is_string($id) && ! is_int($id)) {
                continue;
            }

            $ref = $this->resolve($entityType, (string) $id);

            if ($ref !== null) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * @return array{id: string, type: string, url: string, label: string|null}|null
     */
    public function resolve(string $entityType, string $recordId): ?array
    {
        $authUser = auth()->user();

        if (! $authUser instanceof User) {
            return null;
        }

        $team = $authUser->currentTeam;

        if ($team === null) {
            return null;
        }

        try {
            $url = match ($entityType) {
                'company' => CompanyResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
                'people' => PeopleResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
                'opportunity' => OpportunityResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
                'task' => TaskResource::getUrl('index', panel: 'app', tenant: $team),
                'note' => NoteResource::getUrl('index', panel: 'app', tenant: $team),
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
            'label' => $this->resolveLabel($entityType, $recordId),
        ];
    }

    private function resolveLabel(string $entityType, string $recordId): ?string
    {
        try {
            $label = match ($entityType) {
                'company' => Company::query()->whereKey($recordId)->value('name'),
                'people' => People::query()->whereKey($recordId)->value('name'),
                'opportunity' => Opportunity::query()->whereKey($recordId)->value('name'),
                'task' => Task::query()->whereKey($recordId)->value('title'),
                'note' => Note::query()->whereKey($recordId)->value('title'),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return is_string($label) && $label !== '' ? $label : null;
    }
}
