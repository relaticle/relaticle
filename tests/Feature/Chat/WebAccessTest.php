<?php

declare(strict_types=1);

use App\Models\Note;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\Request as ToolRequest;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\Chat\Actions\GrantWebAccess;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Enums\WebAccess;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Tools\Note\CreateNoteTool;
use Relaticle\Chat\Tools\Web\RequestWebAccessTool;
use Tests\Helpers\AnthropicSse;

mutates(WebAccess::class, CrmAssistant::class, RequestWebAccessTool::class, GrantWebAccess::class, ProposalCard::class, ProposalEntity::class);

beforeEach(function (): void {
    config()->set('chat.self_hosted.url', 'http://127.0.0.1:11434/v1');
    config()->set('chat.self_hosted.models', 'qwen3');
    app()->forgetInstance(ModelRegistry::class);
});

/** @param array{provider: string, model: string, id: string, source: string} $resolved */
function agentForWebTurn(User $user, array $resolved): CrmAssistant
{
    seedWebConversation($user);

    CrmAssistant::fake(['ok']);

    new ProcessChatMessage(
        user: $user,
        workspace: $user->currentWorkspace,
        message: 'Research filaforms.com',
        conversationId: 'conv-web',
        resolved: $resolved,
    )->handle(resolve(CreditService::class));

    return promptedAgent();
}

function seedWebConversation(User $user): void
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
}

function promptedAgent(): CrmAssistant
{
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
    'cloud provider without web with allow' => ['allow', true, 'gemini', 'gemini-3-flash', [], 'Web: you cannot open web pages or search the web on this turn.'],
    'default ask' => [null, true, 'anthropic', 'claude-sonnet-5', [RequestWebAccessTool::class], 'Web: only when answering needs the web (a URL, a website, or something online), call RequestWebAccessTool once'],
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
    config()->set('ai.providers.selfhosted.url', 'http://127.0.0.1:11434/v1');
    config()->set('ai.providers.openai.key', '');

    $user = User::factory()->withPersonalWorkspace()->create();
    $user->update(['ai_preferences' => ['web_search' => 'allow']]);
    $user = $user->fresh();
    seedWebConversation($user);

    AnthropicSse::fake(AnthropicSse::TERMINAL_ERROR);
    Queue::fake();

    new ProcessChatMessage(
        user: $user,
        workspace: $user->currentWorkspace,
        message: 'Research filaforms.com',
        conversationId: 'conv-web',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: 'turn-web',
    )->handle(resolve(CreditService::class));

    Http::assertSent(fn (HttpRequest $request): bool => collect($request->data()['tools'] ?? [])
        ->contains(fn (array $tool): bool => str_starts_with((string) ($tool['type'] ?? ''), 'web_search')));

    $failover = Queue::pushed(ProcessChatMessage::class)->sole();

    CrmAssistant::fake(['ok']);
    $failover->handle(resolve(CreditService::class));

    $agent = promptedAgent();

    expect($failover->failoverDepth)->toBe(1)
        ->and($agent->modelLabel)->toBe('qwen3')
        ->and(webToolClasses($agent))->toBe([])
        ->and($agent->instructions())->toContain('Web: you cannot open web pages or search the web on this turn.');
});

function webAccessUser(array $preferences = []): User
{
    $user = User::factory()->withPersonalWorkspace()->create();
    $user->update(['ai_preferences' => $preferences === [] ? null : $preferences]);
    $user = $user->fresh();

    seedWebConversation($user);
    AiCreditBalance::query()->updateOrCreate(
        ['workspace_id' => $user->currentWorkspace->getKey()],
        ['credits_remaining' => 50, 'credits_used' => 0, 'purchased_credits' => 0],
    );

    test()->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    return $user;
}

function requestWebAccess(User $user, string $turnId, string $query = 'filaforms.com company overview'): PendingAction
{
    auth()->setUser($user);

    $tool = resolve(RequestWebAccessTool::class);
    $tool->setConversationId('conv-web');
    $tool->setTurnId($turnId);
    $tool->handle(new ToolRequest(['query' => $query, 'reason' => 'Your workspace has no record of Filaforms.']));

    return PendingAction::query()->where('entity_type', ProposalEntity::WebAccess)->where('turn_id', $turnId)->sole();
}

function openWebAccessDock(PendingAction $anchor): Testable
{
    return Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: (string) $anchor->getKey(), context: 'conversation');
}

function runQueuedResume(): CrmAssistant
{
    $resume = Queue::pushed(ProcessChatMessage::class)->sole();

    expect($resume->origin)->toBe(MessageOrigin::Resume);

    CrmAssistant::fake(['ok']);
    $resume->handle(resolve(CreditService::class));

    return promptedAgent();
}

function typedWebTurn(User $user): CrmAssistant
{
    CrmAssistant::fake(['ok']);

    new ProcessChatMessage(
        user: $user,
        workspace: $user->currentWorkspace,
        message: 'Now research acme.com',
        conversationId: 'conv-web',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: (string) str()->ulid(),
    )->handle(resolve(CreditService::class));

    $agent = null;

    CrmAssistant::assertPrompted(function ($prompt) use (&$agent): bool {
        $agent = $prompt->agent;

        return $prompt->prompt === 'Now research acme.com';
    });

    return $agent;
}

it('asks before searching', function (): void {
    $user = webAccessUser();
    Queue::fake();

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(AnthropicSse::toolUseStepWithInput('RequestWebAccessTool', ['query' => 'filaforms.com company overview', 'reason' => 'Filaforms is not in your workspace.']), 200, ['Content-Type' => 'text/event-stream'])
            ->push(AnthropicSse::reply('Allow the search below.', 'claude-sonnet-5'), 200, ['Content-Type' => 'text/event-stream']),
    ]);

    new ProcessChatMessage(
        user: $user,
        workspace: $user->currentWorkspace,
        message: 'Research filaforms.com',
        conversationId: 'conv-web',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: (string) str()->ulid(),
    )->handle(resolve(CreditService::class));

    $request = PendingAction::query()->where('conversation_id', 'conv-web')->sole();

    expect($request->entity_type)->toBe(ProposalEntity::WebAccess)
        ->and($request->status)->toBe(PendingActionStatus::Pending)
        ->and($request->action_class)->toBe(GrantWebAccess::class)
        ->and($request->action_data)->toBe(['query' => 'filaforms.com company overview'])
        ->and($request->display_data['reason'])->toBe('Filaforms is not in your workspace.');
});

it('tells the model web access is unavailable when the user turned it off', function (): void {
    $user = webAccessUser(['web_search' => 'deny']);

    $tool = resolve(RequestWebAccessTool::class);
    $tool->setConversationId('conv-web');
    $tool->setTurnId((string) str()->ulid());
    $result = json_decode($tool->handle(new ToolRequest(['query' => 'filaforms.com', 'reason' => 'Research'])), true);

    expect($result['error'])->toContain('Web access is unavailable')
        ->and(PendingAction::query()->count())->toBe(0);
});

it('grants web access to the resumed turn only', function (): void {
    $user = webAccessUser();
    Queue::fake();
    $request = requestWebAccess($user, (string) str()->ulid());

    openWebAccessDock($request)
        ->call('approveStep', (string) $request->getKey())
        ->assertHasNoErrors();

    $resumed = runQueuedResume();

    expect($request->fresh()->status)->toBe(PendingActionStatus::Approved)
        ->and(webToolClasses($resumed))->toBe([WebSearch::class, WebFetch::class])
        ->and(webToolClasses(typedWebTurn($user)))->toBe([RequestWebAccessTool::class]);
});

it('remembers Always allow', function (): void {
    $user = webAccessUser(['default_model' => 'claude-sonnet-5']);
    Queue::fake();
    $request = requestWebAccess($user, (string) str()->ulid());

    openWebAccessDock($request)
        ->call('approveAlways', (string) $request->getKey())
        ->assertHasNoErrors();

    expect($user->fresh()->ai_preferences)->toEqual(['default_model' => 'claude-sonnet-5', 'web_search' => 'allow'])
        ->and($request->fresh()->status)->toBe(PendingActionStatus::Approved);

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->origin === MessageOrigin::Resume);
});

it('answers without the web after Deny', function (): void {
    $user = webAccessUser();
    Queue::fake();
    $request = requestWebAccess($user, (string) str()->ulid());

    openWebAccessDock($request)
        ->call('rejectStep', (string) $request->getKey())
        ->assertHasNoErrors();

    $resumed = runQueuedResume();

    expect($request->fresh()->status)->toBe(PendingActionStatus::Rejected)
        ->and(webToolClasses($resumed))->toBe([RequestWebAccessTool::class])
        ->and($resumed->dynamicInstructions())->toContain('REJECTED (nothing was written): search the web for "filaforms.com company overview"');
});

it('refuses the grant when the workspace turned web search off between request and approval', function (): void {
    $user = webAccessUser();
    Queue::fake();
    $request = requestWebAccess($user, (string) str()->ulid());

    $user->currentWorkspace->update(['ai_web_search_enabled' => false]);

    openWebAccessDock($request)
        ->call('approveAlways', (string) $request->getKey())
        ->assertHasErrors('resolve')
        ->assertDispatched('proposal:resolve-failed', fn (string $event, array $params): bool => $params['message'] === 'Your workspace owner turned web search off.');

    expect($request->fresh()->status)->toBe(PendingActionStatus::Pending)
        ->and($user->fresh()->ai_preferences)->toBeNull();

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('never approves web access through approve all or the plan shortcut', function (string $decide): void {
    $user = webAccessUser();
    Queue::fake();
    $turnId = (string) str()->ulid();

    $note = resolve(CreateNoteTool::class);
    $note->setConversationId('conv-web');
    $note->setTurnId($turnId);
    $note->handle(new ToolRequest(['records' => [['title' => 'Filaforms brief']]]));
    $request = requestWebAccess($user, $turnId);
    $noteStep = PendingAction::query()->where('entity_type', ProposalEntity::Note)->sole();

    $dock = openWebAccessDock($noteStep);
    $decide === 'approve all'
        ? $dock->call('approveAll')
        : $dock->dispatch('proposal:create-current', context: 'conversation');

    $dock->assertHasNoErrors()->assertSet('pendingActionId', (string) $request->getKey());

    expect($noteStep->fresh()->status)->toBe(PendingActionStatus::Approved)
        ->and(Note::query()->where('title', 'Filaforms brief')->exists())->toBeTrue()
        ->and($request->fresh()->status)->toBe(PendingActionStatus::Pending);

    Queue::assertNotPushed(ProcessChatMessage::class);
})->with(['approve all', 'plan shortcut']);

it('names the search price only where the workspace meters credits', function (bool $metered): void {
    if ($metered) {
        withHostedBilling();
    }

    $user = webAccessUser();
    Queue::fake();
    $request = requestWebAccess($user, (string) str()->ulid());

    $dock = openWebAccessDock($request)->assertSee('Rela wants to search the web for this.');

    $metered
        ? $dock->assertSee('Each search uses 2 credits.')
        : $dock->assertDontSee('Each search uses');
})->with(['metered' => true, 'unmetered' => false]);

it('ignores the shortcut on a lone web access request', function (): void {
    $user = webAccessUser();
    Queue::fake();
    $request = requestWebAccess($user, (string) str()->ulid());

    openWebAccessDock($request)->dispatch('proposal:create-current', context: 'conversation');

    expect($request->fresh()->status)->toBe(PendingActionStatus::Pending);
});
