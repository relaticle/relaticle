<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Models\Email;

#[Queue('emails-sync')]
#[Tries(3)]
#[UniqueFor(600)]
final class MergeConversations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $key,
        public readonly string $otherKey,
    ) {}

    public function uniqueId(): string
    {
        $pair = [$this->key, $this->otherKey];
        sort($pair);

        return "{$this->workspaceId}:".implode(':', $pair);
    }

    public function handle(CurrentWorkspace $currentWorkspace, RefreshConversationSummary $refresh): void
    {
        $currentWorkspace->within($this->workspaceId, function () use ($refresh): void {
            [$keep, $retire] = $this->survivorFirst();

            Email::query()->withoutGlobalScopes()
                ->where('workspace_id', $this->workspaceId)
                ->where('conversation_key', $retire)
                ->toBase()
                ->update(['conversation_key' => $keep]);

            $refresh->execute($this->workspaceId, $retire);
            $refresh->execute($this->workspaceId, $keep);
        });
    }

    /** @return array{string, string} */
    private function survivorFirst(): array
    {
        $keep = Email::query()->withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->whereIn('conversation_key', [$this->key, $this->otherKey])
            ->groupBy('conversation_key')
            ->orderByRaw('min(sent_at) asc nulls last, conversation_key asc')
            ->value('conversation_key') ?? $this->key;

        return [$keep, $keep === $this->key ? $this->otherKey : $this->key];
    }
}
