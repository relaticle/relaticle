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
        'user_id' => (string) $user->getKey(),
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

it('write tool result includes agent_should_stop=true in meta', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    Auth::guard('web')->setUser($user);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-0000-7000-8000-000000000010',
        'user_id' => (string) $user->getKey(),
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

it('system prompt instructs the agent to stop after writes and handle approvals', function (): void {
    $agent = resolve(CrmAssistant::class);

    $instructions = $agent->instructions();

    expect($instructions)
        ->toContain('After ANY write tool call')
        ->toContain('STOP your turn immediately')
        ->toContain('[approval]')
        ->toContain('automatically be prompted to continue');
});
