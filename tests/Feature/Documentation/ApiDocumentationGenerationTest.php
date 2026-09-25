<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

mutates(AppServiceProvider::class);

it('generates the complete API documentation with company ownership fields', function (): void {
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
});
