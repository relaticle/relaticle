<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Pest\Evals\Plugin;
use Tests\Evals\EvalTestCase;

it('keeps the shared stray request guard outside eval mode', function (): void {
    expect(Plugin::isEvalMode())->toBeFalse();

    Http::get('https://example.test/unfaked');
})->throws(RuntimeException::class, 'Attempted request');

it('prefers the process env key over the dotenv key', function (): void {
    $keys = EvalTestCase::providerKeys(
        ['ANTHROPIC_API_KEY' => 'env-anthropic', 'OPENAI_API_KEY' => 'env-openai'],
        ['ANTHROPIC_API_KEY' => 'dotenv-anthropic', 'OPENAI_API_KEY' => 'dotenv-openai'],
    );

    expect($keys)->toBe(['ANTHROPIC_API_KEY' => 'env-anthropic', 'OPENAI_API_KEY' => 'env-openai']);
});

it('treats the phpunit fake, an empty value and an unset variable as absent', function (): void {
    $keys = EvalTestCase::providerKeys(
        ['ANTHROPIC_API_KEY' => 'fake-anthropic-key', 'OPENAI_API_KEY' => false],
        ['ANTHROPIC_API_KEY' => 'dotenv-anthropic', 'OPENAI_API_KEY' => 'dotenv-openai'],
    );

    expect($keys)->toBe(['ANTHROPIC_API_KEY' => 'dotenv-anthropic', 'OPENAI_API_KEY' => 'dotenv-openai']);
});

it('names the missing key when neither source holds a real one', function (): void {
    EvalTestCase::providerKeys(
        ['ANTHROPIC_API_KEY' => 'real', 'OPENAI_API_KEY' => 'fake-openai-key'],
        ['OPENAI_API_KEY' => ''],
    );
})->throws(RuntimeException::class, 'Evals need OPENAI_API_KEY');
