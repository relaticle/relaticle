<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Support\Classifier;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;
use Relaticle\ImportWizard\Data\ImportFieldCollection;

final readonly class ColumnMatcher
{
    private const int TIMEOUT_SECONDS = 3;

    private const int SAMPLE_LENGTH = 100;

    public function __construct(private Classifier $classifier) {}

    /**
     * @param  array<array-key, list<string>>  $samplesByHeader
     * @return array<array-key, string>
     */
    public function match(array $samplesByHeader, ImportFieldCollection $fields): array
    {
        $freeFields = $fields->values()->all();

        if ($samplesByHeader === [] || $freeFields === [] || ! $this->classifier->isEnabled()) {
            return [];
        }

        $headers = array_map(strval(...), array_keys($samplesByHeader));

        $fieldKeys = [];
        $criteria = [];

        foreach ($freeFields as $index => $field) {
            $fieldKeys[$index] = $field->key;
            $criteria["f{$index}"] = $field->label;
        }

        $criteria[Classifier::NONE] = 'None of the fields fits this column';

        $columns = array_map(fn (string $header): array => [
            'header' => $header,
            'samples' => array_values(array_map(
                fn (string $sample): string => str($sample)->limit(self::SAMPLE_LENGTH, '')->toString(),
                array_filter($samplesByHeader[$header], filled(...)),
            )),
        ], $headers);

        $questions = [];

        foreach (array_keys($headers) as $index) {
            $questions["column_{$index}"] = new Choice(
                "Which CRM field should receive the CSV column `columns[{$index}]`, judging by its header and sample values?",
                $criteria,
            );
        }

        $response = $this->classifier->classify(['columns' => $columns], $questions, self::TIMEOUT_SECONDS);

        if (! $response instanceof ClassificationResponse) {
            return [];
        }

        $best = [];

        foreach ($headers as $index => $header) {
            $resolved = $this->resolveAnswer($response->answers["column_{$index}"] ?? null, count($fieldKeys));

            if ($resolved === null) {
                continue;
            }

            [$fieldIndex, $confidence] = $resolved;
            $fieldKey = $fieldKeys[$fieldIndex];

            if (isset($best[$fieldKey]) && $best[$fieldKey]['confidence'] >= $confidence) {
                continue;
            }

            $best[$fieldKey] = ['header' => $header, 'confidence' => $confidence];
        }

        return collect($best)
            ->mapWithKeys(fn (array $pick, string $fieldKey): array => [$pick['header'] => $fieldKey])
            ->all();
    }

    /**
     * @return array{0: int, 1: float}|null
     */
    private function resolveAnswer(?Answer $answer, int $fieldCount): ?array
    {
        if (! $answer instanceof Answer || ! $this->classifier->isConfident($answer)) {
            return null;
        }

        $fieldIndex = $this->parseFieldIndex($answer->choice, $fieldCount);

        return $fieldIndex === null ? null : [$fieldIndex, (float) $answer->confidence];
    }

    private function parseFieldIndex(string $choice, int $fieldCount): ?int
    {
        if (! str_starts_with($choice, 'f') || ! ctype_digit(substr($choice, 1))) {
            return null;
        }

        $index = (int) substr($choice, 1);

        return $index < $fieldCount ? $index : null;
    }
}
