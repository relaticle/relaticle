<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;

it('passes disable_parallel_tool_use to Anthropic via tool_choice', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    Http::fake([
        'https://api.anthropic.com/*' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
        ]),
    ]);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-0000-7000-8000-000000000001',
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $agent = resolve(CrmAssistant::class);
    $agent->withConversationId('019df800-0000-7000-8000-000000000001');

    $agent->prompt('hi', provider: 'anthropic', model: 'claude-sonnet-4-6');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return ($body['tool_choice']['disable_parallel_tool_use'] ?? false) === true;
    });
});

it('returns provider-specific options for parallel-tool-call control', function (): void {
    $agent = resolve(CrmAssistant::class);

    expect($agent->providerOptions('openai'))->toBe(['parallel_tool_calls' => false]);
    expect($agent->providerOptions('gemini'))->toBe([]);
    expect($agent->providerOptions(Lab::Anthropic))->toHaveKey('tool_choice');
});

it('caches both the static prefix and the growing transcript on Anthropic', function (): void {
    $options = resolve(CrmAssistant::class)->providerOptions(Lab::Anthropic);

    expect($options['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($options['cache_control'])->toBe(['type' => 'ephemeral']);
});

it('sends both cache breakpoints in the Anthropic request body', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    Http::fake([
        'https://api.anthropic.com/*' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
        ]),
    ]);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-0000-7000-8000-000000000002',
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $agent = resolve(CrmAssistant::class);
    $agent->withConversationId('019df800-0000-7000-8000-000000000002');
    $agent->prompt('hi', provider: 'anthropic', model: 'claude-sonnet-4-6');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return ($body['system'][0]['cache_control']['type'] ?? null) === 'ephemeral'
            && ($body['cache_control']['type'] ?? null) === 'ephemeral';
    });
});

it('omits every cache breakpoint when prompt caching is disabled', function (): void {
    config()->set('chat.anthropic_prompt_caching', false);

    $options = resolve(CrmAssistant::class)->providerOptions(Lab::Anthropic);

    expect($options)->not->toHaveKey('cache_control')
        ->and($options)->not->toHaveKey('system')
        ->and($options)->toHaveKey('tool_choice');
});

it('write tool result includes agent_should_stop=true in meta', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    Auth::guard('web')->setUser($user);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-0000-7000-8000-000000000010',
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var CreateCompanyTool $tool */
    $tool = resolve(CreateCompanyTool::class);
    $tool->setConversationId('019df800-0000-7000-8000-000000000010');

    $resultJson = $tool->handle(new Request(['records' => [['name' => 'Acme']]]));
    $result = json_decode($resultJson, true);

    expect($result)->toHaveKey('meta')
        ->and($result['meta']['agent_should_stop'] ?? null)->toBeTrue();
});

it('system prompt chains the writes of one request and stops after the last', function (): void {
    $agent = resolve(CrmAssistant::class);

    $instructions = $agent->instructions();

    expect($instructions)
        ->toContain('is ONE turn, not several')
        ->toContain('$ref:<that pending_action_id>')
        ->toContain('After the LAST write of the request, STOP your turn')
        ->toContain('never ask the user to approve one record at a time')
        ->not->toContain('[approval]')
        ->not->toContain('automatically be prompted to continue')
        // The old contract was one write per turn, with the user typing "continue"
        // between steps. Plans replaced it, and the prompt must not reinstate it.
        ->not->toContain('continue from <resolved_actions> when they say')
        ->toContain('never ask them to say "continue"');
});

it('bounds how many steps one turn may chain into a single card', function (): void {
    $agent = resolve(CrmAssistant::class);

    // The cap is enforced in the tool layer (LimitsPlanSteps), never as prompt text:
    // a limit the model only knows about is a limit it can talk itself out of.
    expect($agent->instructions())->not->toContain('max_plan_steps')
        ->and(config('chat.max_plan_steps'))->toBeGreaterThan(1);
});
