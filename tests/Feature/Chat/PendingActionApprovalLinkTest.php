<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Http\Controllers\PendingActionController;
use Relaticle\Chat\Services\PendingActionService;

mutates(PendingActionService::class);
mutates(PendingActionController::class);

beforeEach(function (): void {
    Bus::fake();

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

function insertConversationForLinkTest(string $id, User $user): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('records the new record id in result_data after approving a Create pending action', function (): void {
    $conversationId = (string) Str::uuid7();
    insertConversationForLinkTest($conversationId, $this->user);

    /** @var PendingActionService $service */
    $service = app(PendingActionService::class);

    $pending = $service->createProposal(
        user: $this->user,
        conversationId: $conversationId,
        actionClass: CreateCompany::class,
        operation: PendingActionOperation::Create,
        entityType: 'company',
        actionData: ['name' => 'Linked Co', 'account_owner_id' => $this->user->getKey()],
        displayData: ['title' => 'Create Company', 'summary' => 'Create company', 'fields' => []],
    );

    $approved = $service->approve($pending, $this->user);

    expect($approved->result_data)->toBeArray()
        ->and($approved->result_data)->toHaveKey('id')
        ->and($approved->result_data)->toHaveKey('type', 'company');

    $company = Company::query()->where('name', 'Linked Co')->firstOrFail();
    expect($approved->result_data['id'])->toBe((string) $company->getKey());
});

it('returns a record URL from the approve endpoint pointing at the new Filament view page', function (): void {
    $conversationId = (string) Str::uuid7();
    insertConversationForLinkTest($conversationId, $this->user);

    /** @var PendingActionService $service */
    $service = app(PendingActionService::class);

    $pending = $service->createProposal(
        user: $this->user,
        conversationId: $conversationId,
        actionClass: CreateCompany::class,
        operation: PendingActionOperation::Create,
        entityType: 'company',
        actionData: ['name' => 'Deep Link Co', 'account_owner_id' => $this->user->getKey()],
        displayData: ['title' => 'Create Company', 'summary' => 'Create company', 'fields' => []],
    );

    $response = $this->postJson(route('chat.actions.approve', $pending))
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    $company = Company::query()->where('name', 'Deep Link Co')->firstOrFail();

    $response
        ->assertJsonPath('record.id', (string) $company->getKey())
        ->assertJsonPath('record.type', 'company');

    $url = $response->json('record.url');

    expect($url)
        ->toBeString()
        ->toContain('/companies/')
        ->toContain((string) $company->getKey())
        ->not->toContain('/edit');
});
