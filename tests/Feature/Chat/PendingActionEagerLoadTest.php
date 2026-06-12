<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Models\PendingAction;
use Tests\Helpers\ChatDocument;

mutates(ListConversationMessages::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);

    DB::table('agent_conversations')->insert([
        'id' => 'c-perf',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Perf',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (range(1, 10) as $i) {
        DB::table('agent_conversation_messages')->insert([
            'id' => "m-{$i}",
            'conversation_id' => 'c-perf',
            'user_id' => $this->user->getKey(),
            'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
            'role' => 'assistant',
            'content' => "message {$i}",
            'document' => ChatDocument::emptyJson(),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => json_encode([[
                'result' => json_encode([
                    'type' => 'pending_action',
                    'pending_action_id' => "pa-{$i}",
                ]),
            ]]),
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        PendingAction::query()->create([
            'id' => "pa-{$i}",
            'team_id' => $this->team->getKey(),
            'user_id' => $this->user->getKey(),
            'conversation_id' => 'c-perf',
            'message_id' => "m-{$i}",
            'action_class' => 'App\\Actions\\Company\\CreateCompany',
            'operation' => 'create',
            'entity_type' => 'company',
            'action_data' => [],
            'display_data' => [],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
    }
});

it('fetches pending_actions in a single batch', function (): void {
    DB::enableQueryLog();

    resolve(ListConversationMessages::class)->execute($this->user, 'c-perf');

    $queries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'pending_actions'));

    expect($queries)->toHaveCount(1);

    DB::disableQueryLog();
});
