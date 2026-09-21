<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Classification;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Throwable;

final readonly class Classifier
{
    public const string NONE = 'none';

    public function isEnabled(): bool
    {
        return filled(config('ai.providers.typesafe.key'));
    }

    /**
     * @param  array<array-key, mixed>|string  $state
     * @param  array<string, Question>  $questions
     */
    public function classify(array|string $state, array $questions, int $timeout): ?ClassificationResponse
    {
        if (! $this->isEnabled() || $questions === []) {
            return null;
        }

        try {
            return Classification::of($state)
                ->questions($questions)
                ->timeout($timeout)
                ->classify(provider: 'typesafe');
        } catch (Throwable $exception) {
            Log::warning('TypeSafe classification failed; falling back to exact matching.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /** @phpstan-assert-if-true ChoiceAnswer $answer */
    public function isConfident(Answer $answer): bool
    {
        return $answer instanceof ChoiceAnswer
            && $answer->choice !== self::NONE
            && $this->meetsThreshold($answer->confidence);
    }

    public function meetsThreshold(?float $confidence): bool
    {
        return $confidence !== null && $confidence >= (float) config('relaticle.classification.min_confidence');
    }
}
