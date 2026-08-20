<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Filament\Pages\Team\CustomFields;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\EditAction;
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
        $url = $this->urlFor($entityType, $recordId);

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

    /**
     * @param  Team|null  $team  The tenant to build the panel URL against. Defaults to the
     *                           authenticated user's current team. Pass the record's own team
     *                           explicitly when the caller already resolved a specific record
     *                           (e.g. the `/r/{type}/{id}` redirect) so a citation for a record
     *                           in a non-current team still lands on that team's panel.
     */
    public function urlFor(string $entityType, string $recordId, ?Team $team = null): ?string
    {
        $authUser = auth()->user();

        if (! $authUser instanceof User) {
            return null;
        }

        $team ??= $authUser->currentTeam;

        if ($team === null) {
            return null;
        }

        try {
            return match ($entityType) {
                // Custom field definitions have no per-record route — the management page
                // is the destination, deep-linked to the tab the field lives on.
                'custom_field' => $this->customFieldUrl($recordId, $team),
                'company' => CompanyResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
                'people' => PeopleResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
                'opportunity' => OpportunityResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
                'task' => TaskResource::getUrl('index', [
                    'tableAction' => EditAction::getDefaultName(),
                    'tableActionRecord' => $recordId,
                ], panel: 'app', tenant: $team),
                'note' => NoteResource::getUrl('index', [
                    'tableAction' => EditAction::getDefaultName(),
                    'tableActionRecord' => $recordId,
                ], panel: 'app', tenant: $team),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The custom-fields management page, focused on the tab owning this field.
     * Scoped explicitly to the team and past every global scope: this also runs on
     * conversation reload and for deactivated fields, where neither the ambient
     * custom-fields tenant context nor the activable scope can be relied on.
     */
    private function customFieldUrl(string $recordId, Team $team): ?string
    {
        $entityType = CustomField::query()
            ->withoutGlobalScopes()
            ->whereKey($recordId)
            ->where('tenant_id', $team->getKey())
            ->value('entity_type');

        if (! is_string($entityType) || $entityType === '') {
            return null;
        }

        return CustomFields::getUrl(panel: 'app', tenant: $team).'?'.http_build_query([
            'currentEntityType' => $entityType,
        ]);
    }

    private function resolveLabel(string $entityType, string $recordId): ?string
    {
        try {
            $label = match ($entityType) {
                'custom_field' => CustomField::query()
                    ->withoutGlobalScopes()
                    ->whereKey($recordId)
                    ->value('name'),
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
