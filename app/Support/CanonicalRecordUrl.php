<?php

declare(strict_types=1);

namespace App\Support;

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
    /** @var array<string, string> */
    private const array SEGMENTS = [
        'company' => 'companies',
        'person' => 'people',
        'opportunity' => 'opportunities',
        'task' => 'tasks',
        'note' => 'notes',
    ];

    /** @var list<string> */
    private const array MODAL_TYPES = ['task', 'note'];

    public function build(string $type, string $recordId, Team $team): ?string
    {
        try {
            return match ($type) {
                'company' => CompanyResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
                'person' => PeopleResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
                'opportunity' => OpportunityResource::getUrl('view', ['record' => $recordId], panel: 'app', tenant: $team),
                'task' => TaskResource::getUrl('index', $this->modalQuery($recordId), panel: 'app', tenant: $team),
                'note' => NoteResource::getUrl('index', $this->modalQuery($recordId), panel: 'app', tenant: $team),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{type: string, id: string}|null
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
        $type = $segment === null ? null : array_search($segment, self::SEGMENTS, true);

        if (! is_string($type)) {
            return null;
        }

        if (in_array($type, self::MODAL_TYPES, true)) {
            $recordId = $this->queryParam($url, 'tableActionRecord');

            return $recordId === null ? null : ['type' => $type, 'id' => $recordId];
        }

        $recordId = $segments[2] ?? null;

        return is_string($recordId)
            ? ['type' => $type, 'id' => $recordId]
            : null;
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
