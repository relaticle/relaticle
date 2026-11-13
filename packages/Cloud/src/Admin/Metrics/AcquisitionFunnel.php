<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Metrics;

use App\Enums\CreationSource;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\User;
use Illuminate\Support\Collection;
use Relaticle\Cloud\Analytics\Models\Acquisition;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\SystemAdmin\Metrics\ActivityDays;
use Relaticle\SystemAdmin\Metrics\Scopes\GenuineSignup;
use Relaticle\SystemAdmin\Metrics\Scopes\ReachedFirstValue;

final readonly class AcquisitionFunnel
{
    /**
     * @return list<array{source: string, campaign: ?string, landing: string, signups: int, eligible: int, first_value: int,
     *     workspaces: int, setup: int, trial: int, checkout: int, paid: int, renewal_failed: int,
     *     chat: int, mailbox: int, healthy_sync: int, import: int, partial_import: int, mcp: int, mcp_clients: array<string, int>}>
     */
    public static function rows(): array
    {
        $acquisitions = Acquisition::query()
            ->whereIn('user_id', User::query()
                ->withGlobalScope(GenuineSignup::class, new GenuineSignup)
                ->where('created_at', '>=', now()->subDays(90))
                ->select('id'))
            ->with(['user:id,created_at', 'workspace:id,pro_trial_used_at'])
            ->get();

        $firstValue = User::query()
            ->withGlobalScope(ReachedFirstValue::class, new ReachedFirstValue)
            ->whereKey($acquisitions->modelKeys())
            ->pluck('id')->all();
        $workspaceIds = $acquisitions->map(fn (Acquisition $acquisition): ?string => $acquisition->workspace_id)->filter()->all();
        $facts = self::workspaceFacts($workspaceIds);
        $mcpClients = McpConnectors::workspacesByClient($workspaceIds);

        return array_values($acquisitions
            ->groupBy(fn (Acquisition $acquisition): string => "{$acquisition->source}/{$acquisition->campaign}/{$acquisition->landing}")
            ->map(function (Collection $group) use ($firstValue, $facts, $mcpClients): array {
                $eligible = $group->filter(fn (Acquisition $acquisition): bool => $acquisition->user?->created_at?->toDateString() < now()->subDays(7)->toDateString());
                $first = $group->first();
                $row = [
                    'source' => $first->source, 'campaign' => $first->campaign, 'landing' => $first->landing,
                    'signups' => $group->count(), 'eligible' => $eligible->count(),
                    'first_value' => $eligible->filter(fn (Acquisition $acquisition): bool => in_array($acquisition->user_id, $firstValue, true))->count(),
                    'workspaces' => $group->whereNotNull('workspace_linked_at')->count(),
                    'setup' => $group->whereNotNull('setup_completed_at')->count(),
                    'trial' => $group->filter(fn (Acquisition $acquisition): bool => $acquisition->workspace?->pro_trial_used_at !== null)->count(),
                    'checkout' => $group->whereNotNull('first_checkout_at')->count(),
                    'paid' => $group->whereNotNull('first_paid_at')->count(),
                    'renewal_failed' => $group->whereNotNull('first_renewal_failed_at')->count(),
                ];

                return $row
                    + array_map(fn (array $ids): int => self::countIn($group, $ids), $facts)
                    + ['mcp_clients' => self::clientCounts($group, $mcpClients)];
            })
            ->sortBy([['signups', 'desc'], ['source', 'asc'], ['campaign', 'asc'], ['landing', 'asc']])
            ->all());
    }

    /** @param Collection<int, Acquisition> $group
     * @param  array<int, string>  $ids
     */
    private static function countIn(Collection $group, array $ids): int
    {
        return $group->filter(fn (Acquisition $acquisition): bool => in_array($acquisition->workspace_id, $ids, true))->count();
    }

    /** @param Collection<int, Acquisition> $group
     * @param  array<string, array<int, string>>  $workspacesByClient
     * @return array<string, int>
     */
    private static function clientCounts(Collection $group, array $workspacesByClient): array
    {
        return collect($workspacesByClient)
            ->map(fn (array $ids): int => self::countIn($group, $ids))
            ->filter()
            ->sortKeys()
            ->sortDesc()
            ->all();
    }

    /** @param array<int, string> $ids
     * @return array{chat: array<int, string>, mcp: array<int, string>, import: array<int, string>, partial_import: array<int, string>, mailbox: array<int, string>, healthy_sync: array<int, string>}
     */
    private static function workspaceFacts(array $ids): array
    {
        $imports = Import::query()->whereIn('workspace_id', $ids)->completed()->whereRaw('created_rows + updated_rows > 0');

        return [
            'chat' => ActivityDays::from()->whereIn('activity.workspace_id', $ids)
                ->where('activity.kind', 'message')->distinct()->pluck('activity.workspace_id')->map(fn (mixed $id): string => (string) $id)->all(),
            'mcp' => Activity::query()->withoutGlobalScope(WorkspaceScope::class)
                ->whereIn('workspace_id', $ids)->fromSource(CreationSource::MCP)
                ->whereIn('event', ['created', 'updated', 'deleted', 'custom_field_changes'])
                ->distinct()->pluck('workspace_id')->map(fn (mixed $id): string => (string) $id)->all(),
            'import' => (clone $imports)->distinct()->pluck('workspace_id')->map(fn (mixed $id): string => (string) $id)->all(),
            'partial_import' => $imports->where('failed_rows', '>', 0)->distinct()->pluck('workspace_id')->map(fn (mixed $id): string => (string) $id)->all(),
            ...self::mailboxFacts($ids),
        ];
    }

    /** @param array<int, string> $ids
     * @return array{mailbox: array<int, string>, healthy_sync: array<int, string>}
     */
    private static function mailboxFacts(array $ids): array
    {
        if (! EmailIntegrationServiceProvider::enabled()) {
            return ['mailbox' => [], 'healthy_sync' => []];
        }

        $connected = ConnectedAccount::query()->whereIn('workspace_id', $ids)->connected();

        return [
            'mailbox' => (clone $connected)->distinct()->pluck('workspace_id')->map(fn (mixed $id): string => (string) $id)->all(),
            'healthy_sync' => $connected->where('status', EmailAccountStatus::ACTIVE)
                ->whereNotNull('last_synced_at')->whereNotNull('sync_cursor')->whereNull('last_error')
                ->distinct()->pluck('workspace_id')->map(fn (mixed $id): string => (string) $id)->all(),
        ];
    }
}
