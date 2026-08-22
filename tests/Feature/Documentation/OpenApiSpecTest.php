<?php

declare(strict_types=1);

use App\Scribe\OpenApi\ErrorResponsesGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Relaticle\Documentation\Http\Controllers\OpenApiSpecController;

mutates(OpenApiSpecController::class, ErrorResponsesGenerator::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('404s both spec URLs until scribe has generated the spec', function (): void {
    $this->get('/openapi.json')->assertNotFound();
    $this->get('/openapi.yaml')->assertNotFound();
});

it('serves the generated spec as yaml and as json', function (): void {
    Storage::disk('local')->put('scribe/openapi.yaml', "openapi: 3.1.0\ninfo:\n  title: Relaticle\npaths: {}\n");

    $this->get('/openapi.yaml')
        ->assertOk()
        ->assertHeader('content-type', 'application/yaml; charset=UTF-8')
        ->assertSee('openapi: 3.1.0', false);

    $this->get('/openapi.json')
        ->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertExactJson(['openapi' => '3.1.0', 'info' => ['title' => 'Relaticle'], 'paths' => []]);
});

it('documents the error envelope, scoped abilities, and rate limits on every operation', function (): void {
    $generatedView = resource_path('views/scribe/index.blade.php');
    $committedView = File::get($generatedView);

    try {
        $this->artisan('scribe:generate', ['--no-interaction' => true, '--force' => true, '--scribe-dir' => storage_path('framework/testing/scribe')])
            ->assertSuccessful();
    } finally {
        File::put($generatedView, $committedView);
        File::deleteDirectory(storage_path('framework/testing/scribe'));
    }

    $spec = $this->get('/openapi.json')->assertOk()->json();

    expect($spec['components']['schemas'])->toHaveKeys(['Error', 'ValidationError'])
        ->and($spec['components']['securitySchemes']['default']['description'])->toContain('`read` (GET)');

    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if ($method === 'parameters') {
                continue;
            }

            expect($operation['responses'])->toHaveKeys(['401', '403', '429'], "{$method} {$path}");
            expect($operation['responses']['429']['headers'])->toHaveKey('Retry-After');
            expect(isset($operation['responses']['404']))->toBe(str_contains($path, '{id}'), "{$method} {$path}");
            expect(isset($operation['responses']['422']))->toBe(in_array($method, ['post', 'put', 'patch'], true), "{$method} {$path}");
        }
    }
});
