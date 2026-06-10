<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;

it('persists the active conversation id on pending actions when a tool handles a request', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $conversationId = '01957a1a-5b02-7a01-83b6-c2b7d9b6f1aa';

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'Tool persistence',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var CreateCompanyTool $tool */
    $tool = app(CreateCompanyTool::class);
    $tool->setConversationId($conversationId);

    $tool->handle(new Request(['name' => 'Acme Corp']));

    $pending = PendingAction::query()
        ->where('team_id', $user->currentTeam->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->conversation_id)->toBe($conversationId);
});

it('persists null conversation id when none is set on the tool', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    /** @var CreateCompanyTool $tool */
    $tool = app(CreateCompanyTool::class);

    $tool->handle(new Request(['name' => 'Acme Corp']));

    $pending = PendingAction::query()
        ->where('team_id', $user->currentTeam->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->conversation_id)->toBeNull();
});
