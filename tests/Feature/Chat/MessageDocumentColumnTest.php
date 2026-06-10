<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('agent_conversation_messages has a non-null document jsonb column', function (): void {
    expect(Schema::hasColumn('agent_conversation_messages', 'document'))->toBeTrue();

    $columnType = Schema::getColumnType('agent_conversation_messages', 'document');

    // Postgres reports jsonb as 'jsonb' via getColumnType()
    expect($columnType)->toBe('jsonb');

    $isNullable = DB::selectOne(
        'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
        ['agent_conversation_messages', 'document'],
    )->is_nullable;

    expect($isNullable)->toBe('NO');
});

it('rejects null document on insert', function (): void {
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => null,
        'team_id' => null,
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $exception = null;

    try {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::ulid(),
            'conversation_id' => $conversationId,
            'agent' => 'crm',
            'role' => 'user',
            'content' => 'hi',
            'document' => null,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (Throwable $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->getMessage())->toContain('null value in column "document"');
});
