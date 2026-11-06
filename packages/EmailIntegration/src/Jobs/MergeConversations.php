<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\ConversationKeyResolver;

#[Queue('emails-sync')]
#[Tries(3)]
#[UniqueFor(600)]
final class MergeConversations implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $emailId,
        public readonly bool $broadcast = true,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->workspaceId}:{$this->emailId}";
    }

    public function handle(CurrentWorkspace $currentWorkspace, ConversationKeyResolver $resolver, RefreshConversationSummary $refresh): void
    {
        // One transaction, so a failed refresh rolls the rewrite back and the retry merges again.
        $currentWorkspace->within($this->workspaceId, function () use ($resolver, $refresh): void {
            $keys = DB::transaction(function () use ($resolver, $refresh): array {
                $keys = $this->linkedKeys($resolver);
                $audiences = $this->audiences($keys);
                $this->rewrite(array_slice($keys, 1), $keys[0] ?? '');
                $this->refreshMerged($keys, $audiences, $refresh);

                return $keys;
            });

            if ($keys !== []) {
                $this->moveStrandedRows($keys[0], array_slice($keys, 1), $refresh);
            }
        });
    }

    /**
     * A row keyed outside the workspace lock, such as by the backfill, may still hold an absorbed key.
     *
     * @param  list<string>  $absorbed
     */
    private function moveStrandedRows(string $survivor, array $absorbed, RefreshConversationSummary $refresh): void
    {
        DB::transaction(function () use ($survivor, $absorbed, $refresh): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$this->workspaceId]);

            $audiences = $this->audiences([$survivor, ...$absorbed]);

            if ($this->rewrite($absorbed, $survivor) > 0) {
                $this->refreshMerged([$survivor, ...$absorbed], $audiences, $refresh);
            }
        });
    }

    // Read before the rows move, so each absorbed key signals the members who saw it.
    /**
     * @param  list<string>  $keys
     * @return array<string, list<string>>
     */
    private function audiences(array $keys): array
    {
        return $this->broadcast && count($keys) > 1 && ConversationChanged::broadcasts()
            ? resolve(ConversationsQuery::class)->audiences($this->workspaceId, $keys)
            : [];
    }

    /** @param  list<string>  $absorbed */
    private function rewrite(array $absorbed, string $survivor): int
    {
        if ($absorbed === []) {
            return 0;
        }

        return Email::query()->withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->whereIn('conversation_key', $absorbed)
            ->toBase()
            ->update(['conversation_key' => $survivor]);
    }

    /**
     * @param  list<string>  $keys  the surviving key first
     * @param  array<string, list<string>>  $audiences
     */
    private function refreshMerged(array $keys, array $audiences, RefreshConversationSummary $refresh): void
    {
        foreach ($keys as $index => $key) {
            $refresh->execute($this->workspaceId, $key, $this->broadcast, $index === 0 ? [] : ($audiences[$key] ?? []));
        }
    }

    /** @return list<string> the surviving key first, then the keys it absorbs */
    private function linkedKeys(ConversationKeyResolver $resolver): array
    {
        // One merge per workspace at a time, so each run reads the keys the previous one left.
        DB::select('select pg_advisory_xact_lock(hashtext(?))', [$this->workspaceId]);

        $email = Email::query()->withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->whereKey($this->emailId)
            ->first();

        if (! $email instanceof Email || $email->conversation_key === null) {
            return [];
        }

        $keys = [$email->conversation_key, ...$resolver->keysToMerge($email)];

        if (count($keys) < 2) {
            return [];
        }

        $survivor = $this->oldest($keys);

        return [$survivor, ...array_values(array_diff($keys, [$survivor]))];
    }

    /** @param  non-empty-list<string>  $keys */
    private function oldest(array $keys): string
    {
        $survivor = Email::query()->withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->whereIn('conversation_key', $keys)
            ->groupBy('conversation_key')
            ->orderByRaw('min(sent_at) asc nulls last, conversation_key asc')
            ->value('conversation_key');

        return is_string($survivor) ? $survivor : min($keys);
    }
}
