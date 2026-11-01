<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\WebAccess;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Tools\Web\RequestWebAccessTool;

mutates(WebAccess::class, CrmAssistant::class, RequestWebAccessTool::class);

beforeEach(function (): void {
    config()->set('chat.self_hosted.url', 'http://127.0.0.1:11434/v1');
    config()->set('chat.self_hosted.models', 'qwen3');
    app()->forgetInstance(ModelRegistry::class);
});

/** @param array{provider: string, model: string, id: string, source: string} $resolved */
function agentForWebTurn(User $user, array $resolved, int $failoverDepth = 0): CrmAssistant
{
    DB::table('agent_conversations')->insert([
        'id' => 'conv-web',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => 'Web conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CrmAssistant::fake(['ok']);

    new ProcessChatMessage(
        user: $user,
        workspace: $user->currentWorkspace,
        message: 'Research filaforms.com',
        conversationId: 'conv-web',
        resolved: $resolved,
        failoverDepth: $failoverDepth,
    )->handle(resolve(CreditService::class));

    $agent = null;

    CrmAssistant::assertPrompted(function ($prompt) use (&$agent): bool {
        $agent = $prompt->agent;

        return true;
    });

    return $agent;
}

/** @return list<class-string> */
function webToolClasses(CrmAssistant $agent): array
{
    return array_values(array_filter(
        array_map(static fn (Tool|ProviderTool $tool): string => $tool::class, $agent->tools()),
        static fn (string $class): bool => in_array($class, [WebSearch::class, WebFetch::class, RequestWebAccessTool::class], true),
    ));
}

it('resolves web access per turn and gives the assistant only the matching tools', function (?string $preference, bool $workspaceEnabled, string $provider, string $modelId, array $expectedTools, string $expectedLine): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $user->update(['ai_preferences' => $preference === null ? null : ['web_search' => $preference]]);
    $user->currentWorkspace->update(['ai_web_search_enabled' => $workspaceEnabled]);

    $agent = agentForWebTurn($user->fresh(), ['provider' => $provider, 'model' => $modelId, 'id' => $modelId, 'source' => 'explicit']);

    $classes = array_map(static fn (Tool|ProviderTool $tool): string => $tool::class, $agent->tools());

    expect(webToolClasses($agent))->toBe($expectedTools)
        ->and(array_slice($classes, count($classes) - count($expectedTools)))->toBe($expectedTools)
        ->and($agent->instructions())->toContain($expectedLine);
})->with([
    'self-hosted model with allow' => ['allow', true, 'selfhosted', 'selfhosted:qwen3', [], 'Web: you cannot open web pages or search the web on this turn.'],
    'workspace switch off' => ['allow', false, 'anthropic', 'claude-sonnet-5', [], 'Web: you cannot open web pages or search the web on this turn.'],
    'deny' => ['deny', true, 'anthropic', 'claude-sonnet-5', [], 'Web: you cannot open web pages or search the web on this turn.'],
    'allow' => ['allow', true, 'anthropic', 'claude-sonnet-5', [WebSearch::class, WebFetch::class], 'Web: you can search the web and read pages on this turn, up to 5 searches.'],
    'allow on openai' => ['allow', true, 'openai', 'gpt-5.5', [WebSearch::class, WebFetch::class], 'Web: you can search the web and read pages on this turn, up to 5 searches.'],
    'default ask' => [null, true, 'anthropic', 'claude-sonnet-5', [RequestWebAccessTool::class], 'Web: before any web lookup and before any write, call RequestWebAccessTool once with the search you plan, then stop.'],
]);

it('caps web searches and page reads at five per turn', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $user->update(['ai_preferences' => ['web_search' => 'allow']]);

    $agent = agentForWebTurn($user->fresh(), ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit']);

    $webTools = array_values(array_filter($agent->tools(), static fn (Tool|ProviderTool $tool): bool => $tool instanceof ProviderTool));

    expect($webTools[0])->toBeInstanceOf(WebSearch::class)
        ->and($webTools[0]->maxSearches)->toBe(5)
        ->and($webTools[1])->toBeInstanceOf(WebFetch::class)
        ->and($webTools[1]->maxSearches)->toBe(5);
});

it('drops web tools when failover lands on a model without web', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $user->update(['ai_preferences' => ['web_search' => 'allow']]);

    $agent = agentForWebTurn(
        $user->fresh(),
        ['provider' => 'selfhosted', 'model' => 'qwen3', 'id' => 'selfhosted:qwen3', 'source' => 'auto'],
        failoverDepth: 1,
    );

    expect(webToolClasses($agent))->toBe([])
        ->and($agent->instructions())->toContain('Web: you cannot open web pages or search the web on this turn.');
});
