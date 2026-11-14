<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;

#[Queue('emails-sync')]
#[Tries(3)]
final class RefreshConversationSummaries implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $conversationKeys
     * @param  array<string, list<string>>  $previousAudiences
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly array $conversationKeys,
        public readonly array $previousAudiences = [],
    ) {}

    public function handle(CurrentWorkspace $currentWorkspace, RefreshConversationSummary $refresh): void
    {
        $currentWorkspace->within($this->workspaceId, function () use ($refresh): void {
            foreach ($this->conversationKeys as $conversationKey) {
                $refresh->execute($this->workspaceId, $conversationKey, alsoNotify: $this->previousAudiences[$conversationKey] ?? []);
            }
        });
    }
}
