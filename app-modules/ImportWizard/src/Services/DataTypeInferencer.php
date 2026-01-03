<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

/**
 * Analyzes sample cell values to infer field types.
 * Used as a FALLBACK when header matching fails.
 *
 * @phpstan-type InferenceResult array{type: string|null, confidence: float, suggestedFields: array<string>}
 */
final class DataTypeInferencer
{
    private const string EMAIL_PATTERN = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';

    private const string PHONE_PATTERN = '/^[\+]?\d?[-.\s]?\(?\d{1,4}\)?[-.\s]?\d{1,4}[-.\s]?\d{1,4}[-.\s]?\d{0,4}$/';

    private const string URL_PATTERN = '/^https?:\/\/[^\s]+$/i';

    private const string DOMAIN_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$/';

    // Matches: 2024-01-15, 2024-01-15T10:30:00, 2024-01-15 14:45, 01/15/2024, 1/15/24
    private const string DATE_PATTERN = '/^(\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?|\d{1,2}\/\d{1,2}\/\d{2,4})$/';

    private const float MIN_CONFIDENCE = 0.7;

    /** @var array<string, array{pattern: string, fields: array<string>}> */
    private const array TYPE_MAPPINGS = [
        'email' => [
            'pattern' => self::EMAIL_PATTERN,
            'fields' => ['email', 'work_email', 'personal_email', 'account_owner_email'],
        ],
        'url' => [
            'pattern' => self::URL_PATTERN,
            'fields' => ['website', 'linkedin', 'url', 'link'],
        ],
        // Date must be checked BEFORE phone because phone pattern is permissive and matches dates
        'date' => [
            'pattern' => self::DATE_PATTERN,
            'fields' => ['date', 'created_at', 'updated_at', 'birth_date', 'start_date', 'end_date', 'due_date', 'close_date'],
        ],
        'phone' => [
            'pattern' => self::PHONE_PATTERN,
            'fields' => ['phone', 'mobile', 'work_phone', 'home_phone'],
        ],
        'domain' => [
            'pattern' => self::DOMAIN_PATTERN,
            'fields' => ['domain', 'company_domain'],
        ],
    ];

    /**
     * Infer field type from sample values.
     *
     * @param  array<mixed>  $sampleValues
     * @return InferenceResult
     */
    public function inferType(array $sampleValues): array
    {
        $nonEmptyValues = array_filter(
            $sampleValues,
            fn (mixed $v): bool => trim((string) $v) !== ''
        );

        if (count($nonEmptyValues) < 3) {
            return ['type' => null, 'confidence' => 0.0, 'suggestedFields' => []];
        }

        foreach (self::TYPE_MAPPINGS as $type => $config) {
            /** @var int */
            $matches = 0;

            foreach ($nonEmptyValues as $value) {
                if (preg_match($config['pattern'], trim((string) $value)) === 1) {
                    $matches++;
                }
            }

            /** @var float */
            $confidence = $matches / count($nonEmptyValues);

            if ($confidence >= self::MIN_CONFIDENCE) {
                return [
                    'type' => $type,
                    'confidence' => $confidence,
                    'suggestedFields' => $config['fields'],
                ];
            }
        }

        return ['type' => null, 'confidence' => 0.0, 'suggestedFields' => []];
    }
}
