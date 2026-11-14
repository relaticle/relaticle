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
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\ConversationKeyResolver;

#[Queue('emails-sync')]
#[Tries(3)]
#[UniqueFor(600)]
final class SettleConversation implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
            $email = Email::query()->withoutGlobalScopes()
                ->where('workspace_id', $this->workspaceId)
                ->whereKey($this->emailId)
                ->first();

            if (! $email instanceof Email || $email->conversation_key === null) {
                return;
            }

            $refresh->execute($this->workspaceId, $email->conversation_key);

            MergeConversations::dispatchIf($resolver->keysToMerge($email) !== [], $this->workspaceId, $this->emailId);
        });
    }
}
