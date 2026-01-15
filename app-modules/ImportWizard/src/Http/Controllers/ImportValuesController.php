<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Relaticle\ImportWizard\Infrastructure\ImportCache;
use Relaticle\ImportWizard\Services\ValueValidationService;

/**
 * API controller for fetching import values.
 *
 * This bypasses Livewire's state sync to avoid PayloadTooLargeException
 * when dealing with large datasets (thousands of unique values).
 */
final class ImportValuesController extends Controller
{
    public function __construct(
        private readonly ImportCache $cache,
        private readonly ValueValidationService $validation,
    ) {}

    /**
     * Fetch paginated values for a column.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
            'csv_column' => ['required', 'string'],
            'field_name' => ['required', 'string'],
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:500'],
            'errors_only' => ['boolean'],
            'date_format' => ['nullable', 'string'],
        ]);

        $sessionId = $validated['session_id'];
        $csvColumn = $validated['csv_column'];
        $fieldName = $validated['field_name'];
        $page = $validated['page'] ?? 1;
        $perPage = $validated['per_page'] ?? 100;
        $errorsOnly = $validated['errors_only'] ?? false;
        $dateFormatOverride = $validated['date_format'] ?? null;

        // Fetch unique values from cache
        $allValues = $this->cache->getUniqueValues($sessionId, $csvColumn);

        if ($allValues === []) {
            return response()->json([
                'values' => [],
                'hasMore' => false,
                'total' => 0,
                'showing' => 0,
            ]);
        }

        // Fetch analysis data from cache (for issues)
        $analysisData = $this->cache->getAnalysis($sessionId, $csvColumn);

        /** @var array<int, array<string, mixed>> $issuesData */
        $issuesData = $analysisData['issues'] ?? [];
        $issuesByValue = collect($issuesData)->keyBy('value');

        // Fetch corrections from cache
        $corrections = $this->cache->getCorrections($sessionId, $fieldName);

        // Filter to errors only if requested
        if ($errorsOnly) {
            $errorValues = collect($issuesData)
                ->where('severity', 'error')
                ->pluck('value')
                ->all();
            $allValues = array_filter(
                $allValues,
                fn (int $count, string $value): bool => in_array($value, $errorValues, true),
                ARRAY_FILTER_USE_BOTH
            );
        }

        $total = count($allValues);
        $paginatedValues = array_slice($allValues, 0, $page * $perPage, preserve_keys: true);

        // Determine field type for validation
        $fieldType = $analysisData['fieldType'] ?? null;
        $isDateField = $this->validation->isDateField($fieldType);

        // Get effective format for validation and preview
        $formatValue = $dateFormatOverride ?? $this->validation->getEffectiveFormat($analysisData ?? []);

        // Build response with all data needed for rendering
        $values = [];
        foreach ($paginatedValues as $value => $count) {
            $stringValue = (string) $value;
            $originalIssue = $issuesByValue->get($stringValue);
            $isSkipped = isset($corrections[$stringValue]) && $corrections[$stringValue] === '';
            $correctedValue = $corrections[$stringValue] ?? null;

            // Determine which value to validate and show issue for
            $issue = $originalIssue;
            if ($correctedValue !== null && $correctedValue !== '' && ! $isSkipped) {
                // Validate the corrected value instead of using original issue
                $issue = $this->validation->validateValue($correctedValue, $fieldType, $formatValue);
            } elseif ($isSkipped) {
                // Skipped values have no issue
                $issue = null;
            }

            // Calculate parsed date preview
            $parsedDate = null;
            if ($isDateField && $formatValue !== null) {
                $valueToPreview = $correctedValue ?? $stringValue;
                if ($valueToPreview !== '' && $issue === null) {
                    $parsedDate = $this->validation->parseDatePreviewHuman($valueToPreview, $fieldType, $formatValue);
                }
            }

            $values[] = [
                'value' => $stringValue,
                'count' => $count,
                'issue' => $issue,
                'isSkipped' => $isSkipped,
                'correctedValue' => $correctedValue,
                'parsedDate' => $parsedDate,
            ];
        }

        return response()->json([
            'values' => $values,
            'hasMore' => count($paginatedValues) < $total,
            'total' => $total,
            'showing' => count($paginatedValues),
        ]);
    }
}
