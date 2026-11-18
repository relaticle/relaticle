<?php

declare(strict_types=1);

namespace Tests\Evals\Support\ClaudeCode;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;

final class ClaudeCodeProvider extends Provider implements TextProvider
{
    use GeneratesText;
    use HasTextGateway;
    use StreamsText;

    public const string NAME = 'claude-code';

    public static function register(): void
    {
        $manager = resolve(AiManager::class);

        $manager->extend(self::NAME, fn (Application $app, array $config): ClaudeCodeProvider => new ClaudeCodeProvider(new ClaudeCodeGateway, $config, $app->make(Dispatcher::class)));
        $manager->forgetInstance(self::NAME);

        config()->set('ai.providers.'.self::NAME, ['driver' => self::NAME, 'key' => 'plan']);
    }

    public function defaultTextModel(): string
    {
        return 'claude-sonnet-5-5';
    }

    public function cheapestTextModel(): string
    {
        return 'claude-haiku-5-5';
    }

    public function smartestTextModel(): string
    {
        return 'claude-opus-5-5';
    }
}
