<?php

declare(strict_types=1);

namespace Relaticle\Chat\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use Relaticle\Chat\Actions\SummarizeEmailConversation;
use Relaticle\EmailIntegration\Models\AiSummary;
use Throwable;

#[Connection('redis-chat')]
#[Queue('chat')]
#[Tries(1)]
#[Timeout(90)]
#[UniqueFor(120)]
final class SummarizeEmailConversationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $userId, public readonly string $workspaceId, public readonly string $conversationKey, public readonly bool $regenerate = false) {}

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->workspaceId}:{$this->conversationKey}";
    }

    public function handle(SummarizeEmailConversation $action): void
    {
        try {
            $viewer = User::query()->findOrFail($this->userId);
            $viewer->current_workspace_id = $this->workspaceId;
            $viewer->unsetRelation('currentWorkspace');
            $summary = $action->execute($viewer, $this->workspaceId, $this->conversationKey, $this->regenerate);
            Cache::put('email-summary:status:'.$this->uniqueId(), $summary instanceof AiSummary ? 'done' : 'no_credits', 120);
        } catch (Throwable $exception) {
            Cache::put('email-summary:status:'.$this->uniqueId(), 'failed', 120);
            report($exception);
        }
    }
}
