<?php

declare(strict_types=1);

use App\Enums\CustomFieldType;
use App\Http\Requests\Api\V1\BaseCrmEntityRequest;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Scribe\Strategies\GetFromSpatieQueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;

mutates(AppServiceProvider::class, BaseCrmEntityRequest::class, GetFromSpatieQueryBuilder::class);

it('generates the complete API documentation with company ownership fields', function (): void {
    expect(Schema::hasTable('custom_fields'))->toBeTrue();

    $disk = Storage::fake('local');
    $this->app->usePublicPath($disk->path('public'));
    config()->set('scribe.static.output_path', $disk->path('public/docs'));
    config()->set('view.paths.0', $disk->path('views'));

    $this->artisan('scribe:generate', [
        '--force' => true,
        '--no-interaction' => true,
        '--scribe-dir' => $disk->path('.scribe'),
    ])->assertSuccessful();

    $disk->assertExists(['scribe/openapi.yaml', 'scribe/collection.json', 'views/scribe/index.blade.php']);

    $spec = Yaml::parse($disk->get('scribe/openapi.yaml'));

    expect($spec['paths']['/api/v1/companies']['post']['requestBody']['content']['application/json']['schema']['properties'])
        ->toHaveKey('account_owner_id');
    expect($spec['paths']['/api/v1/companies/{id}']['put']['requestBody']['content']['application/json']['schema']['properties'])
        ->toHaveKey('account_owner_id');

    $customFieldFilter = collect($spec['paths']['/api/v1/opportunities']['get']['parameters'])
        ->firstWhere('name', 'filter[custom_fields][{code}][{operator}]');
    $publishedOperators = collect(CustomFieldType::cases())
        ->flatMap(fn (CustomFieldType $type): array => array_keys(CustomFieldFilterSchema::operatorsForType($type->value)))
        ->filter(fn (string $operator): bool => str_starts_with($operator, '$'))
        ->unique()
        ->all();

    $query = $spec['paths']['/api/v1/companies/query']['post'];
    $queryBody = $query['requestBody']['content']['application/json']['schema']['properties'];
    $listParameters = collect($spec['paths']['/api/v1/companies']['get']['parameters'])->keyBy('name');

    expect($queryBody)->toHaveKeys(['filter', 'sort', 'include', 'per_page', 'cursor', 'page'])
        ->and($query['parameters'])->toBe([])
        ->and($query['description'])->toContain('re-send the same body with `page` or `cursor`')
        ->and($queryBody['sort']['description'])->toBe($listParameters['sort']['description'])
        ->and($queryBody['include']['description'])->toBe($listParameters['include']['description'])
        ->and($queryBody['sort']['description'])->toContain('Allowed:')
        ->and($queryBody['per_page']['description'])->toBe($listParameters['per_page']['description'])
        ->and($queryBody['cursor']['description'])->toBe($listParameters['cursor']['description'])
        ->and($queryBody['page']['description'])->toBe($listParameters['page']['description']);

    $user = User::factory()->withPersonalWorkspace()->create();
    Sanctum::actingAs($user);

    foreach (['companies', 'people', 'opportunities', 'tasks', 'notes'] as $entity) {
        $example = $spec['paths']["/api/v1/{$entity}/query"]['post']['requestBody']['content']['application/json']['schema']['properties']['filter']['examples'][0];

        $this->postJson("/api/v1/{$entity}/query", ['filter' => $example])->assertOk();
    }

    expect($customFieldFilter)->not->toBeNull()
        ->and(array_diff($publishedOperators, str($customFieldFilter['description'])->matchAll('/\$[a-z_]+/')->all()))->toBe([]);
});

it('generates the API documentation before the database is migrated', function (): void {
    $disk = Storage::fake('local');
    $this->app->usePublicPath($disk->path('public'));
    config()->set('scribe.static.output_path', $disk->path('public/docs'));
    config()->set('view.paths.0', $disk->path('views'));

    DB::statement('drop schema public cascade');
    DB::statement('create schema public');

    $this->artisan('scribe:generate', [
        '--force' => true,
        '--no-interaction' => true,
        '--scribe-dir' => $disk->path('.scribe'),
    ])->assertSuccessful();

    $spec = Yaml::parse($disk->get('scribe/openapi.yaml'));

    expect($spec['paths']['/api/v1/tasks']['post']['requestBody']['content']['application/json']['schema']['properties'])
        ->toHaveKeys(['assignee_ids', 'custom_fields']);
});
