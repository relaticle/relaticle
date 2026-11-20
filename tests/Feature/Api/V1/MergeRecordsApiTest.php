<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CompaniesMergeController;
use App\Http\Controllers\Api\V1\PeopleMergeController;
use App\Http\Requests\Api\V1\MergeRecordsRequest;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\WorkspaceCustomField;

mutates(CompaniesMergeController::class, PeopleMergeController::class, MergeRecordsRequest::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    TenantContextService::setTenantId($this->workspace->getKey());
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

dataset('mergeApiEntities', ['companies', 'people']);

function mergeApiMake(string $path, User $user, array $values = [], array $attributes = []): Model
{
    $workspace = $user->personalWorkspace();

    return match ($path) {
        'companies' => mergeCompanyRecord($workspace, $user, $attributes, $values === [] ? [] : ['domains' => $values]),
        'people' => mergePersonRecord($workspace, $user, $attributes, $values === [] ? [] : ['emails' => $values]),
    };
}

function mergeApiValues(string $path, string $prefix, int $count): array
{
    $suffix = $path === 'companies' ? '.com' : '@x.com';

    return array_map(fn (int $number): string => "{$prefix}{$number}{$suffix}", range(1, $count));
}

function mergeApiField(string $path): array
{
    return $path === 'companies' ? ['company', 'domains'] : ['people', 'emails'];
}

function mergeApiBody(Model $primary, Model $secondary): array
{
    return ['primary_record_id' => $primary->getKey(), 'secondary_record_id' => $secondary->getKey()];
}

it('requires authentication', function (string $path): void {
    $this->postJson("/api/v1/{$path}/merge", [])->assertUnauthorized();
})->with(['companies', 'people']);

it('merges two records into a new one and answers 404 for both originals', function (string $path): void {
    Sanctum::actingAs($this->user, ['create', 'update', 'delete']);
    $primary = mergeApiMake($path, $this->user, [], ['name' => 'Primary Name']);
    $secondary = mergeApiMake($path, $this->user, [], ['name' => 'Secondary Name']);

    $response = $this->postJson("/api/v1/{$path}/merge", mergeApiBody($primary, $secondary))
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Primary Name');

    $mergedId = $response->json('data.id');

    expect($mergedId)->not->toBeIn([$primary->getKey(), $secondary->getKey()]);

    $this->getJson("/api/v1/{$path}/{$mergedId}")->assertOk();
    $this->getJson("/api/v1/{$path}/{$primary->getKey()}")->assertNotFound();
    $this->getJson("/api/v1/{$path}/{$secondary->getKey()}")->assertNotFound();
})->with('mergeApiEntities');

it('refuses the same id twice on secondary_record_id', function (string $path): void {
    Sanctum::actingAs($this->user, ['create', 'update', 'delete']);
    $record = mergeApiMake($path, $this->user);

    $this->postJson("/api/v1/{$path}/merge", mergeApiBody($record, $record))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['secondary_record_id']);
})->with('mergeApiEntities');

it('refuses an id that is unknown, deleted, merged or in another workspace', function (string $path): void {
    Sanctum::actingAs($this->user, ['create', 'update', 'delete']);
    $primary = mergeApiMake($path, $this->user);
    $foreignOwner = User::factory()->withPersonalWorkspace()->create();
    $deleted = mergeApiMake($path, $this->user);
    $deleted->delete();

    $first = mergeApiMake($path, $this->user);
    $second = mergeApiMake($path, $this->user);
    $this->postJson("/api/v1/{$path}/merge", mergeApiBody($first, $second))->assertOk();

    $cases = [
        'unknown' => (string) Str::ulid(),
        'deleted' => $deleted->getKey(),
        'merged' => $first->getKey(),
        'other workspace' => ($path === 'companies' ? Company::factory() : People::factory())->recycle([$foreignOwner, $foreignOwner->personalWorkspace()])->create()->getKey(),
    ];

    foreach ($cases as $id) {
        $this->postJson("/api/v1/{$path}/merge", ['primary_record_id' => $primary->getKey(), 'secondary_record_id' => $id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['secondary_record_id']);

        $this->postJson("/api/v1/{$path}/merge", ['primary_record_id' => $id, 'secondary_record_id' => $primary->getKey()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['primary_record_id']);
    }

    expect($primary->fresh())->not->toBeNull();
})->with('mergeApiEntities');

it('refuses a repeat of the same request', function (string $path): void {
    Sanctum::actingAs($this->user, ['create', 'update', 'delete']);
    $primary = mergeApiMake($path, $this->user);
    $secondary = mergeApiMake($path, $this->user);

    $this->postJson("/api/v1/{$path}/merge", mergeApiBody($primary, $secondary))->assertOk();

    $this->postJson("/api/v1/{$path}/merge", mergeApiBody($primary, $secondary))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['primary_record_id', 'secondary_record_id']);
})->with('mergeApiEntities');

it('refuses a token missing one of the three abilities', function (string $path, string $missing): void {
    $token = $this->user->createToken('test', array_values(array_diff(['create', 'update', 'delete'], [$missing])))->plainTextToken;
    $primary = mergeApiMake($path, $this->user);
    $secondary = mergeApiMake($path, $this->user);

    $this->withToken($token)->postJson("/api/v1/{$path}/merge", mergeApiBody($primary, $secondary))->assertForbidden();

    expect($primary->fresh())->not->toBeNull()->and($secondary->fresh())->not->toBeNull();
})->with('mergeApiEntities')->with(['create', 'update', 'delete']);

it('raises the field maximum when the combined count passes it', function (string $path): void {
    Sanctum::actingAs($this->user, ['create', 'update', 'delete']);
    $primary = mergeApiMake($path, $this->user, mergeApiValues($path, 'a', 4));
    $secondary = mergeApiMake($path, $this->user, mergeApiValues($path, 'b', 4));
    $field = WorkspaceCustomField::byCode($this->workspace->getKey(), ...mergeApiField($path));
    expect($field->settings->max_values)->toBe(5);

    $this->postJson("/api/v1/{$path}/merge", mergeApiBody($primary, $secondary))->assertOk();

    expect($field->fresh()->settings->max_values)->toBe(8);
})->with('mergeApiEntities');

it('refuses a combined count above the ceiling of 20 on records', function (string $path): void {
    Sanctum::actingAs($this->user, ['create', 'update', 'delete']);
    $primary = mergeApiMake($path, $this->user, mergeApiValues($path, 'a', 11));
    $secondary = mergeApiMake($path, $this->user, mergeApiValues($path, 'b', 10));

    $this->postJson("/api/v1/{$path}/merge", mergeApiBody($primary, $secondary))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['records']);

    expect($primary->fresh())->not->toBeNull()->and($secondary->fresh())->not->toBeNull();
})->with('mergeApiEntities');
