<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\Plan;
use App\Features\OnboardSeed;
use App\Filament\Pages\Dashboard;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Services\ModelAccess;
use Relaticle\Chat\Services\ModelRegistry;

mutates(ModelRegistry::class, ModelAccess::class);

/**
 * @return array{
 *     allowedModels: list<string>,
 *     trialLocked: bool
 * }
 */
function pickerState(string $html): array
{
    $html = html_entity_decode($html);

    preg_match("/allowedModels: JSON\.parse\('(.+?)'\)/", $html, $models);
    preg_match('/trialLocked: (true|false)/', $html, $locked);

    expect($models)->not->toBeEmpty()
        ->and($locked)->not->toBeEmpty();

    return [
        'allowedModels' => json_decode(preg_replace('/\\\\+u0022/', '"', $models[1]), true),
        'trialLocked' => $locked[1] === 'true',
    ];
}

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

it('shows the Ollama model in the chat picker when configured', function (): void {
    config()->set('chat.ollama.model', 'qwen3:14b');
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertSee('qwen3:14b', stripInitialData: false);
});

it('hides the Ollama model from the chat picker when not configured', function (): void {
    config()->set('chat.ollama.model', null);
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertDontSee('qwen3:14b', stripInitialData: false);
});

it('hides cloud models whose provider key is not configured', function (): void {
    config()->set('ai.providers.openai.key', null);
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertSee('Sonnet 5', stripInitialData: false)
        ->assertDontSee('GPT 5.5', stripInitialData: false);
});

it('shows the Ollama model on the dashboard picker when configured', function (): void {
    config()->set('chat.ollama.model', 'qwen3:14b');
    app()->forgetInstance(ModelRegistry::class);

    livewire(Dashboard::class)
        ->assertSee('qwen3:14b', stripInitialData: false);
});

it('hides the Ollama model from the dashboard picker when not configured', function (): void {
    config()->set('chat.ollama.model', null);
    app()->forgetInstance(ModelRegistry::class);

    livewire(Dashboard::class)
        ->assertDontSee('qwen3:14b', stripInitialData: false);
});

it('drives the chat picker from the model registry', function (): void {
    config()->set('ai.providers.openai.key', null);
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertSee('Sonnet 5', stripInitialData: false)   // anthropic key set in tests
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

it('locks premium models in the chat picker for a trial workspace without its own data', function (): void {
    $this->user->currentWorkspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(14)])->save();

    $state = pickerState(Livewire::test(ChatInterface::class)->html());

    expect($state['trialLocked'])->toBeTrue()
        ->and($state['allowedModels'])->toContain('claude-sonnet-5')
        ->and($state['allowedModels'])->not->toContain('claude-opus-5');
});

it('locks premium models in the dashboard picker for a trial workspace without its own data', function (): void {
    $this->user->currentWorkspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(14)])->save();

    $state = pickerState(livewire(Dashboard::class)->html());

    expect($state['trialLocked'])->toBeTrue()
        ->and($state['allowedModels'])->toContain('claude-sonnet-5')
        ->and($state['allowedModels'])->not->toContain('claude-opus-5');
});

it('keeps premium models locked without the trial flag on the free plan', function (): void {
    $state = pickerState(Livewire::test(ChatInterface::class)->html());

    expect($state['trialLocked'])->toBeFalse()
        ->and($state['allowedModels'])->not->toContain('claude-opus-5');
});

it('unlocks premium models for a trial workspace once it holds its own record', function (): void {
    $workspace = $this->user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(14)])->save();
    Company::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);

    $state = pickerState(Livewire::test(ChatInterface::class)->html());

    expect($state['trialLocked'])->toBeFalse()
        ->and($state['allowedModels'])->toContain('claude-opus-5');
});
