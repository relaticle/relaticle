<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\GetRelatedRecordsTool;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\RecordFieldFixture;

mutates(GetRelatedRecordsTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;

    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->getKey());

    $this->employer = RecordFieldFixture::record($this->team, 'people', 'company', 'employer', RelationshipCardinality::ManyToOne);
    $this->account = RecordFieldFixture::record($this->team, 'opportunity', 'company', 'account', RelationshipCardinality::ManyToOne);

    $this->alice = People::factory()->for($this->team)->create(['name' => 'Alice Doe']);
    $this->acme = Company::factory()->for($this->team)->create(['name' => 'Acme Robotics']);
    $this->deal = Opportunity::factory()->for($this->team)->create(['name' => 'Acme renewal']);

    $this->alice->update(['custom_fields' => ['employer' => [$this->acme->getKey()]]]);
    $this->deal->update(['custom_fields' => ['account' => [$this->acme->getKey()]]]);

    $this->call = fn (array $input): array => json_decode(
        (new GetRelatedRecordsTool)->handle(new Request($input)),
        true,
    );
});

it('returns the records one link away, grouped by relationship', function (): void {
    $result = ($this->call)(['entity_type' => 'people', 'id' => (string) $this->alice->getKey()]);

    expect($result['root']['name'])->toBe('Alice Doe')
        ->and($result['relationships'])->toHaveCount(1)
        ->and($result['relationships'][0]['code'])->toBe('people_employer')
        ->and($result['relationships'][0]['field'])->toBe('Employer')
        ->and($result['relationships'][0]['records'])->toBe([[
            'type' => 'company',
            'id' => (string) $this->acme->getKey(),
            'name' => 'Acme Robotics',
            'depth' => 1,
        ]]);
});

it('reaches the second hop and never walks back to where it started', function (): void {
    $result = ($this->call)(['entity_type' => 'people', 'id' => (string) $this->alice->getKey(), 'depth' => 2]);

    $reached = collect($result['relationships'])
        ->flatMap(fn (array $group): array => $group['records'])
        ->map(fn (array $record): string => $record['name'].':'.$record['depth'])
        ->all();

    expect($reached)->toEqualCanonicalizing(['Acme Robotics:1', 'Acme renewal:2'])
        ->and(collect($result['relationships'])->pluck('code')->all())
        ->toEqualCanonicalizing(['people_employer', 'opportunity_account']);
});

it('stops at the requested depth', function (): void {
    $result = ($this->call)(['entity_type' => 'people', 'id' => (string) $this->alice->getKey(), 'depth' => 1]);

    expect(collect($result['relationships'])->flatMap(fn (array $group): array => $group['records'])->pluck('name')->all())
        ->toBe(['Acme Robotics']);
});

it('never crosses into another workspace', function (): void {
    $other = User::factory()->withTeam()->create();

    $this->actingAs($other);
    Filament::setTenant($other->currentTeam);

    $result = ($this->call)(['entity_type' => 'people', 'id' => (string) $this->alice->getKey()]);

    expect($result)->toHaveKey('error');
});

it('rejects an unknown entity type', function (): void {
    expect(($this->call)(['entity_type' => 'invoice', 'id' => (string) $this->alice->getKey()]))->toHaveKey('error');
});
