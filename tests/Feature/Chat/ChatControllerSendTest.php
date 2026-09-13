<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Tests\Helpers\ChatDocument;

mutates(ChatController::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $this->workspace->getKey()], [
        'workspace_id' => $this->workspace->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

it('returns 404 when the supplied conversation_id does not exist', function (): void {
    $clientId = '019dded5-aaaa-7bbb-8ccc-444400000000';

    $this->postJson(route('chat.send'), [
        'document' => ChatDocument::fromText('hi'),
        'conversation_id' => $clientId,
    ])->assertStatus(404);

    expect(DB::table('agent_conversations')->where('id', $clientId)->exists())->toBeFalse();
});

it('rejects a client-supplied conversation_id that already belongs to another user', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $sharedId = '019dded5-bbbb-7bbb-8ccc-555500000000';

    DB::table('agent_conversations')->insert([
        'id' => $sharedId,
        'participant_type' => 'user',
        'participant_id' => $owner->getKey(),
        'workspace_id' => $owner->currentWorkspace->getKey(),
        'title' => 'private',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson(route('chat.send'), [
        'document' => ChatDocument::fromText('hi'),
        'conversation_id' => $sharedId,
    ]);

    $response->assertStatus(403);
});

it('rejects a client-supplied conversation_id pinned to a different workspace', function (): void {
    $otherWorkspace = Workspace::factory()->create();
    $crossWorkspaceId = '019dded5-cccc-7bbb-8ccc-666600000000';

    DB::table('agent_conversations')->insert([
        'id' => $crossWorkspaceId,
        'participant_type' => 'user',
        'participant_id' => $this->user->getKey(),
        'workspace_id' => $otherWorkspace->getKey(),
        'title' => 'cross-workspace',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson(route('chat.send'), [
        'document' => ChatDocument::fromText('hi'),
        'conversation_id' => $crossWorkspaceId,
    ]);

    $response->assertStatus(403);
});

it('rejects malformed conversation_id', function (): void {
    $response = $this->postJson(route('chat.send'), [
        'document' => ChatDocument::fromText('hi'),
        'conversation_id' => 'not-a-uuid',
    ]);

    $response->assertStatus(422);
});

it('returns 422 when conversation_id is missing', function (): void {
    $response = $this->postJson(route('chat.send'), [
        'document' => ChatDocument::fromText('hi'),
    ]);

    $response->assertStatus(422);
});

it('accepts a valid mentions array', function (): void {
    Queue::fake();
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme Corp']);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson(route('chat.send'), [
        'conversation_id' => $conversationId,
        'document' => ChatDocument::fromText('Tell me about ', [
            ['type' => 'company', 'id' => $company->id, 'label' => 'Acme Corp'],
        ]),
    ]);

    $response->assertOk();
    Queue::assertPushed(ProcessChatMessage::class, function (ProcessChatMessage $job) use ($company): bool {
        return count($job->mentions) === 1
            && $job->mentions[0]['id'] === $company->id
            && $job->mentions[0]['type'] === 'company'
            && $job->mentions[0]['label'] === 'Acme Corp';
    });
});

it('silently drops mentions whose records do not belong to the current workspace', function (): void {
    Queue::fake();
    $otherWorkspace = Workspace::factory()->create();
    $foreignCompany = Company::factory()->for($otherWorkspace)->create(['name' => 'Foreign Co']);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson(route('chat.send'), [
        'conversation_id' => $conversationId,
        'document' => ChatDocument::fromText('Tell me about that company ', [
            ['type' => 'company', 'id' => $foreignCompany->id, 'label' => 'Foreign Co'],
        ]),
    ]);

    $response->assertOk();
    Queue::assertPushed(ProcessChatMessage::class, function (ProcessChatMessage $job): bool {
        return $job->mentions === [];
    });
});
