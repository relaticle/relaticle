<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('agent_conversation_messages.document column has the empty-doc default', function (): void {
    $row = DB::selectOne(
        "SELECT column_default
         FROM information_schema.columns
         WHERE table_name = 'agent_conversation_messages' AND column_name = 'document'"
    );

    $normalized = preg_replace('/\s+/', '', (string) $row->column_default);

    expect($row)->not->toBeNull()
        ->and($normalized)->toContain('"type":"doc"')
        ->and($normalized)->toContain('"content":[]');
});

it('agent_conversation_messages.document column is NOT NULL', function (): void {
    $row = DB::selectOne(
        "SELECT is_nullable
         FROM information_schema.columns
         WHERE table_name = 'agent_conversation_messages' AND column_name = 'document'"
    );

    expect($row->is_nullable)->toBe('NO');
});
