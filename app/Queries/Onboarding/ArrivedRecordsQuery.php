<?php

declare(strict_types=1);

namespace App\Queries\Onboarding;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

final readonly class ArrivedRecordsQuery
{
    private const array SOURCES = [CreationSource::IMPORT->value, CreationSource::MAILBOX->value];

    /** @return array<string, array{people: int, companies: int}> */
    public function today(User $user, Workspace $workspace): array
    {
        if (! $user->belongsToWorkspaceId((string) $workspace->getKey())) {
            return [];
        }

        $since = today($workspace->ownerTimezone())->utc();
        $arrived = [];

        foreach (['people' => People::class, 'companies' => Company::class] as $key => $model) {
            $totals = $model::query()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('creation_source', self::SOURCES)
                ->where('created_at', '>=', $since)
                ->toBase()
                ->selectRaw('creation_source, count(*) as total')
                ->groupBy('creation_source')
                ->pluck('total', 'creation_source');

            foreach ($totals as $creationSource => $total) {
                $arrived[$creationSource] ??= ['people' => 0, 'companies' => 0];
                $arrived[$creationSource][$key] = (int) $total;
            }
        }

        return $arrived;
    }

    public function anySince(User $user, Workspace $workspace, CarbonImmutable $moment): bool
    {
        if (! $user->belongsToWorkspaceId((string) $workspace->getKey())) {
            return false;
        }

        return array_any(
            [People::class, Company::class],
            fn (string $model): bool => $model::query()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('creation_source', self::SOURCES)
                ->where('created_at', '>=', $moment)
                ->exists(),
        );
    }
}
