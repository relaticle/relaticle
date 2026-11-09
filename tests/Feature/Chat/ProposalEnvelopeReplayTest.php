<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolResult as DataToolResult;
use Laravel\Ai\Streaming\Events\ToolResult;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;
use Relaticle\Chat\Support\DisplayBlocks;
use Relaticle\Chat\Support\PendingActionEnvelope;
use Relaticle\Chat\Support\StreamEventBroadcaster;
use Tests\Helpers\ChatDocument;
use Tests\Helpers\ProposalCardFixture;

mutates(DisplayBlocks::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($this->user);
});

function writeEchoEnvelope(User $user, int $bodyBytes = 50_000): array
{
    $action = ProposalCardFixture::proposal($user, ['name' => 'Acme'], ['summary' => 'Create Acme']);

    return PendingActionEnvelope::for($action, 'CreateCompany', ['name' => 'Acme', 'body' => str_repeat('x', $bodyBytes)]);
}

it('strips data and display from a replayed write echo', function (): void {
    $echo = (string) json_encode(writeEchoEnvelope($this->user));

    $replayed = json_decode(DisplayBlocks::strip($echo), true);

    expect(array_keys($replayed))->toBe(['type', 'pending_action_id', 'turn_id', 'action', 'entity_type', 'operation', 'summary', 'meta'])
        ->and($replayed['type'])->toBe('pending_action')
        ->and($replayed['meta'])->toBe(['agent_should_stop' => true]);
});

it('keeps a replayed write echo under 1 KB when data held 50 KB', function (): void {
    $echo = (string) json_encode(writeEchoEnvelope($this->user));

    expect(strlen($echo))->toBeGreaterThan(50_000)
        ->and(strlen(DisplayBlocks::strip($echo)))->toBeLessThan(1_000);
});

it('strips data and display from a decided write echo and keeps the verdict', function (): void {
    $action = ProposalCardFixture::proposal($this->user, ['name' => 'Acme'], ['summary' => 'Create Acme']);
    $action->forceFill(['status' => 'approved', 'result_data' => ['id' => '01ABC']])->save();

    $echo = (string) json_encode(PendingActionEnvelope::for($action->refresh(), 'CreateCompany', ['name' => 'Acme']));
    $replayed = json_decode(DisplayBlocks::strip($echo), true);

    expect($replayed)->not->toHaveKey('data')->not->toHaveKey('display')
        ->and($replayed['status'])->toBe('approved')
        ->and($replayed['outcome'])->toBe(['id' => '01ABC'])
        ->and($replayed['message'])->toContain('approved');
});

it('passes a non json write result through untouched', function (): void {
    expect(DisplayBlocks::strip('Skill [x] does not exist.'))->toBe('Skill [x] does not exist.')
        ->and(DisplayBlocks::strip('Error: "pending_action" could not be saved.'))->toBe('Error: "pending_action" could not be saved.');
});

it('returns a read result without a block byte for byte', function (): void {
    $read = '{"data": [{"id": "a", "url": "\/r\/note\/a", "name": "Café"}], "total": 1}';

    expect(DisplayBlocks::strip($read))->toBe($read);
});

it('returns a read result that merely holds the word pending_action byte for byte', function (): void {
    $read = '{"data": [{"id": "a", "status": "pending_action"}], "total": 1}';

    expect(DisplayBlocks::strip($read))->toBe($read);
});

it('returns a result typed pending_action byte for byte when it carries neither key', function (): void {
    $bare = '{"type": "pending_action", "pending_action_id": "p1", "url": "\/r\/a"}';

    expect(DisplayBlocks::strip($bare))->toBe($bare);
});

it('keeps the record name and changed fields of a single update in the replayed echo', function (): void {
    $action = ProposalCardFixture::proposal(
        $this->user,
        ['_record_id' => '01ABC', '_model_class' => 'company', 'name' => 'Acme', 'domain' => 'acme.test'],
        ['title' => 'Update Company', 'summary' => 'Update Acme'],
    );
    $action->forceFill(['operation' => 'update'])->save();

    $envelope = PendingActionEnvelope::for($action->refresh(), 'UpdateCompany', ['name' => 'Acme']);
    $replayed = json_decode(DisplayBlocks::strip((string) json_encode($envelope)), true);

    expect($replayed['summary'])->toBe(['records' => [['label' => 'Acme', 'fields' => ['name', 'domain']]]]);
});

it('keeps one summary entry per record of a batch create in the replayed echo', function (): void {
    $action = ProposalCardFixture::batchCompany($this->user, ['Acme', 'Globex', 'Initech']);

    $replayed = json_decode(DisplayBlocks::strip((string) json_encode(PendingActionEnvelope::for($action, 'CreateCompany', []))), true);

    expect($replayed['summary']['records'])->toHaveCount(3)
        ->and(array_column($replayed['summary']['records'], 'label'))->toBe(['Acme', 'Globex', 'Initech'])
        ->and($replayed['summary']['records'][0])->toBe(['label' => 'Acme', 'fields' => ['name']]);
});

it('names each custom field a record changes in the replayed echo', function (): void {
    $action = ProposalCardFixture::proposal(
        $this->user,
        ['_record_id' => '01ABC', '_model_class' => 'company', 'name' => 'Acme', 'custom_fields' => ['tier' => 'gold', 'region' => 'EMEA']],
        ['title' => 'Update Company', 'summary' => 'Update Acme'],
    );
    $action->forceFill(['operation' => 'update'])->save();

    $replayed = json_decode(DisplayBlocks::strip((string) json_encode(PendingActionEnvelope::for($action->refresh(), 'UpdateCompany', []))), true);

    expect($replayed['summary']['records'][0])->toBe(['label' => 'Acme', 'fields' => ['name', 'tier', 'region']]);
});

it('keeps the record name and no fields for a delete in the replayed echo', function (): void {
    $action = ProposalCardFixture::proposal(
        $this->user,
        ['_record_ids' => ['01ABC']],
        ['title' => 'Delete Company', 'summary' => 'Delete Acme', 'fields' => [['label' => 'Name', 'value' => 'Acme']]],
    );
    $action->forceFill(['operation' => 'delete'])->save();

    $replayed = json_decode(DisplayBlocks::strip((string) json_encode(PendingActionEnvelope::for($action->refresh(), 'DeleteCompany', ['ids' => ['01ABC']]))), true);

    expect($replayed['summary']['records'])->toBe([['label' => 'Acme']]);
});

it('keeps display and drops data in the stream payload while the stored envelope carries both', function (): void {
    $envelope = writeEchoEnvelope($this->user, 100);

    expect($envelope)->toHaveKeys(['display', 'data']);

    $event = new ToolResult(
        id: 'evt-1',
        toolResult: new DataToolResult(id: 'tool-1', name: 'CreateCompanyTool', arguments: [], result: (string) json_encode($envelope)),
        successful: true,
        error: null,
        timestamp: time(),
    );

    $payload = json_decode((string) StreamEventBroadcaster::payloadFor($event)['with']['result'], true);

    expect($payload)->toHaveKey('display')->not->toHaveKey('data')
        ->and($payload['display'])->toBe(['summary' => 'Create Acme']);
});

it('replays the stripped echo from the conversation store while the stored row keeps it', function (): void {
    $envelope = (string) json_encode(writeEchoEnvelope($this->user));
    $conversationId = (string) Str::uuid7();
    $userId = (string) $this->user->getKey();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => $userId,
        'workspace_id' => $this->user->currentWorkspace->getKey(),
        'title' => 'Echo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $base = [
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => $userId,
        'agent' => CrmAssistant::class,
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'usage' => '{}',
        'meta' => '{}',
    ];

    DB::table('agent_conversation_messages')->insert([
        ...$base,
        'id' => (string) Str::ulid(),
        'role' => 'user',
        'content' => 'create Acme',
        'steps' => '[]',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        ...$base,
        'id' => (string) Str::ulid(),
        'role' => 'assistant',
        'content' => 'Proposed.',
        'steps' => storedToolSteps([['id' => 'toolu_echo_1', 'name' => 'CreateCompanyTool', 'result' => $envelope]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $replayed = resolve(SupersededAwareConversationStore::class)
        ->getLatestConversationMessages($conversationId, 20)
        ->filter(fn (object $message): bool => $message instanceof ToolResultMessage)
        ->flatMap(fn (ToolResultMessage $message): Collection => $message->toolResults)
        ->map(fn (object $result): array => json_decode((string) $result->result, true));

    $stored = json_decode((string) DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->value('steps'), true);

    $storedResult = json_decode((string) $stored[0]['tool_calls'][0]['result'], true);

    expect($replayed)->toHaveCount(1)
        ->and($replayed->first())->not->toHaveKey('data')->not->toHaveKey('display')
        ->and($replayed->first()['pending_action_id'])->toBe($storedResult['pending_action_id'])
        ->and($storedResult)->toHaveKey('data')->toHaveKey('display');
});
