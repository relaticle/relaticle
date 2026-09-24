<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function runMigrateSyntheticChatMessagesMigration(): void
{
    $path = glob(database_path('migrations/*_migrate_synthetic_chat_messages_to_origin.php'))[0];

    (require $path)->up();
}

function legacyConversation(User $user, ?string $purpose = null): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => 'legacy',
        'purpose' => $purpose,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

function legacyMessage(string $conversationId, User $user, string $role, string $content, string $meta, int $offset): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversation_messages')->insert([
        'id' => $id,
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'crm',
        'role' => $role,
        'content' => $content,
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '[]',
        'meta' => $meta,
        'created_at' => now()->addSeconds($offset),
        'updated_at' => now()->addSeconds($offset),
    ]);

    return $id;
}

test('turns a legacy setup greeting into a greeting row holding the opener', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    DB::table('agent_conversations')->where('workspace_id', $user->currentWorkspace->getKey())->delete();
    $conversationId = legacyConversation($user, 'setup');

    $greeting = legacyMessage($conversationId, $user, 'user', 'The user finished signing up a moment ago.', '{"kind": "continuation"}', 0);
    legacyMessage($conversationId, $user, 'assistant', 'Hi Jane.', '{"model": "m"}', 1);
    $typed = legacyMessage($conversationId, $user, 'user', 'Here are my contacts', '[]', 2);
    $resume = legacyMessage($conversationId, $user, 'user', 'The proposals from your last turn have just been decided.', '{"kind": "continuation"}', 3);

    runMigrateSyntheticChatMessagesMigration();

    $rows = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->get()->keyBy('id');

    expect($rows[$greeting]->origin)->toBe('greeting')
        ->and($rows[$greeting]->content)->toBe('The user opened their setup conversation.')
        ->and(json_decode((string) $rows[$greeting]->meta, true))->not->toHaveKey('kind')
        ->and($rows[$resume]->origin)->toBe('resume')
        ->and($rows[$resume]->content)->toBe('The user decided the proposals above.')
        ->and($rows[$typed]->origin)->toBe('typed')
        ->and($rows[$typed]->content)->toBe('Here are my contacts');
});

test('turns legacy approval echoes and ordinary continuations into resume rows', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = legacyConversation($user);

    legacyMessage($conversationId, $user, 'user', 'Create a company', '[]', 0);
    $approval = legacyMessage($conversationId, $user, 'user', "[approval]\nstatus: approved\noperation: create\nentity_type: company\n", '[]', 1);
    $continuation = legacyMessage($conversationId, $user, 'user', 'The proposals from your last turn have just been decided.', '{"kind": "continuation"}', 2);

    runMigrateSyntheticChatMessagesMigration();

    $rows = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->get()->keyBy('id');

    expect($rows[$approval]->origin)->toBe('resume')
        ->and($rows[$approval]->content)->toBe('The user decided the proposals above.')
        ->and($rows[$continuation]->origin)->toBe('resume')
        ->and(json_decode((string) $rows[$continuation]->meta, true))->not->toHaveKey('kind');
});

test('leaves typed rows and the import handoff assistant row untouched', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = legacyConversation($user);

    $typed = legacyMessage($conversationId, $user, 'user', 'Attached contacts.csv', '{"attachment": {"id": "a"}}', 0);
    $handoff = legacyMessage($conversationId, $user, 'assistant', "That's 900 rows.", '{"kind": "import_handoff"}', 1);

    runMigrateSyntheticChatMessagesMigration();

    $rows = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->get()->keyBy('id');

    expect($rows[$typed]->origin)->toBe('typed')
        ->and(json_decode((string) $rows[$typed]->meta, true))->toHaveKey('attachment')
        ->and($rows[$handoff]->origin)->toBe('typed')
        ->and(json_decode((string) $rows[$handoff]->meta, true))->toBe(['kind' => 'import_handoff']);
});

test('migrates every payload the retired approval writer produced', function (string $content): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = legacyConversation($user);
    $approval = legacyMessage($conversationId, $user, 'user', $content, '[]', 0);

    runMigrateSyntheticChatMessagesMigration();

    $row = DB::table('agent_conversation_messages')->where('id', $approval)->first();

    expect($row->origin)->toBe('resume')
        ->and($row->content)->toBe('The user decided the proposals above.');
})->with([
    'structured' => "[approval]\nstatus: rejected\noperation: update\nentity_type: people\n",
    'approved prose' => "[approval]\nThe user APPROVED and the system has already EXECUTED this action: create Acme.",
    'rejected prose' => "[approval]\nThe user REJECTED the proposal to delete Acme.\nDo not silently retry it.",
]);

test('migrates a resume prompt a failed turn stored without the continuation mark', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = legacyConversation($user);
    $prompt = 'The proposals from your last turn have just been decided. Their outcome is in <resolved_actions>. Confirm what happened in one short sentence, naming each record as a link. If a step of the request is still outstanding and you can act on it now, do it in this turn. If nothing is left, say so and stop.';

    $resume = legacyMessage($conversationId, $user, 'user', $prompt, '[]', 0);
    $typed = legacyMessage($conversationId, $user, 'user', 'The proposals from your last turn have just been decided.', '[]', 1);

    runMigrateSyntheticChatMessagesMigration();

    $rows = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->get()->keyBy('id');

    expect($rows[$resume]->origin)->toBe('resume')
        ->and($rows[$resume]->content)->toBe('The user decided the proposals above.')
        ->and($rows[$typed]->origin)->toBe('typed')
        ->and($rows[$typed]->content)->toBe('The proposals from your last turn have just been decided.');
});

test('never rewrites a message a person typed that merely starts with the approval token', function (string $content): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = legacyConversation($user);
    $typed = legacyMessage($conversationId, $user, 'user', $content, '[]', 0);

    runMigrateSyntheticChatMessagesMigration();

    $row = DB::table('agent_conversation_messages')->where('id', $typed)->first();

    expect($row->origin)->toBe('typed')
        ->and($row->content)->toBe($content);
})->with([
    'same line' => '[approval] why does this appear in my chat?',
    'own line, human text' => "[approval]\nplease approve the Acme deal",
    'bare token' => '[approval]',
]);

test('changes nothing on a second run, including rows already in the new shape', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = legacyConversation($user);

    legacyMessage($conversationId, $user, 'user', 'Create a company', '[]', 0);
    legacyMessage($conversationId, $user, 'user', "[approval]\nstatus: approved\n", '[]', 1);
    legacyMessage($conversationId, $user, 'user', 'old prompt', '{"kind": "continuation"}', 2);
    $current = legacyMessage($conversationId, $user, 'user', 'The user decided the proposals above.', '[]', 3);
    DB::table('agent_conversation_messages')->where('id', $current)->update(['origin' => 'resume']);

    $snapshot = fn (): array => DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->orderBy('id')
        ->get(['id', 'role', 'origin', 'content', 'meta'])
        ->map(fn (object $row): array => (array) $row)
        ->all();

    runMigrateSyntheticChatMessagesMigration();
    $afterFirstRun = $snapshot();

    runMigrateSyntheticChatMessagesMigration();

    expect($snapshot())->toBe($afterFirstRun)
        ->and(array_column($afterFirstRun, 'origin'))->toBe(['typed', 'resume', 'resume', 'resume']);
});
