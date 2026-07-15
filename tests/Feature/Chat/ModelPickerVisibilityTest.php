<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Services\ModelRegistry;

mutates(ModelRegistry::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

it('shows the Ollama model in the chat picker when configured', function (): void {
    config()->set('chat.models.6.model', 'qwen3:14b');
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertSee('qwen3:14b', stripInitialData: false);
});

it('hides the Ollama model from the chat picker when not configured', function (): void {
    config()->set('chat.models.6.model', null);
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertDontSee('qwen3:14b', stripInitialData: false);
});

it('hides cloud models whose provider key is not configured', function (): void {
    config()->set('ai.providers.openai.key', null);
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertSee('Sonnet 4.6', stripInitialData: false)
        ->assertDontSee('GPT 5.5', stripInitialData: false);
});

it('shows the Ollama model on the dashboard picker when configured', function (): void {
    config()->set('chat.models.6.model', 'qwen3:14b');
    app()->forgetInstance(ModelRegistry::class);

    livewire(Dashboard::class)
        ->assertSee('qwen3:14b', stripInitialData: false);
});

it('hides the Ollama model from the dashboard picker when not configured', function (): void {
    config()->set('chat.models.6.model', null);
    app()->forgetInstance(ModelRegistry::class);

    livewire(Dashboard::class)
        ->assertDontSee('qwen3:14b', stripInitialData: false);
});

it('drives the chat picker from the model registry', function (): void {
    config()->set('ai.providers.openai.key', null);
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertSee('Sonnet 4.6', stripInitialData: false)   // anthropic key set in tests
        ->assertSee('Auto', stripInitialData: false)
        ->assertDontSee('GPT 5.5', stripInitialData: false)   // openai key nulled → hidden
        ->assertDontSee('Gemini 3 Flash', stripInitialData: false); // supports_tools=false → never shown
});

it('shows env-configured self-hosted models in the picker', function (): void {
    config()->set('chat.self_hosted.url', 'http://vllm.local/v1');
    config()->set('chat.self_hosted.models', 'llama3.1:70b, qwen3:32b');
    config()->set('ai.providers.selfhosted.url', 'http://vllm.local/v1');
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertSee('llama3.1:70b', stripInitialData: false)
        ->assertSee('qwen3:32b', stripInitialData: false);
});
