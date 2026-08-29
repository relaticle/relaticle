<?php

declare(strict_types=1);

use App\Actions\People\UpdatePeople;
use App\Features\OnboardSeed;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\BaseWriteCreateTool;
use Relaticle\Chat\Tools\People\CreatePersonTool;
use Relaticle\Chat\Tools\People\UpdatePersonTool;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(BaseWriteCreateTool::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);

    // Mirror ProcessChatMessage::bindAuth(): tool validation runs inside the
    // chat job with the custom-fields tenant bound, which is what arms the
    // per-field unique rules this guard relies on.
    TenantContextService::setTenantId($this->team->getKey());

    $this->convId = '019df800-4444-7000-8000-000000000321';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

function seedPersonWithEmail(User $user, string $name, string $email): People
{
    $person = People::factory()->for($user->currentTeam)->create(['name' => $name]);
    resolve(UpdatePeople::class)->execute($user, $person, ['custom_fields' => ['emails' => [$email]]]);

    return $person;
}

function runCreatePersonTool(string $conversationId, array $payload): string
{
    $tool = resolve(CreatePersonTool::class);
    $tool->setConversationId($conversationId);

    return $tool->handle(new Request($payload));
}

it('rejects a single create whose email is already taken, with no proposal', function (): void {
    seedPersonWithEmail($this->user, 'Test Person', 'test@example.com');

    $response = runCreatePersonTool($this->convId, ['records' => [[
        'name' => 'Another Person',
        'custom_fields' => ['emails' => ['test@example.com']],
    ]]]);

    expect($response)->toContain('already assigned')
        ->toContain('test@example.com')
        ->not->toContain('pending_action_id');

    expect(PendingAction::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});

it('skips only the failing record of a batch and reports it to the model', function (): void {
    seedPersonWithEmail($this->user, 'Test Person', 'test@example.com');

    $response = runCreatePersonTool($this->convId, ['records' => [
        ['name' => 'Fresh Person', 'custom_fields' => ['emails' => ['fresh@example.com']]],
        ['name' => 'Copy Person', 'custom_fields' => ['emails' => ['test@example.com']]],
    ]]);

    $decoded = json_decode($response, true);

    expect($decoded['pending_action_id'] ?? null)->not->toBeNull()
        ->and($decoded['data']['name'] ?? null)->toBe('Fresh Person')
        ->and($decoded['skipped_records'][0]['record'] ?? null)->toBe('Copy Person')
        ->and($decoded['skipped_records'][0]['reason'] ?? '')->toContain('already assigned');

    $pending = PendingAction::query()->where('team_id', $this->team->getKey())->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->action_data['name'] ?? null)->toBe('Fresh Person');
});

it('proposes normally when the email is unused or absent', function (): void {
    seedPersonWithEmail($this->user, 'Test Person', 'test@example.com');

    $fresh = runCreatePersonTool($this->convId, ['records' => [[
        'name' => 'Fresh Person',
        'custom_fields' => ['emails' => ['fresh@example.com']],
    ]]]);

    $noEmail = runCreatePersonTool($this->convId, ['records' => [[
        'name' => 'No Email Person',
    ]]]);

    expect($fresh)->toContain('pending_action_id')
        ->and($noEmail)->toContain('pending_action_id');
});

it('lets an update resubmit the record\'s own unique email', function (): void {
    $person = seedPersonWithEmail($this->user, 'Test Person', 'test@example.com');

    $tool = resolve(UpdatePersonTool::class);
    $tool->setConversationId($this->convId);

    $response = $tool->handle(new Request(['records' => [[
        'id' => (string) $person->getKey(),
        'custom_fields' => ['emails' => ['test@example.com'], 'job_title' => 'VP of Sales'],
    ]]]));

    expect($response)->toContain('pending_action_id')
        ->not->toContain('already assigned');
});

it('still blocks an update that takes another record\'s unique email', function (): void {
    seedPersonWithEmail($this->user, 'Test Person', 'test@example.com');
    $other = seedPersonWithEmail($this->user, 'Other Person', 'other@example.com');

    $tool = resolve(UpdatePersonTool::class);
    $tool->setConversationId($this->convId);

    $response = $tool->handle(new Request(['records' => [[
        'id' => (string) $other->getKey(),
        'custom_fields' => ['emails' => ['test@example.com']],
    ]]]));

    expect($response)->toContain('already assigned')
        ->not->toContain('pending_action_id');
});

it('ignores matching emails on another team', function (): void {
    $other = User::factory()->withPersonalTeam()->create();
    $previousTenantId = TenantContextService::getCurrentTenantId();
    TenantContextService::setTenantId($other->currentTeam->getKey());
    seedPersonWithEmail($other, 'Foreign Person', 'test@example.com');
    TenantContextService::setTenantId($previousTenantId);

    $response = runCreatePersonTool($this->convId, ['records' => [[
        'name' => 'Another Person',
        'custom_fields' => ['emails' => ['test@example.com']],
    ]]]);

    expect($response)->toContain('pending_action_id');
});
