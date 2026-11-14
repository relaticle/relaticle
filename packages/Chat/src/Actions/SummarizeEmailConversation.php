<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\CreditService;
use Relaticle\EmailIntegration\Agents\ThreadSummarizer;
use Relaticle\EmailIntegration\Models\AiSummary;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;
use Throwable;

final readonly class SummarizeEmailConversation
{
    public function __construct(private CurrentWorkspace $currentWorkspace, private CreditService $credits, private EmailThreadSummaryService $summaries) {}

    public function execute(User $viewer, string $workspaceId, string $conversationKey, bool $regenerate = false): ?AiSummary
    {
        abort_unless($viewer->current_workspace_id === $workspaceId && $viewer->belongsToWorkspaceId($workspaceId), 403);

        return $this->currentWorkspace->within($workspaceId, fn (): ?AiSummary => Cache::lock("email-summary:lock:{$viewer->getKey()}:{$workspaceId}:{$conversationKey}", 90)
            ->block(2, fn (): ?AiSummary => $this->summarize($viewer, $conversationKey, $regenerate)));
    }

    private function summarize(User $viewer, string $key, bool $regenerate): ?AiSummary
    {
        abort_if(resolve(ConversationsQuery::class)->messages($viewer, $key)->isEmpty(), 404);
        $conversation = EmailConversation::query()->where('workspace_id', $viewer->current_workspace_id)->where('conversation_key', $key)->firstOrFail();
        $prompt = $this->summaries->prompt($viewer, $key);
        $hash = hash('sha256', $prompt);
        $cached = $this->summaries->cached($viewer, $conversation, $hash);
        if (! $regenerate && $cached instanceof AiSummary) {
            return $cached;
        }
        $token = 'summary-'.Str::ulid();
        if (! $this->credits->reserveCredit($viewer->currentWorkspace, "reserve-{$token}", userId: $viewer->getKey())) {
            return null;
        }
        try {
            $resolved = resolve(AiModelResolver::class)->resolve($viewer);
            $response = (new ThreadSummarizer)->prompt($prompt, provider: $resolved['provider'], model: $resolved['model']);
            abort_unless(hash('sha256', $this->summaries->prompt($viewer, $key)) === $hash, 409);

            return DB::transaction(function () use ($viewer, $conversation, $hash, $resolved, $response, $token): AiSummary {
                $summary = AiSummary::query()->updateOrCreate([
                    'workspace_id' => $conversation->workspace_id, 'user_id' => $viewer->getKey(),
                    'summarizable_type' => $conversation->getMorphClass(), 'summarizable_id' => $conversation->getKey(),
                ], [
                    'summary' => $response->text, 'input_hash' => $hash, 'model_used' => $resolved['model'] ?? '',
                    'prompt_tokens' => $response->usage->inputTokens, 'completion_tokens' => $response->usage->outputTokens,
                ]);
                $this->credits->settleReservation($viewer->currentWorkspace, $viewer, AiCreditType::Summary, $resolved['model'] ?? '',
                    $response->usage->inputTokens, $response->usage->outputTokens, resolutionKey: "resolve-{$token}");

                return $summary;
            });
        } catch (Throwable $exception) {
            $this->credits->refundReservation($viewer->currentWorkspace, resolutionKey: "resolve-{$token}");

            throw $exception;
        }
    }
}
