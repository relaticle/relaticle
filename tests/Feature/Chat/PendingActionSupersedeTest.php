<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

mutates(PendingActionService::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
});

function makePendingAction(User $user, string $conversationId, string $name = 'Acme'): PendingAction
{
    return PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => $name],
        'display_data' => ['title' => 'Create Company', 'summary' => "Create {$name}", 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
}

it('marks every still-pending action on the conversation as superseded and returns the pre-update snapshot', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'conv-supersede',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $a = makePendingAction($this->user, 'conv-supersede', 'Acme');
    $b = makePendingAction($this->user, 'conv-supersede', 'Globex');

    $superseded = resolve(PendingActionService::class)
        ->supersedePendingForConversation('conv-supersede');

    expect($superseded)->toHaveCount(2)
        ->and(collect($superseded)->pluck('id')->all())->toEqualCanonicalizing([$a->getKey(), $b->getKey()])
        ->and($a->refresh()->status)->toBe(PendingActionStatus::Superseded)
        ->and($b->refresh()->status)->toBe(PendingActionStatus::Superseded)
        ->and($a->resolved_at)->not->toBeNull();
});

it('does not touch already-resolved actions on the same conversation', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'conv-mixed',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pending = makePendingAction($this->user, 'conv-mixed', 'Still pending');
    $approved = makePendingAction($this->user, 'conv-mixed', 'Already done');
    $approved->update(['status' => PendingActionStatus::Approved, 'resolved_at' => now()]);

    $superseded = resolve(PendingActionService::class)
        ->supersedePendingForConversation('conv-mixed');

    expect($superseded)->toHaveCount(1)
        ->and($superseded[0]->id)->toBe($pending->getKey())
        ->and($approved->refresh()->status)->toBe(PendingActionStatus::Approved);
});

it('returns an empty list when there are no pending actions on the conversation', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'conv-empty',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $superseded = resolve(PendingActionService::class)
        ->supersedePendingForConversation('conv-empty');

    expect($superseded)->toBe([]);
});

it('exposes Superseded as a status with gray color and human label', function (): void {
    expect(PendingActionStatus::Superseded->value)->toBe('superseded')
        ->and(PendingActionStatus::Superseded->getColor())->toBe('gray')
        ->and(PendingActionStatus::Superseded->getLabel())->toBe('Superseded');
});
