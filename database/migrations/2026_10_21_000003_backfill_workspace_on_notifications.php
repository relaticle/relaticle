<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array ACCOUNT_LEVEL_ACTIONS = ['billing', 'download_csv', 'download_xlsx'];

    public function up(): void
    {
        DB::table('notifications')
            ->select(['id', 'data'])
            ->whereNull('reason')
            ->whereNull('workspace_id')
            ->whereNull('archived_at')
            ->chunkById(500, function (Collection $rows): void {
                $this->backfill($this->viewDataById($rows));
            });
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function viewDataById(Collection $rows): array
    {
        $viewData = [];

        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true);
            $data = is_array($data) ? $data : [];

            if (in_array(data_get($data, 'actions.0.name'), self::ACCOUNT_LEVEL_ACTIONS, true)) {
                continue;
            }

            $viewData[(string) $row->id] = is_array($data['viewData'] ?? null) ? $data['viewData'] : [];
        }

        return $viewData;
    }

    /** @param  array<string, array<string, mixed>>  $viewData */
    private function backfill(array $viewData): void
    {
        $lookups = $this->workspaceLookups($viewData);

        $workspaces = collect($viewData)->map(fn (array $view): ?string => $this->workspaceFor($view, $lookups));

        $workspaces
            ->filter()
            ->groupBy(fn (string $workspaceId): string => $workspaceId, preserveKeys: true)
            ->each(fn (Collection $group, string $workspaceId): int => DB::table('notifications')
                ->whereIn('id', $group->keys())
                ->update(['workspace_id' => $workspaceId]));

        DB::table('notifications')
            ->whereIn('id', $workspaces->whereNull()->keys())
            ->update(['archived_at' => now()]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $viewData
     * @return array<string, Collection<string, string>>
     */
    private function workspaceLookups(array $viewData): array
    {
        return [
            'task_id' => DB::table('tasks')
                ->whereIn('id', $this->ids($viewData, 'task_id'))
                ->pluck('workspace_id', 'id'),
            'request_id' => DB::table('email_access_requests')
                ->join('emails', 'emails.id', '=', 'email_access_requests.email_id')
                ->whereIn('email_access_requests.id', $this->ids($viewData, 'request_id'))
                ->pluck('emails.workspace_id', 'email_access_requests.id'),
            'account_id' => DB::table('connected_accounts')
                ->whereIn('id', $this->ids($viewData, 'account_id'))
                ->pluck('workspace_id', 'id'),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $viewData
     * @return list<string>
     */
    private function ids(array $viewData, string $key): array
    {
        $ids = [];

        foreach ($viewData as $view) {
            if (is_string($view[$key] ?? null)) {
                $ids[] = $view[$key];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $view
     * @param  array<string, Collection<string, string>>  $lookups
     */
    private function workspaceFor(array $view, array $lookups): ?string
    {
        foreach ($lookups as $key => $workspaces) {
            $recordId = $view[$key] ?? null;
            $workspaceId = is_string($recordId) ? $workspaces->get($recordId) : null;

            if (is_string($workspaceId)) {
                return $workspaceId;
            }
        }

        return null;
    }
};
