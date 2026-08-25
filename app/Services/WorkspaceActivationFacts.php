<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CreationSource;
use App\Models\Team;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Request-scoped answers to "what has this workspace done so far".
 *
 * One union query per team covers every creation-source question; the
 * remaining facts are single EXISTS queries. Consumers: the onboarding step
 * registry, the activation checklist, and the chat agent's workspace-state
 * block.
 */
final class WorkspaceActivationFacts
{
    private const array ENTITY_TABLES = ['companies', 'people', 'tasks', 'notes', 'opportunities'];

    /** @var array<string, list<string>> */
    private array $creationSources = [];

    public function hasOwnRecord(Team $team): bool
    {
        return array_any(
            $this->creationSources($team),
            fn (string $source): bool => $source !== CreationSource::SYSTEM->value,
        );
    }

    public function hasImportedRecord(Team $team): bool
    {
        return in_array(CreationSource::IMPORT->value, $this->creationSources($team), true);
    }

    public function hasSampleData(Team $team): bool
    {
        return in_array(CreationSource::SYSTEM->value, $this->creationSources($team), true);
    }

    public function hasTeammate(Team $team): bool
    {
        return $team->users()->exists() || $team->teamInvitations()->exists();
    }

    public function hasUserChatMessage(Team $team): bool
    {
        return DB::table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.team_id', $team->getKey())
            ->where('m.role', 'user')
            ->exists();
    }

    public function sampleRecordCount(Team $team): int
    {
        $total = 0;

        foreach (self::ENTITY_TABLES as $table) {
            $total += (int) DB::table($table)
                ->where('team_id', $team->getKey())
                ->where('creation_source', CreationSource::SYSTEM->value)
                ->whereNull('deleted_at')
                ->count();
        }

        return $total;
    }

    public function forget(Team $team): void
    {
        unset($this->creationSources[(string) $team->getKey()]);
    }

    /**
     * @return list<string>
     */
    public function creationSources(Team $team): array
    {
        $key = (string) $team->getKey();

        if (isset($this->creationSources[$key])) {
            return $this->creationSources[$key];
        }

        $query = null;

        foreach (self::ENTITY_TABLES as $table) {
            $branch = DB::table($table)
                ->select('creation_source')
                ->where('team_id', $team->getKey())
                ->whereNull('deleted_at')
                ->distinct();

            $query = $query instanceof QueryBuilder ? $query->union($branch) : $branch;
        }

        /** @var QueryBuilder $query */
        $sources = array_map(
            fn (mixed $source): string => (string) $source,
            $query->pluck('creation_source')->all(),
        );

        return $this->creationSources[$key] = array_values(array_unique($sources));
    }
}
