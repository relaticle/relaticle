<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Support\Classifier;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

final readonly class OptionMatcher
{
    private const int VALUES_PER_REQUEST = 50;

    private const int CACHE_DAYS = 30;

    public function __construct(private Classifier $classifier) {}

    /**
     * @param  array<array-key, string>  $options
     * @param  list<string>  $values
     * @return array<array-key, OptionMatch>
     */
    public function match(string $fieldName, array $options, array $values, int $timeout): array
    {
        if (! $this->classifier->isEnabled() || $options === [] || $values === []) {
            return [];
        }

        $optionKeys = array_map(strval(...), array_keys($options));
        $labels = array_values($options);
        $optionSet = hash('xxh128', json_encode([
            $fieldName,
            $optionKeys,
            $labels,
            config('ai.providers.typesafe.models.classification.default'),
        ], JSON_THROW_ON_ERROR));

        $matches = [];
        $uncached = [];

        foreach (array_values(array_unique($values)) as $value) {
            $cached = Cache::get($this->cacheKey($optionSet, $value));

            if ($cached === null) {
                $uncached[] = $value;

                continue;
            }

            $match = $this->matchFromCached($cached, $optionKeys, $labels);

            if ($match instanceof OptionMatch) {
                $matches[$value] = $match;
            }
        }

        $criteria = [];

        foreach ($labels as $index => $label) {
            $criteria["o{$index}"] = $label;
        }

        $criteria[Classifier::NONE] = 'None of the options means the same thing';

        foreach (array_chunk($uncached, self::VALUES_PER_REQUEST) as $chunk) {
            $questions = [];

            foreach (array_keys($chunk) as $index) {
                $questions["value_{$index}"] = new Choice(
                    "Which option of the dropdown field `field` means the same thing as `values[{$index}]`?",
                    $criteria,
                );
            }

            $response = $this->classifier->classify(['field' => $fieldName, 'values' => $chunk], $questions, $timeout);

            if (! $response instanceof ClassificationResponse) {
                return $matches;
            }

            foreach ($chunk as $index => $value) {
                $resolved = $this->resolveAnswer($response->answers["value_{$index}"] ?? null, $optionKeys);

                if ($resolved === null) {
                    continue;
                }

                [$key, $confidence] = $resolved;

                Cache::put(
                    $this->cacheKey($optionSet, $value),
                    ['key' => $key, 'confidence' => $confidence],
                    now()->addDays(self::CACHE_DAYS),
                );

                if ($key === null || ! $this->classifier->meetsThreshold($confidence)) {
                    continue;
                }

                $optionIndex = array_search($key, $optionKeys, true);

                if ($optionIndex !== false) {
                    $matches[$value] = new OptionMatch($key, $labels[$optionIndex]);
                }
            }
        }

        return $matches;
    }

    /**
     * @param  list<string>  $optionKeys
     * @return array{0: string|null, 1: float|null}|null
     */
    private function resolveAnswer(?Answer $answer, array $optionKeys): ?array
    {
        if (! $answer instanceof ChoiceAnswer) {
            return null;
        }

        if ($answer->choice === Classifier::NONE) {
            return [null, $answer->confidence];
        }

        $optionIndex = $this->parseOptionIndex($answer->choice, count($optionKeys));

        return $optionIndex === null ? null : [$optionKeys[$optionIndex], $answer->confidence];
    }

    private function parseOptionIndex(string $choice, int $optionCount): ?int
    {
        if (! str_starts_with($choice, 'o') || ! ctype_digit(substr($choice, 1))) {
            return null;
        }

        $index = (int) substr($choice, 1);

        return $index < $optionCount ? $index : null;
    }

    /**
     * @param  list<string>  $optionKeys
     * @param  list<string>  $labels
     */
    private function matchFromCached(mixed $cached, array $optionKeys, array $labels): ?OptionMatch
    {
        if (! is_array($cached) || ! is_string($cached['key'] ?? null)) {
            return null;
        }

        $confidence = is_float($cached['confidence'] ?? null) ? $cached['confidence'] : null;

        if (! $this->classifier->meetsThreshold($confidence)) {
            return null;
        }

        $index = array_search($cached['key'], $optionKeys, true);

        return $index === false ? null : new OptionMatch($cached['key'], $labels[$index]);
    }

    private function cacheKey(string $optionSet, string $value): string
    {
        return 'option-match:'.$optionSet.':'.hash('xxh128', mb_strtolower(trim($value)));
    }
}
