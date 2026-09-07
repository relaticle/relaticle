<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomFieldLink;
use App\Models\People;
use App\Models\User;
use App\Support\LinkActorResolver;
use Filament\Facades\Filament;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\RecordFieldFixture;

mutates(LinkActorResolver::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->getKey());

    $this->field = RecordFieldFixture::record($this->team, 'people', 'company', 'vendors', RelationshipCardinality::ManyToMany);
});

it('stamps the signed-in user on a link written through the panel', function (): void {
    $person = People::factory()->for($this->team)->create();
    $company = Company::factory()->for($this->team)->create();

    $person->update(['custom_fields' => ['vendors' => [$company->getKey()]]]);

    $link = CustomFieldLink::query()->sole();

    expect($link->created_by_type)->toBe('user')
        ->and($link->created_by_id)->toBe($this->user->getKey());
});

it('stamps the proposal owner on a link written by an approval with no session', function (): void {
    $person = People::factory()->for($this->team)->create();
    $company = Company::factory()->for($this->team)->create();

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\People\\UpdatePeople',
        'operation' => PendingActionOperation::Update,
        'entity_type' => 'people',
        'action_data' => [
            '_record_id' => (string) $person->getKey(),
            '_model_class' => People::class,
            'custom_fields' => ['vendors' => [(string) $company->getKey()]],
        ],
        'display_data' => ['title' => 'Update Person', 'summary' => 'Link a vendor', 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    auth()->forgetUser();

    resolve(PendingActionService::class)->approve($pending, $this->user);

    $link = CustomFieldLink::query()->sole();

    expect($link->created_by_type)->toBe('user')
        ->and($link->created_by_id)->toBe($this->user->getKey())
        ->and(resolve(LinkActorResolver::class)->resolve())->toBeNull();
});
