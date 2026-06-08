<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Anthropic\AnthropicGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager as BaseAiManager;
use Laravel\Ai\Providers\AnthropicProvider;

final class AiManager extends BaseAiManager
{
    /**
     * Use our patched gateway so tool_use blocks with empty arguments
     * serialise as JSON objects rather than arrays.
     *
     * @param  array<string, mixed>  $config
     */
    public function createAnthropicDriver(array $config): AnthropicProvider
    {
        return new AnthropicProvider(
            new AnthropicGateway($this->app->make(Dispatcher::class)),
            $config,
            $this->app->make(Dispatcher::class),
        );
    }
}
