<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

it('refuses to execute a pending action whose class is not allowlisted', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    DB::table('agent_conversations')->insert([
        'id' => 'conv-allowlist-test',
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pending = PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => 'conv-allowlist-test',
        'action_class' => stdClass::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => [],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    expect(fn () => app(PendingActionService::class)->approve($pending, $user))
        ->toThrow(RuntimeException::class, 'Action class not allowlisted');
});
