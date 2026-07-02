<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Enums\AiModel;
use Relaticle\Chat\Livewire\Chat\ChatInterface;

mutates(AiModel::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

it('shows the Ollama model in the chat picker when configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', 'qwen3:14b');

    Livewire::test(ChatInterface::class)
        ->assertSee('qwen3:14b', stripInitialData: false);
});

it('hides the Ollama model from the chat picker when not configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', null);

    Livewire::test(ChatInterface::class)
        ->assertDontSee('qwen3:14b', stripInitialData: false);
});

it('hides cloud models whose provider key is not configured', function (): void {
    config()->set('ai.providers.openai.key', null);

    Livewire::test(ChatInterface::class)
        ->assertSee('Sonnet 4.6', stripInitialData: false)
        ->assertDontSee('GPT 5.5', stripInitialData: false);
});

it('shows the Ollama model on the dashboard picker when configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', 'qwen3:14b');

    livewire(Dashboard::class)
        ->assertSee('qwen3:14b', stripInitialData: false);
});

it('hides the Ollama model from the dashboard picker when not configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', null);

    livewire(Dashboard::class)
        ->assertDontSee('qwen3:14b', stripInitialData: false);
});
