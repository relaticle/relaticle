<?php

declare(strict_types=1);

use App\Mcp\Filters\CustomFieldFilter;
use App\Mcp\Filters\CustomFieldSort;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\RecordFieldFixture;

mutates(CustomFieldFilter::class, CustomFieldSort::class, CustomFieldFilterSchema::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();

    TenantContextService::setTenantId($this->team->getKey());
    Cache::flush();

    $this->field = RecordFieldFixture::record($this->team, 'people', 'company', 'vendor', RelationshipCardinality::ManyToOne);

    $this->acme = Company::factory()->for($this->team)->create(['name' => 'Acme Robotics']);
    $this->globex = Company::factory()->for($this->team)->create(['name' => 'Globex']);

    $this->alice = People::factory()->for($this->team)->create(['name' => 'Alice']);
    $this->bob = People::factory()->for($this->team)->create(['name' => 'Bob']);
    $this->carol = People::factory()->for($this->team)->create(['name' => 'Carol']);

    $this->alice->update(['custom_fields' => ['vendor' => [$this->acme->getKey()]]]);
    $this->bob->update(['custom_fields' => ['vendor' => [$this->globex->getKey()]]]);

    Sanctum::actingAs($this->user);
});

it('reads a link field back through the api', function (): void {
    $response = $this->getJson('/api/v1/people/'.$this->alice->getKey())->assertOk();

    expect($response->json('data.attributes.custom_fields.vendor'))
        ->toBe([['id' => (string) $this->acme->getKey(), 'label' => (string) $this->acme->getKey()]]);
});

it('filters people by the id of the record their link field points at', function (): void {
    $response = $this->getJson('/api/v1/people?filter[custom_fields][vendor][eq]='.$this->acme->getKey())->assertOk();

    expect($response->json('data.*.attributes.name'))->toBe(['Alice']);
});

it('filters people by the name of the record their link field points at', function (): void {
    $response = $this->getJson('/api/v1/people?filter[custom_fields][vendor][contains]=Globe')->assertOk();

    expect($response->json('data.*.attributes.name'))->toBe(['Bob']);
});

it('sorts people by the name of the record their link field points at', function (): void {
    $ascending = $this->getJson('/api/v1/people?sort=vendor')->assertOk();
    $descending = $this->getJson('/api/v1/people?sort=-vendor')->assertOk();

    expect(array_slice($ascending->json('data.*.attributes.name'), 0, 2))->toBe(['Alice', 'Bob'])
        ->and(array_slice($descending->json('data.*.attributes.name'), 0, 2))->toBe(['Bob', 'Alice']);
});

it('writes a link field through the api and clears it back', function (): void {
    $this->patchJson('/api/v1/people/'.$this->carol->getKey(), [
        'custom_fields' => ['vendor' => [(string) $this->acme->getKey()]],
    ])->assertOk();

    expect($this->carol->fresh()->getCustomFieldValue($this->field))->toBe([(string) $this->acme->getKey()]);

    $this->patchJson('/api/v1/people/'.$this->carol->getKey(), [
        'custom_fields' => ['vendor' => []],
    ])->assertOk();

    expect($this->carol->fresh()->getCustomFieldValue($this->field))->toBe([]);
});

it('refuses to link a record from another workspace', function (): void {
    $foreign = Company::factory()->for(User::factory()->withPersonalTeam()->create()->personalTeam())->create();

    $this->patchJson('/api/v1/people/'.$this->carol->getKey(), [
        'custom_fields' => ['vendor' => [(string) $foreign->getKey()]],
    ])->assertInvalid(['custom_fields.vendor']);
});
