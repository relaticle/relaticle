<?php

declare(strict_types=1);

namespace Tests\Evals;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Http;
use Pest\Evals\Plugin;
use RuntimeException;
use Tests\TestCase;

abstract class EvalTestCase extends TestCase
{
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
            return;
        }

        Http::allowStrayRequests();

        $values = Dotenv::createArrayBacked(base_path())->load();

        if (blank($values['ANTHROPIC_API_KEY'] ?? null) || blank($values['OPENAI_API_KEY'] ?? null)) {
            throw new RuntimeException('Evals need ANTHROPIC_API_KEY and OPENAI_API_KEY in .env');
        }

        config()->set('ai.providers.anthropic.key', $values['ANTHROPIC_API_KEY']);
        config()->set('ai.providers.openai.key', $values['OPENAI_API_KEY']);
    }
}
