<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Metrics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class McpConnectors
{
    // A client names itself when it registers. Claude Code appends a label in parentheses, such as "Claude Code (relaticle)".
    public static function query(): Builder
    {
        return DB::table('oauth_access_tokens')
            ->join('oauth_clients', 'oauth_clients.id', '=', 'oauth_access_tokens.client_id')
            ->whereNotNull('oauth_access_tokens.workspace_id')
            ->distinct()
            ->selectRaw("oauth_access_tokens.workspace_id, regexp_replace(oauth_clients.name, '\\s*\\(.*\\)$', '') as client");
    }

    /** @param array<int, string> $workspaceIds
     * @return array<string, array<int, string>>
     */
    public static function workspacesByClient(array $workspaceIds): array
    {
        return self::query()
            ->whereIn('oauth_access_tokens.workspace_id', $workspaceIds)
            ->get()
            ->groupBy('client')
            ->map(fn (Collection $rows): array => $rows->pluck('workspace_id')->map(fn (mixed $id): string => (string) $id)->all())
            ->all();
    }
}
