<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CrmEntity;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource;
use App\Models\Team;
use Filament\Actions\EditAction;
use Throwable;

/**
 * Builds and parses the canonical, browser-openable URL for a CRM record.
 *
 * Both directions live here on purpose: the MCP search tool publishes these URLs as
 * citations and the fetch tool has to resolve the same strings back to a record. When
 * the two were written independently they disagreed — search emitted a tenant-less
 * path that 404s in a browser, and fetch rejected the real URL a user copies from the
 * address bar.
 *
 * Tasks and notes have no per-record page (they are managed from their index table), so
 * their canonical URL is the index deep link that opens the record's edit modal.
 */
final readonly class CanonicalRecordUrl
{
    public function build(CrmEntity $entity, string $recordId, Team $team): ?string
    {
        // The match sits outside the try on purpose: catching Throwable there would
        // swallow the UnhandledMatchError a newly added entity raises, turning a
        // missing case into a silently null URL. Only the route lookup is guarded.
        $route = match ($entity) {
            CrmEntity::Company => static fn (): string => CompanyResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
            CrmEntity::People => static fn (): string => PeopleResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
            CrmEntity::Opportunity => static fn (): string => OpportunityResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
            CrmEntity::Task => fn (): string => TaskResource::getUrl('index', $this->modalQuery($recordId), panel: 'app', tenant: $team),
            CrmEntity::Note => fn (): string => NoteResource::getUrl('index', $this->modalQuery($recordId), panel: 'app', tenant: $team),
        };

        try {
            return $route();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{entity: CrmEntity, id: string}|null
     */
    public function parse(string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $s): bool => $s !== ''));

        if (($segments[0] ?? null) === config('app.app_panel_path', 'app')) {
            array_shift($segments);
        }

        // Past the tenant slug: [{tenant}, {segment}, {record?}]
        $segment = $segments[1] ?? null;
        $entity = $segment === null ? null : $this->entityForSegment($segment);

        if (! $entity instanceof CrmEntity) {
            return null;
        }

        if ($this->isModalManaged($entity)) {
            $recordId = $this->queryParam($url, 'tableActionRecord');

            return $recordId === null ? null : ['entity' => $entity, 'id' => $recordId];
        }

        $recordId = $segments[2] ?? null;

        return is_string($recordId)
            ? ['entity' => $entity, 'id' => $recordId]
            : null;
    }

    private function entityForSegment(string $segment): ?CrmEntity
    {
        foreach (CrmEntity::cases() as $entity) {
            if ($this->segment($entity) === $segment) {
                return $entity;
            }
        }

        return null;
    }

    private function segment(CrmEntity $entity): string
    {
        return match ($entity) {
            CrmEntity::Company => 'companies',
            CrmEntity::People => 'people',
            CrmEntity::Opportunity => 'opportunities',
            CrmEntity::Task => 'tasks',
            CrmEntity::Note => 'notes',
        };
    }

    private function isModalManaged(CrmEntity $entity): bool
    {
        return match ($entity) {
            CrmEntity::Task, CrmEntity::Note => true,
            CrmEntity::Company, CrmEntity::People, CrmEntity::Opportunity => false,
        };
    }

    /**
     * @return array<string, string>
     */
    private function modalQuery(string $recordId): array
    {
        return [
            'tableAction' => EditAction::getDefaultName(),
            'tableActionRecord' => $recordId,
        ];
    }

    private function queryParam(string $url, string $key): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query)) {
            return null;
        }

        parse_str($query, $parsed);

        $value = $parsed[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
