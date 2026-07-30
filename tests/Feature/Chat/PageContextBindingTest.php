<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Jobs\ProcessChatMessage;

mutates(ProcessChatMessage::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->conversationId = '019df800-5555-7000-8000-000000000001';

    DB::table('agent_conversations')->insert([
        'id' => $this->conversationId,
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Queue::fake();
});

function sendWithPageContext(string $conversationId, ?array $pageContext): TestResponse
{
    return test()->postJson('/chat/'.$conversationId, array_filter([
        'document' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'summarize this']]]],
        ],
        'conversation_id' => $conversationId,
        'page_context' => $pageContext,
    ], static fn (mixed $value): bool => $value !== null));
}

it('binds the record the user is viewing to the turn', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $this->actingAs($this->user);

    sendWithPageContext($this->conversationId, [
        'type' => 'company',
        'id' => (string) $acme->getKey(),
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class, function (ProcessChatMessage $job) use ($acme): bool {
        return $job->pageContext !== null
            && $job->pageContext['type'] === 'company'
            && $job->pageContext['id'] === (string) $acme->getKey()
            && $job->pageContext['label'] === 'Acme';
    });
});

it('drops a page context pointing at another team record', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $theirs = Company::factory()->for($otherUser->currentTeam)->create(['name' => 'Theirs']);

    $this->actingAs($this->user);

    sendWithPageContext($this->conversationId, [
        'type' => 'company',
        'id' => (string) $theirs->getKey(),
    ])->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        static fn (ProcessChatMessage $job): bool => $job->pageContext === null,
    );
});

it('carries no page context when none is sent', function (): void {
    $this->actingAs($this->user);

    sendWithPageContext($this->conversationId, null)->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        static fn (ProcessChatMessage $job): bool => $job->pageContext === null,
    );
});

it('drops a page context with an unsupported type', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $this->actingAs($this->user);

    sendWithPageContext($this->conversationId, [
        'type' => 'invoice',
        'id' => (string) $acme->getKey(),
    ])->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        static fn (ProcessChatMessage $job): bool => $job->pageContext === null,
    );
});

it('renders the bound record into the agent dynamic instructions', function (): void {
    $agent = resolve(CrmAssistant::class);

    $agent->withPageContext(['type' => 'company', 'id' => '01JABCDEF', 'label' => 'Acme']);

    expect($agent->dynamicInstructions())
        ->toContain('Acme')
        ->toContain('01JABCDEF')
        ->toContain('untrusted');
});

it('renders nothing when no record is bound', function (): void {
    $agent = resolve(CrmAssistant::class);

    $agent->withPageContext(null);

    expect($agent->dynamicInstructions())->not->toContain('currently viewing');
});
