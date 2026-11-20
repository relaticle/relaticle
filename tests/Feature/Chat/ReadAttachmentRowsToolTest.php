<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Support\AttachedRows;
use Relaticle\Chat\Support\ChatAttachment;
use Relaticle\Chat\Tools\ReadAttachmentRowsTool;

mutates(ReadAttachmentRowsTool::class, AttachedRows::class, ChatAttachment::class);

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($this->user);
    $this->conversationId = readRowsConversation($this->user);
});

function readRowsConversation(User $user): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->current_workspace_id,
        'title' => 'rows',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @param  list<string>  $lines
 */
function readRowsUpload(string $conversationId, array $lines, string $name = 'companies.csv', bool $send = true): string
{
    $id = (string) test()->postJson(route('chat.attachments.store'), [
        'file' => UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n"),
        'conversation_id' => $conversationId,
    ])->assertOk()->json('id');

    if ($send) {
        test()->postJson(route('chat.send', ['conversation' => $conversationId]), [
            'document' => ['type' => 'doc', 'content' => []],
            'attachment_id' => $id,
        ])->assertOk();
    }

    return $id;
}

/**
 * @return list<string>
 */
function readRowsCompanies(int $count): array
{
    return ['Name,Domain', ...array_map(static fn (int $i): string => "Company {$i},company{$i}.example", range(1, $count))];
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function readRows(?string $conversationId, array $arguments = []): array
{
    $tool = (new ReadAttachmentRowsTool)->setConversationId($conversationId);

    return json_decode($tool->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);
}

it('reads a large file sent alone page by page until the last row', function (): void {
    readRowsUpload($this->conversationId, readRowsCompanies(62));

    $first = readRows($this->conversationId);
    $second = readRows($this->conversationId, ['offset' => $first['next_offset']]);
    $last = readRows($this->conversationId, ['offset' => $second['next_offset']]);

    expect($first['header'])->toBe('Name,Domain')
        ->and($first['total_rows'])->toBe(62)
        ->and($first['rows'])->toHaveCount(25)
        ->and($first['rows'][0])->toBe('Company 1,company1.example')
        ->and($first['next_offset'])->toBe(25)
        ->and($second['rows'][0])->toBe('Company 26,company26.example')
        ->and($second['next_offset'])->toBe(50)
        ->and($last['rows'])->toHaveCount(12)
        ->and($last['rows'][11])->toBe('Company 62,company62.example')
        ->and($last['next_offset'])->toBeNull();
});

it('counts offsets over the non-blank rows a preview shows', function (): void {
    $lines = readRowsCompanies(30);
    array_splice($lines, 11, 0, [',', '']);

    readRowsUpload($this->conversationId, $lines);

    $second = readRows($this->conversationId, ['offset' => 25]);

    expect($second['rows'])->toBe(array_map(static fn (int $i): string => "Company {$i},company{$i}.example", range(26, 30)))
        ->and($second['next_offset'])->toBeNull();
});

it('cuts a page of wide rows at the byte limit and continues from the rows it returned', function (): void {
    $wide = str_repeat('x', AttachedRows::CELL_LIMIT);
    $header = implode(',', array_map(static fn (int $i): string => "col_{$i}", range(1, 40)));
    $row = implode(',', array_fill(0, 40, $wide));

    readRowsUpload($this->conversationId, [$header, ...array_fill(0, 30, $row)], 'wide.csv');

    $first = readRows($this->conversationId);
    $next = readRows($this->conversationId, ['offset' => $first['next_offset']]);
    $bytes = strlen($first['header']) + array_sum(array_map(static fn (string $line): int => strlen($line) + 1, $first['rows']));

    expect(count($first['rows']))->toBeGreaterThan(0)->toBeLessThan(25)
        ->and($bytes)->toBeLessThanOrEqual(AttachedRows::INLINE_BYTE_LIMIT)
        ->and($first['next_offset'])->toBe(count($first['rows']))
        ->and($next['rows'])->not->toBeEmpty();
});

it('asks which file to read when the conversation holds two, then reads the one named', function (): void {
    readRowsUpload($this->conversationId, readRowsCompanies(30), 'first.csv');
    $secondId = readRowsUpload($this->conversationId, ['Name', 'Only row'], 'second.csv');

    $ambiguous = readRows($this->conversationId);
    $named = readRows($this->conversationId, ['attachment_id' => $secondId]);

    expect($ambiguous['error'])->toBe('Name the file with attachment_id.')
        ->and(array_column($ambiguous['files'], 'name'))->toBe(['first.csv', 'second.csv'])
        ->and($named['name'])->toBe('second.csv')
        ->and($named['rows'])->toBe(['Only row']);
});

it('reads no rows the user did not send in this conversation', function (): void {
    readRowsUpload($this->conversationId, readRowsCompanies(30), send: false);

    $otherConversation = readRowsConversation($this->user);
    readRowsUpload($otherConversation, readRowsCompanies(30));

    $stranger = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($stranger);
    $strangerConversation = readRowsConversation($stranger);
    $strangerFile = readRowsUpload($strangerConversation, readRowsCompanies(30));
    $this->actingAs($this->user);

    expect(readRows($this->conversationId))->toHaveKey('error')->not->toHaveKey('rows')
        ->and(readRows($this->conversationId, ['attachment_id' => $strangerFile]))->toHaveKey('error')->not->toHaveKey('rows')
        ->and(readRows($strangerConversation))->toHaveKey('error')->not->toHaveKey('rows')
        ->and(readRows(null))->toHaveKey('error')->not->toHaveKey('rows');
});

it('leaves a text file to the message it arrived in', function (): void {
    readRowsUpload($this->conversationId, ['Call notes', 'Agreed to a pilot.'], 'notes.txt');

    expect(readRows($this->conversationId)['error'])->toBe('No CSV file was sent in this conversation. Ask the user to attach it.');
});

it('hands the assistant the conversation it reads from', function (): void {
    readRowsUpload($this->conversationId, readRowsCompanies(3));

    $tool = array_find((new CrmAssistant)->withConversationId($this->conversationId)->tools(), fn (object $tool): bool => $tool instanceof ReadAttachmentRowsTool);

    expect(json_decode($tool->handle(new Request([])), true)['rows'])->toHaveCount(3);
});
