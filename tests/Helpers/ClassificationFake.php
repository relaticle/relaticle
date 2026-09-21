<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Closure;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use RuntimeException;

final class ClassificationFake
{
    private static int $calls = 0;

    /**
     * @param  array<string, string>  $labelBySubject
     * @param  float|array<string, float>  $confidence
     */
    public static function choosing(array $labelBySubject, float|array $confidence = 0.97): void
    {
        self::enable();

        Classification::fake(function (ClassificationPrompt $prompt) use ($labelBySubject, $confidence): array {
            self::$calls++;

            $answers = [];

            foreach ($prompt->questions as $key => $question) {
                $subject = self::subject($prompt, (int) str($key)->afterLast('_')->toString());
                $label = $labelBySubject[$subject] ?? null;
                $choice = $question instanceof Choice && $label !== null
                    ? array_search($label, $question->options, true)
                    : false;

                $answers[$key] = new ChoiceAnswer(
                    $choice === false ? 'none' : (string) $choice,
                    [],
                    is_array($confidence) ? ($confidence[$subject] ?? 0.97) : $confidence,
                );
            }

            return $answers;
        });
    }

    public static function failing(): void
    {
        self::enable();

        Classification::fake(fn (): never => throw new RuntimeException('TypeSafe is unavailable.'));
    }

    public static function respondingWith(Closure $responses): void
    {
        self::enable();

        Classification::fake(function (ClassificationPrompt $prompt) use ($responses): mixed {
            self::$calls++;

            return $responses($prompt);
        });
    }

    public static function calls(): int
    {
        return self::$calls;
    }

    private static function enable(): void
    {
        self::$calls = 0;
        config()->set('ai.providers.typesafe.key', 'test-key');
    }

    private static function subject(ClassificationPrompt $prompt, int $index): string
    {
        $state = (array) $prompt->state;

        return (string) ($state['values'][$index] ?? $state['columns'][$index]['header']);
    }
}
