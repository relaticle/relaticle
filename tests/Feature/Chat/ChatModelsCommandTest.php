<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Relaticle\Chat\Commands\ChatModelsCommand;
use Relaticle\Chat\Services\ModelProbe;

mutates(ChatModelsCommand::class);

it('lists the model registry via artisan', function (): void {
    // Ollama comes from env now, so it only appears once it is configured.
    config()->set('chat.ollama.model', 'qwen3:8b');

    $this->artisan('chat:models')
        ->expectsOutputToContain('claude-sonnet-5')
        ->expectsOutputToContain('ollama')
        ->assertExitCode(0);
});

it('verifies a cloud model against its provider and reports what it measured', function (): void {
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg_1', 'content' => [], 'usage' => []])]);

    resolve(ModelProbe::class)->forget('anthropic', 'claude-sonnet-5');

    $this->artisan('chat:models', ['--probe' => 'claude-sonnet-5'])
        ->expectsOutputToContain('write_guard')
        ->assertExitCode(0);
});

it('fails with the provider message when the model is rejected', function (): void {
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'model: nope']], 404)]);

    resolve(ModelProbe::class)->forget('anthropic', 'claude-sonnet-5');

    $this->artisan('chat:models', ['--probe' => 'claude-sonnet-5'])
        ->expectsOutputToContain('model: nope')
        ->assertExitCode(1);
});
