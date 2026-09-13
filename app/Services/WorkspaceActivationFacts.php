<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CreationSource;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Request-scoped answers to "what has this workspace done so far".
 *
 * Every question is a single EXISTS probe per entity table, short-circuiting on
 * the first table that answers it, and memoised per workspace for the lifetime of the
 * request or job. Consumers: the onboarding step registry, the activation
 * checklist, and the chat agent's workspace-state block.
 *
 * EXISTS rather than one union of DISTINCT creation_source: the checklist renders
 * from the panel's sidebar footer, so these run on every app page until the
 * workspace is dismissed or fully activated. DISTINCT has to read every live row
 * of every entity table to prove which sources are absent, so its cost grows with
 * the workspace; EXISTS stops at the first matching row. Measured on a 25k-record
 * workspace: 9.46ms for the union against 1.24ms for the probes, and the gap
 * widens from there. Both are index-only scans on idx_<entity>_workspace_activity
 * (workspace_id, deleted_at, creation_source, created_at), so this is about how many
 * rows have to be touched, not about a missing index.
 */
final class WorkspaceActivationFacts
{
    private const array ENTITY_TABLES = ['companies', 'people', 'tasks', 'notes', 'opportunities'];

    /** @var array<string, array{own: bool, any: bool, import: bool, sample: bool}> */
    private array $facts = [];

    /**
     * A record the workspace made itself, in any workspace surface. False in a
     * workspace holding only the records seeded at sign-up.
     */
    public function hasOwnRecord(Workspace $workspace): bool
    {
        return $this->facts($workspace)['own'];
    }

    /**
     * Whether the workspace holds any record at all, seeded or the workspace's own.
     * False only in a workspace that has never been written to: a second
     * workspace (the seeder runs for the personal one only), or one whose demo
     * records were deleted.
     */
    public function hasAnyRecord(Workspace $workspace): bool
    {
        return $this->facts($workspace)['any'];
    }

    public function hasImportedRecord(Workspace $workspace): bool
    {
        return $this->facts($workspace)['import'];
    }

    public function hasSampleData(Workspace $workspace): bool
    {
        return $this->facts($workspace)['sample'];
    }

    public function hasAnotherMember(Workspace $workspace): bool
    {
        return $workspace->users()->exists() || $workspace->workspaceInvitations()->exists();
    }

    public function hasUserChatMessage(Workspace $workspace): bool
    {
        return DB::table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.workspace_id', $workspace->getKey())
            ->where('m.role', 'user')
            ->exists();
    }

    public function sampleRecordCount(Workspace $workspace): int
    {
        $total = 0;

        foreach (self::ENTITY_TABLES as $table) {
            $total += (int) DB::table($table)
                ->where('workspace_id', $workspace->getKey())
                ->where('creation_source', CreationSource::SYSTEM->value)
                ->whereNull('deleted_at')
                ->count();
        }

        return $total;
    }

    public function forget(Workspace $workspace): void
    {
        unset($this->facts[(string) $workspace->getKey()]);
    }

    /**
     * All four creation-source facts in ONE round trip, memoised per workspace.
     *
     * Each fact is an OR of one EXISTS per entity table, so Postgres stops at the
     * first row that answers it and never counts. The previous shape unioned
     * SELECT DISTINCT creation_source across the five tables, which had to read
     * every live row of every table to prove a source was absent.
     *
     * @return array{own: bool, any: bool, import: bool, sample: bool}
     */
    private function facts(Workspace $workspace): array
    {
        $key = (string) $workspace->getKey();

        if (isset($this->facts[$key])) {
            return $this->facts[$key];
        }

        $bindings = [];
        $columns = [];

        foreach ([
            'own' => ['creation_source <> ?', CreationSource::SYSTEM->value],
            'any' => [null, null],
            'import' => ['creation_source = ?', CreationSource::IMPORT->value],
            'sample' => ['creation_source = ?', CreationSource::SYSTEM->value],
        ] as $name => [$predicate, $value]) {
            $parts = [];

            foreach (self::ENTITY_TABLES as $table) {
                $parts[] = "exists(select 1 from {$table} where workspace_id = ? and deleted_at is null"
                    .($predicate === null ? '' : " and {$predicate}").')';
                $bindings[] = $workspace->getKey();

                if ($value !== null) {
                    $bindings[] = $value;
                }
            }

            $columns[] = '('.implode(' or ', $parts).") as {$name}";
        }

        $row = DB::selectOne('select '.implode(', ', $columns), $bindings);

        return $this->facts[$key] = [
            'own' => (bool) ($row->own ?? false),
            'any' => (bool) ($row->any ?? false),
            'import' => (bool) ($row->import ?? false),
            'sample' => (bool) ($row->sample ?? false),
        ];
    }
}
