<?php

declare(strict_types=1);

namespace Tests\Evals;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Pest\Evals\Plugin;
use RuntimeException;
use Tests\Evals\Support\ClaudeCode\ClaudeCodeGateway;
use Tests\Evals\Support\EvalModels;
use Tests\Evals\Support\RelaTurn;
use Tests\TestCase;

abstract class EvalTestCase extends TestCase
{
    private const array ABSENT_KEYS = ['', 'fake-anthropic-key', 'fake-openai-key'];

    /**
     * @param  array<string, mixed>  $env
     * @param  array<string, mixed>  $dotenv
     * @return array{ANTHROPIC_API_KEY: string, OPENAI_API_KEY: string}
     */
    public static function providerKeys(array $env, array $dotenv): array
    {
        $keys = [];

        foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY'] as $name) {
            $keys[$name] = self::firstPresent($env[$name] ?? null, $dotenv[$name] ?? null)
                ?? throw new RuntimeException("Evals need {$name} in the process env or in .env");
        }

        return $keys;
    }

    protected function fakeExceptions(): void
    {
        if (Plugin::isEvalMode()) {
            return;
        }

        parent::fakeExceptions();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Plugin::isEvalMode()) {
            $this->markTestSkipped('Eval skipped. Run with [--evals].');
        }

        // The lazy migrate:fresh copies config('chat.models') into the settings table, so it runs before register() edits it.
        DB::select('select 1');

        Sleep::fake(false);

        if (EvalModels::runner() === 'plan') {
            config()->set('chat.title_generation.enabled', false);
            config()->set('chat.next_steps.enabled', false);
        } else {
            $this->useProviderKeys();
        }

        EvalModels::register(getenv('PEST_EVALS_EFFORT') ?: null);

        if (EvalModels::runner() === 'plan') {
            EvalModels::assertPlanServes(RelaTurn::model());
        }
    }

    protected function tearDown(): void
    {
        ClaudeCodeGateway::releaseAll();

        parent::tearDown();
    }

    private function useProviderKeys(): void
    {
        Http::allowStrayRequests();

        $keys = self::providerKeys(
            [
                'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY'),
                'OPENAI_API_KEY' => getenv('OPENAI_API_KEY'),
            ],
            Dotenv::createArrayBacked(base_path())->safeLoad(),
        );

        config()->set('ai.providers.anthropic.key', $keys['ANTHROPIC_API_KEY']);
        config()->set('ai.providers.openai.key', $keys['OPENAI_API_KEY']);
    }

    private static function firstPresent(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && ! in_array(trim($candidate), self::ABSENT_KEYS, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
