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
use Relaticle\EmailIntegration\Models\Email;
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
    ) {}

    public function uniqueId(): string
    {
        return "{$this->workspaceId}:{$this->emailId}";
    }

    public function handle(CurrentWorkspace $currentWorkspace, ConversationKeyResolver $resolver, RefreshConversationSummary $refresh): void
    {
        $currentWorkspace->within($this->workspaceId, function () use ($resolver, $refresh): void {
            $keys = DB::transaction(fn (): array => $this->mergeLinkedKeys($resolver));

            foreach ($keys as $key) {
                $refresh->execute($this->workspaceId, $key);
            }
        });
    }

    /** @return list<string> */
    private function mergeLinkedKeys(ConversationKeyResolver $resolver): array
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

        $keys = array_values(array_unique([$email->conversation_key, ...$resolver->keysToMerge($email)]));

        if (count($keys) < 2) {
            return [];
        }

        $survivor = $this->oldest($keys);

        Email::query()->withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->whereIn('conversation_key', array_values(array_diff($keys, [$survivor])))
            ->toBase()
            ->update(['conversation_key' => $survivor]);

        return $keys;
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
