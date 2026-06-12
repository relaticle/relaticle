<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Models\PendingAction;

beforeEach(function (): void {
    Bus::fake([ContinueChatMessage::class]);
});

it('approve endpoint returns a record.url for a created company', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $pending = PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => null,
        'message_id' => null,
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => [
            'name' => 'Deeplink Test Co',
            'account_owner_id' => (string) $user->getKey(),
        ],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $response = $this->postJson("/chat/actions/{$pending->id}/approve");

    $response->assertOk();
    $response->assertJsonStructure(['status', 'record' => ['id', 'type', 'url']]);

    $url = $response->json('record.url');

    expect($url)->toBeString()
        ->and($url)->toContain('/companies/')
        ->and($url)->toContain($response->json('record.id'));
});

it('approve endpoint returns a record label for the view link', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $pending = PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => null,
        'message_id' => null,
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Labeled Link Co', 'account_owner_id' => (string) $user->getKey()],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $response = $this->postJson("/chat/actions/{$pending->id}/approve");

    $response->assertOk();
    expect($response->json('record.label'))->toBe('Labeled Link Co');
});
