<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\ChatMessageFeedback;
use Relaticle\SystemAdmin\Filament\Resources\ChatMessageFeedbackResource\Pages\ListChatMessageFeedback;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

function seedAdminFeedbackRow(): ChatMessageFeedback
{
    $user = User::factory()->withPersonalTeam()->create();
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'admin feedback test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messageId = (string) Str::uuid7();

    DB::table('agent_conversation_messages')->insert([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'agent' => 'test',
        'role' => 'assistant',
        'content' => 'answer',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ChatMessageFeedback::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'message_id' => $messageId,
        'rating' => 'down',
        'category' => 'inaccurate',
        'comment' => 'wrong numbers',
        'model' => 'claude-sonnet-4',
    ]);
}

it('lists feedback rows across tenants', function (): void {
    $row = seedAdminFeedbackRow();

    livewire(ListChatMessageFeedback::class)
        ->assertCanSeeTableRecords([$row])
        ->assertCanRenderTableColumn('rating')
        ->assertCanRenderTableColumn('category');
});
