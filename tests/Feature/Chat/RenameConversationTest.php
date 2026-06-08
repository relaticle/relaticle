<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Actions\RenameConversation;
use Relaticle\Chat\Http\Controllers\ChatController;

mutates(RenameConversation::class, ChatController::class);

it('renames a conversation', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    DB::table('agent_conversations')->insert([
        'id' => 'conv-rename-1',
        'user_id' => $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'Old title',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson(route('chat.rename', ['conversationId' => 'conv-rename-1']), [
        'title' => 'New shiny title',
    ])
        ->assertOk()
        ->assertJson(['title' => 'New shiny title']);

    expect(DB::table('agent_conversations')->where('id', 'conv-rename-1')->value('title'))
        ->toBe('New shiny title');
});

it('rejects rename of conversation belonging to another user', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $other = User::factory()->withPersonalTeam()->create();

    DB::table('agent_conversations')->insert([
        'id' => 'conv-rename-2',
        'user_id' => $owner->getKey(),
        'team_id' => $owner->currentTeam->getKey(),
        'title' => 'Owner title',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($other);
    $this->postJson(route('chat.rename', ['conversationId' => 'conv-rename-2']), [
        'title' => 'Hijacked',
    ])->assertNotFound();

    expect(DB::table('agent_conversations')->where('id', 'conv-rename-2')->value('title'))
        ->toBe('Owner title');
});

it('validates title length', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    DB::table('agent_conversations')->insert([
        'id' => 'conv-rename-3',
        'user_id' => $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'Title',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson(route('chat.rename', ['conversationId' => 'conv-rename-3']), [
        'title' => str_repeat('a', 256),
    ])->assertUnprocessable();
});
