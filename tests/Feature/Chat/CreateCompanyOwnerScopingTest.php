<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;

it('rejects creating a company with account_owner_id from a foreign team', function (): void {
    $userA = User::factory()->withPersonalTeam()->create();
    $foreignUser = User::factory()->withPersonalTeam()->create();

    $this->actingAs($userA);

    expect(
        fn () => app(CreateCompany::class)->execute($userA, [
            'name' => 'Cross-Tenant Test Co',
            'account_owner_id' => (string) $foreignUser->getKey(),
        ])
    )->toThrow(ValidationException::class);
});

it('accepts a company whose account_owner_id is a member of the workspace', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $teammate = User::factory()->create();
    $owner->currentTeam->users()->attach($teammate, ['role' => 'editor']);

    $this->actingAs($owner);

    $company = app(CreateCompany::class)->execute($owner, [
        'name' => 'Friendly Co',
        'account_owner_id' => (string) $teammate->getKey(),
    ]);

    expect($company->account_owner_id)->toBe((string) $teammate->getKey());
});

function createToolConversationFor(User $owner, string $conversationId): CreateCompanyTool
{
    test()->actingAs($owner);
    Auth::guard('web')->setUser($owner);

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $owner->getKey(),
        'team_id' => $owner->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tool = resolve(CreateCompanyTool::class);
    $tool->setConversationId($conversationId);

    return $tool;
}

it('CreateCompanyTool accepts an explicit team-member owner and shows it on the card', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $teammate = User::factory()->create(['name' => 'Casey Closer']);
    $owner->currentTeam->users()->attach($teammate, ['role' => 'editor']);

    $tool = createToolConversationFor($owner, '019df800-3333-7000-8000-000000000077');

    $response = $tool->handle(new Request([
        'records' => [['name' => 'Delegated Co', 'account_owner_id' => (string) $teammate->getKey()]],
    ]));

    expect($response)->toContain('pending_action');

    $pending = PendingAction::query()
        ->where('team_id', $owner->currentTeam->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)->toHaveKey('account_owner_id', (string) $teammate->getKey());

    $ownerRow = collect($pending->display_data['fields'])->firstWhere('label', 'Account Owner');
    expect($ownerRow['value'])->toBe('Casey Closer');
});

it('CreateCompanyTool rejects a non-member owner id before proposing', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $stranger = User::factory()->withPersonalTeam()->create();

    $tool = createToolConversationFor($owner, '019df800-3333-7000-8000-000000000078');

    $response = $tool->handle(new Request([
        'records' => [['name' => 'Blocked Co', 'account_owner_id' => (string) $stranger->getKey()]],
    ]));

    expect($response)->toContain('must be a workspace team member')
        ->and(PendingAction::query()->where('team_id', $owner->currentTeam->getKey())->count())->toBe(0);
});
