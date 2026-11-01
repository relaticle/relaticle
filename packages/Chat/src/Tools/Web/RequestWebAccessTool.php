<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Web;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Actions\GrantWebAccess;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Enums\WebSearchPreference;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\PendingActionEnvelope;
use Relaticle\Chat\Support\PromptText;
use Relaticle\Chat\Tools\Concerns\LimitsPlanSteps;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;

final class RequestWebAccessTool implements Tool
{
    use LimitsPlanSteps;
    use WithConversationContext;

    public function description(): string
    {
        return 'Ask the user to let you search the web and read web pages on this turn. '
            .'Call it only when answering needs the web (a URL, a website, or something online): once, before any web lookup and before any write for that request, with the search you plan, then stop. The user allows or declines it on a card. '
            .'Never call it for a request the workspace data can answer or for a write that needs nothing from the web.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The web search you plan to run, e.g. "filaforms.com company overview". At most 200 characters.')->required(),
            'reason' => $schema->string()->description('One short sentence telling the user why the web helps with their request. At most 200 characters.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();
        $workspace = $user->currentWorkspace;

        if ($workspace?->ai_web_search_enabled !== true || WebSearchPreference::of($user) === WebSearchPreference::Deny) {
            return $this->error(__('Web access is unavailable for this user. Answer from the workspace data and say you could not use the web.'));
        }

        $planLimitError = $this->planStepLimitError();

        if ($planLimitError !== null) {
            return $this->error($planLimitError);
        }

        $query = PromptText::sanitize((string) $request->string('query'), 200);
        $reason = PromptText::sanitize((string) $request->string('reason'), 200);

        if ($query === '') {
            return $this->error(__('Give the web search you plan to run as the query.'));
        }

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: GrantWebAccess::class,
            operation: PendingActionOperation::Create,
            entityType: ProposalEntity::WebAccess,
            actionData: ['query' => $query],
            displayData: [
                'title' => __('Search web'),
                'summary' => $query,
                'reason' => $reason,
            ],
            turnId: $this->resolveTurnId(),
        );

        return (string) json_encode(
            PendingActionEnvelope::for($pending, class_basename(GrantWebAccess::class), ['query' => $query]),
            JSON_UNESCAPED_SLASHES,
        );
    }

    private function error(string $message): string
    {
        return (string) json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    }
}
